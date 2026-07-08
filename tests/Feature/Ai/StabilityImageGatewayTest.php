<?php

namespace Tests\Feature\Ai;

use App\Ai\Images\Exceptions\ImageGenerationRejectedException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Image;
use Tests\TestCase;

class StabilityImageGatewayTest extends TestCase
{
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
            'api.stability.ai/v2beta/stable-image/generate/core' => Http::response('img-bytes', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $response = Image::of('A clean showroom countertop')
            ->landscape()
            ->generate('stability', 'core');

        $this->assertSame('img-bytes', $response->firstImage()->content());
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
            'api.stability.ai/v2beta/stable-image/generate/sd3' => Http::response('img-bytes', 200, [
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
            'api.stability.ai/v2beta/stable-image/generate/core' => Http::response('img-bytes', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        Image::of('A clean countertop')
            ->attachments([ImageFile::fromBase64(base64_encode('source'), 'image/jpeg')])
            ->generate('stability', 'core');

        Http::assertSent(fn (Request $request) => $this->part($request, 'image') === null);
    }

    public function test_moderation_failures_map_to_a_rejection_exception(): void
    {
        Http::fake([
            'api.stability.ai/*' => Http::response(['errors' => ['flagged by moderation']], 403),
        ]);

        $this->expectException(ImageGenerationRejectedException::class);

        Image::of('Something disallowed')->generate('stability', 'core');
    }
}
