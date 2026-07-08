<?php

namespace App\Images;

use App\Models\Photo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PhotoDerivativeService
{
    /**
     * Normalize the photo's source file and render the web derivatives for
     * its kind, storing them beside the source and recording their paths
     * and dimensions on the model.
     *
     * @throws RuntimeException when processing or storage fails.
     */
    public function process(Photo $photo): void
    {
        $config = config('photostudio.processing');
        $variants = $config['variants'][$photo->kind->value] ?? [];

        if ($variants === []) {
            throw new RuntimeException("No derivative variants are configured for [{$photo->kind->value}] photos.");
        }

        $disk = Storage::disk($photo->disk);
        $workDirectory = storage_path('app/tmp/photo-derivatives/'.Str::uuid7());

        File::ensureDirectoryExists($workDirectory);

        try {
            $sourcePath = $workDirectory.'/source';
            $stream = $disk->readStream($photo->path);

            if ($stream === null) {
                throw new RuntimeException("The photo source file [{$photo->path}] could not be read.");
            }

            file_put_contents($sourcePath, $stream);

            $outputDirectory = $workDirectory.'/out';

            $payload = $this->runScript($sourcePath, $outputDirectory, [
                'max_source_dimension' => $config['max_source_dimension'],
                'variants' => $variants,
            ]);

            $remoteDirectory = $this->remoteDirectoryFor($photo);
            $derivatives = [];

            foreach ($payload['variants'] as $name => $info) {
                $localFile = $outputDirectory.'/'.$info['file'];

                if (! is_file($localFile)) {
                    throw new RuntimeException("The derivative [{$name}] was not produced by the image worker.");
                }

                $remotePath = $remoteDirectory.'/'.$info['file'];
                $handle = fopen($localFile, 'r');

                if ($handle === false || $disk->put($remotePath, $handle) === false) {
                    throw new RuntimeException("The derivative [{$name}] could not be stored on the [{$photo->disk}] disk.");
                }

                $derivatives[$name] = [
                    'path' => $remotePath,
                    'width' => $info['width'] ?? null,
                    'height' => $info['height'] ?? null,
                    'size_bytes' => $info['size_bytes'] ?? null,
                ];
            }

            $photo->update([
                'derivatives' => $derivatives,
                'width' => $payload['source']['width'] ?? null,
                'height' => $payload['source']['height'] ?? null,
                'size_bytes' => $payload['source']['size_bytes'] ?? null,
            ]);
        } finally {
            File::deleteDirectory($workDirectory);
        }
    }

    /**
     * Run the Node/Sharp worker and decode its JSON result.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function runScript(string $inputPath, string $outputDirectory, array $config): array
    {
        $processing = config('photostudio.processing');

        $result = Process::timeout($processing['timeout'] ?? 120)->run([
            (string) ($processing['node_binary'] ?? 'node'),
            base_path((string) $processing['script']),
            $inputPath,
            $outputDirectory,
            (string) json_encode($config),
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                'Sharp image derivative generation failed: '.Str::limit($result->errorOutput() ?: $result->output(), 500)
            );
        }

        $payload = json_decode($result->output(), true);

        if (! is_array($payload) || ! isset($payload['variants']) || ! is_array($payload['variants'])) {
            throw new RuntimeException('Sharp image derivative generation returned invalid JSON.');
        }

        return $payload;
    }

    /**
     * Derivatives live beside the source file. Legacy flat paths (no
     * directory) get a directory named after the source filename so the
     * bucket prefix convention is preserved.
     */
    protected function remoteDirectoryFor(Photo $photo): string
    {
        $directory = dirname($photo->path);

        return $directory === '.'
            ? pathinfo($photo->path, PATHINFO_FILENAME)
            : $directory;
    }
}
