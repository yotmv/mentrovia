<?php

namespace Tests\Feature\Ai;

use App\Ai\Images\Exceptions\ImageGenerationRejectedException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Image;
use RuntimeException;
use Tests\TestCase;

class StabilityImageGatewayTest extends TestCase
{
    protected function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII=', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.stability.key' => 'test-key']);
    }

    /**
     * Find a multipart part by name in a faked request.
     *
     * @return array<string, mixed>|null
     */
    protected function part(Request $request, string $name): ?array
    {
        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === $name) {
                return $part;
            }
        }

        return null;
    }

    public function test_text_to_image_sends_prompt_and_aspect_ratio(): void
    {
        Http::fake([
            'api.stability.ai/v2beta/stable-image/generate/core' => Http::response($this->pngBytes(), 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $response = Image::of('A clean showroom countertop')
            ->landscape()
            ->generate('stability', 'core');

        $this->assertSame($this->pngBytes(), $response->firstImage()->content());
        $this->assertSame('image/png', $response->firstImage()->mime());

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'stable-image/generate/core')
                && ($this->part($request, 'prompt')['contents'] ?? null) === 'A clean showroom countertop'
                && ($this->part($request, 'aspect_ratio')['contents'] ?? null) === '3:2'
                && $this->part($request, 'image') === null;
        });
    }

    public function test_sd3_with_reference_image_switches_to_image_to_image_mode(): void
    {
        Http::fake([
            'api.stability.ai/v2beta/stable-image/generate/sd3' => Http::response($this->pngBytes(), 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        Image::of('Restore this photo')
            ->attachments([ImageFile::fromBase64(base64_encode('source'), 'image/jpeg')])
            ->landscape()
            ->generate('stability', 'sd3');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'stable-image/generate/sd3')
                && ($this->part($request, 'mode')['contents'] ?? null) === 'image-to-image'
                && ($this->part($request, 'strength')['contents'] ?? null) === '0.6'
                && $this->part($request, 'image') !== null
                && $this->part($request, 'aspect_ratio') === null;
        });
    }

    public function test_core_ignores_reference_images_it_cannot_accept(): void
    {
        Http::fake([
            'api.stability.ai/v2beta/stable-image/generate/core' => Http::response($this->pngBytes(), 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        Image::of('A clean countertop')
            ->attachments([ImageFile::fromBase64(base64_encode('source'), 'image/jpeg')])
            ->generate('stability', 'core');

        Http::assertSent(fn (Request $request) => $this->part($request, 'image') === null);
    }

    public function test_api_credentials_are_never_sent_to_an_insecure_base_url(): void
    {
        config(['ai.providers.stability.url' => 'http://api.stability.example/v2beta']);
        Http::fake();

        try {
            Image::of('Anything')->generate('stability', 'core');
            $this->fail('Expected an insecure API base URL to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Stability API requests require a trusted HTTPS base URL.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_moderation_failures_map_to_a_rejection_exception(): void
    {
        Http::fake([
            'api.stability.ai/*' => Http::response(['errors' => ['flagged by moderation']], 403),
        ]);

        $this->expectException(ImageGenerationRejectedException::class);

        Image::of('Something disallowed')->generate('stability', 'core');
    }

    public function test_rejection_logs_and_exceptions_do_not_retain_prompt_or_provider_content(): void
    {
        Log::spy();

        Http::fake([
            'api.stability.ai/*' => Http::response(['errors' => ['secret moderation detail']], 403),
        ]);

        try {
            Image::of('private customer prompt')->generate('stability', 'core');
            $this->fail('Expected the provider rejection to throw.');
        } catch (ImageGenerationRejectedException $exception) {
            $this->assertStringNotContainsString('private customer prompt', $exception->getMessage());
            $this->assertStringNotContainsString('secret moderation detail', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('secret moderation detail', (string) $exception);
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'Stability rejected an image generation request.',
                \Mockery::on(fn (array $context): bool => $context === [
                    'status' => 403,
                    'prompt_sha256' => hash('sha256', 'private customer prompt'),
                    'prompt_bytes' => strlen('private customer prompt'),
                ]),
            );
    }

    public function test_spoofed_image_response_content_is_rejected(): void
    {
        Http::fake([
            'api.stability.ai/*' => Http::response('<script>alert(1)</script>', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not contain the declared image type');

        Image::of('Anything')->generate('stability', 'core');
    }
}
