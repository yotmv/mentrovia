<?php

namespace App\Ai\Images;

use Illuminate\Http\Client\Response;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class GeneratedImageResponseValidator
{
    public static function requireHttpsApiUrl(string $url, string $provider): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || blank($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new RuntimeException("{$provider} API requests require a trusted HTTPS base URL.");
        }

        return rtrim($url, '/');
    }

    /**
     * Bound remote image downloads before their response body is retained.
     *
     * @return array{on_headers: callable, progress: callable}
     */
    public static function httpOptions(): array
    {
        $maximumBytes = self::maximumBytes();

        return [
            'on_headers' => function (ResponseInterface $response) use ($maximumBytes): void {
                $contentLength = (int) $response->getHeaderLine('Content-Length');

                if ($contentLength > $maximumBytes) {
                    throw new RuntimeException('The generated image exceeded the configured download limit.');
                }
            },
            'progress' => function (int $downloadTotal, int $downloadedBytes) use ($maximumBytes): void {
                if ($downloadTotal > $maximumBytes || $downloadedBytes > $maximumBytes) {
                    throw new RuntimeException('The generated image exceeded the configured download limit.');
                }
            },
        ];
    }

    public static function fromResponse(Response $response): GeneratedImage
    {
        if (! $response->successful()) {
            throw new RuntimeException('The generated image could not be downloaded from the provider.');
        }

        $contentType = strtolower(trim(explode(';', $response->header('Content-Type') ?: '')[0]));

        return self::fromBytes($response->body(), $contentType);
    }

    public static function fromBase64(string $encodedImage, string $contentType): GeneratedImage
    {
        $maximumEncodedBytes = (int) ceil(self::maximumBytes() / 3) * 4 + 4;

        if (strlen($encodedImage) > $maximumEncodedBytes) {
            throw new RuntimeException('The generated image exceeded the configured download limit.');
        }

        $bytes = base64_decode($encodedImage, true);

        if ($bytes === false) {
            throw new RuntimeException('The provider returned invalid generated image encoding.');
        }

        return self::fromBytes($bytes, strtolower($contentType));
    }

    public static function fromBytes(string $bytes, string $contentType): GeneratedImage
    {
        if (! in_array($contentType, self::allowedContentTypes(), true)) {
            throw new RuntimeException('The provider returned an unsupported generated image type.');
        }

        if (strlen($bytes) > self::maximumBytes()) {
            throw new RuntimeException('The generated image exceeded the configured download limit.');
        }

        $imageInformation = @getimagesizefromstring($bytes);

        if ($imageInformation === false || $imageInformation['mime'] !== $contentType) {
            throw new RuntimeException('The provider response did not contain the declared image type.');
        }

        $width = $imageInformation[0];
        $height = $imageInformation[1];
        $maximumDimension = (int) config('photostudio.http.max_output_dimension', 8192);
        $maximumPixels = (int) config('photostudio.http.max_output_pixels', 40_000_000);

        if ($width < 1
            || $height < 1
            || $width > $maximumDimension
            || $height > $maximumDimension
            || ($width * $height) > $maximumPixels) {
            throw new RuntimeException('The generated image dimensions exceeded the configured safety limit.');
        }

        return new GeneratedImage(base64_encode($bytes), $contentType);
    }

    private static function maximumBytes(): int
    {
        return (int) config('photostudio.http.max_output_bytes', 26_214_400);
    }

    /**
     * @return array<int, string>
     */
    private static function allowedContentTypes(): array
    {
        return config('photostudio.http.allowed_output_mime_types', []);
    }
}
