<?php

namespace App\Images;

use App\Models\Photo;
use App\Services\PhotoStorageCleanupService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PhotoDerivativeService
{
    public function __construct(private PhotoStorageCleanupService $cleanupService) {}

    public function process(Photo $photo): PhotoDerivativeResult
    {
        $config = config('photostudio.processing');
        $variants = $config['variants'][$photo->kind->value] ?? [];

        if ($variants === []) {
            throw new RuntimeException('Photo derivative generation failed.');
        }

        $disk = Storage::disk($photo->disk);
        $attemptId = (string) Str::uuid7();
        $workDirectory = storage_path('app/tmp/photo-derivatives/'.$attemptId);
        $storedPaths = [];

        File::ensureDirectoryExists($workDirectory);

        try {
            $sourcePath = $workDirectory.'/source';
            $stream = $disk->readStream($photo->path);

            if ($stream === null) {
                throw new RuntimeException('Photo source could not be read.');
            }

            file_put_contents($sourcePath, $stream);

            $outputDirectory = $workDirectory.'/out';
            $payload = $this->runScript($sourcePath, $outputDirectory, [
                'max_source_dimension' => $config['max_source_dimension'],
                'variants' => $variants,
            ]);

            $remoteDirectory = $this->remoteDirectoryFor($photo).'/derivatives/'.$attemptId;
            $derivatives = [];

            foreach ($payload['variants'] as $name => $info) {
                $localFile = $outputDirectory.'/'.$info['file'];

                if (! is_file($localFile)) {
                    throw new RuntimeException('Photo derivative output was incomplete.');
                }

                $remotePath = $remoteDirectory.'/'.$info['file'];
                $handle = fopen($localFile, 'r');

                if ($handle === false || $disk->put($remotePath, $handle) === false) {
                    throw new RuntimeException('Photo derivative storage failed.');
                }

                $storedPaths[] = $remotePath;
                $derivatives[$name] = [
                    'path' => $remotePath,
                    'width' => isset($info['width']) ? (int) $info['width'] : null,
                    'height' => isset($info['height']) ? (int) $info['height'] : null,
                    'size_bytes' => isset($info['size_bytes']) ? (int) $info['size_bytes'] : null,
                ];
            }

            return new PhotoDerivativeResult(
                derivatives: $derivatives,
                storedPaths: $storedPaths,
                width: isset($payload['source']['width']) ? (int) $payload['source']['width'] : null,
                height: isset($payload['source']['height']) ? (int) $payload['source']['height'] : null,
                sizeBytes: isset($payload['source']['size_bytes']) ? (int) $payload['source']['size_bytes'] : null,
            );
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                $this->cleanupService->deleteOrTrack($photo->disk, $storedPaths);
            }

            Log::warning('Photo derivative generation failed.', [
                'photo_id' => $photo->id,
                'exception_class' => $exception::class,
            ]);

            throw new RuntimeException('Photo derivative generation failed.');
        } finally {
            File::deleteDirectory($workDirectory);
        }
    }

    /**
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
            throw new RuntimeException('Photo image worker failed.');
        }

        $payload = json_decode($result->output(), true);

        if (! is_array($payload) || ! isset($payload['variants']) || ! is_array($payload['variants'])) {
            throw new RuntimeException('Photo image worker returned an invalid response.');
        }

        return $payload;
    }

    protected function remoteDirectoryFor(Photo $photo): string
    {
        $directory = dirname($photo->path);

        return $directory === '.'
            ? pathinfo($photo->path, PATHINFO_FILENAME)
            : $directory;
    }
}
