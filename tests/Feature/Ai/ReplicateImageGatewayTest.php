<?php

namespace Tests\Feature\Ai;

use App\Ai\Images\Exceptions\ImageGenerationRejectedException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Image;
use RuntimeException;
use Tests\TestCase;

class ReplicateImageGatewayTest extends TestCase
{
    protected function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII=', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.replicate.key' => 'test-key']);

        Sleep::fake();
        Http::preventStrayRequests();
    }

    public function test_it_shapes_the_prediction_request_per_model_profile_and_polls_to_completion(): void
    {
        Http::fake([
            'api.replicate.com/v1/models/black-forest-labs/flux-kontext-pro/predictions' => Http::response([
                'status' => 'processing',
                'urls' => ['get' => 'https://api.replicate.com/v1/predictions/p1'],
            ]),
            'api.replicate.com/v1/predictions/p1' => Http::response([
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/output.png',
            ]),
            'replicate.delivery/*' => Http::response($this->pngBytes(), 200, ['Content-Type' => 'image/png']),
        ]);

        $response = Image::of('Clean up this countertop photo')
            ->attachments([ImageFile::fromBase64(base64_encode('source-image'), 'image/jpeg')])
            ->landscape()
            ->generate('replicate', 'black-forest-labs/flux-kontext-pro');

        $this->assertSame($this->pngBytes(), $response->firstImage()->content());
        $this->assertSame('image/png', $response->firstImage()->mime());

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'models/black-forest-labs/flux-kontext-pro/predictions')) {
                return false;
            }

            $input = $request['input'];

            return $request->hasHeader('Prefer', 'wait=60')
                && $input['prompt'] === 'Clean up this countertop photo'
                && str_starts_with($input['input_image'], 'data:image/jpeg;base64,')
                && $input['aspect_ratio'] === '3:2'
                && $input['output_format'] === 'png';
        });
    }

    public function test_array_style_image_input_fields_receive_a_list(): void
    {
        Http::fake([
            'api.replicate.com/v1/models/google/nano-banana/predictions' => Http::response([
                'status' => 'succeeded',
                'output' => ['https://replicate.delivery/output.png'],
            ]),
            'replicate.delivery/*' => Http::response($this->pngBytes(), 200, ['Content-Type' => 'image/png']),
        ]);

        Image::of('Clean this up')
            ->attachments([
                ImageFile::fromBase64(base64_encode('one'), 'image/png'),
                ImageFile::fromBase64(base64_encode('two'), 'image/png'),
            ])
            ->generate('replicate', 'google/nano-banana');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'google/nano-banana')
                && is_array($request['input']['image_input'])
                && count($request['input']['image_input']) === 2;
        });
    }

    public function test_versioned_model_slugs_are_rejected(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        Image::of('Anything')->generate('replicate', 'someone/model:abc123def');
    }

    public function test_api_credentials_are_never_sent_to_an_insecure_base_url(): void
    {
        config(['ai.providers.replicate.url' => 'http://api.replicate.example/v1']);
        Http::fake();

        try {
            Image::of('Anything')->generate('replicate', 'google/nano-banana');
            $this->fail('Expected an insecure API base URL to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Replicate API requests require a trusted HTTPS base URL.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_insufficient_credits_maps_to_a_typed_exception(): void
    {
        Http::fake([
            'api.replicate.com/*' => Http::response(['detail' => 'Insufficient credit'], 402),
        ]);

        $this->expectException(InsufficientCreditsException::class);

        Image::of('Anything')->generate('replicate', 'google/nano-banana');
    }

    public function test_validation_failures_map_to_a_rejection_exception(): void
    {
        Http::fake([
            'api.replicate.com/*' => Http::response(['detail' => 'input is invalid'], 422),
        ]);

        $this->expectException(ImageGenerationRejectedException::class);

        Image::of('Anything')->generate('replicate', 'google/nano-banana');
    }

    public function test_prediction_polling_rejects_cross_origin_urls_before_sending_the_api_token(): void
    {
        Http::fake([
            'api.replicate.com/*' => Http::response([
                'status' => 'processing',
                'urls' => ['get' => 'https://attacker.example/predictions/p1'],
            ]),
        ]);

        try {
            Image::of('Anything')->generate('replicate', 'google/nano-banana');
            $this->fail('Expected an untrusted prediction URL to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Replicate returned an untrusted prediction status URL.', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertNotSent(function (Request $request): bool {
            return str_contains($request->url(), 'attacker.example');
        });
    }

    public function test_output_downloads_require_a_trusted_https_host_and_image_content_type(): void
    {
        Http::fake([
            'api.replicate.com/*' => Http::response([
                'status' => 'succeeded',
                'output' => 'https://attacker.example/output.png',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Replicate returned an untrusted output image URL.');

        Image::of('Anything')->generate('replicate', 'google/nano-banana');
    }

    public function test_output_downloads_reject_non_image_content(): void
    {
        Http::fake([
            'api.replicate.com/*' => Http::response([
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/output.png',
            ]),
            'replicate.delivery/*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported generated image type');

        Image::of('Anything')->generate('replicate', 'google/nano-banana');
    }

    public function test_output_downloads_reject_content_that_spoofs_an_image_mime_type(): void
    {
        Http::fake([
            'api.replicate.com/*' => Http::response([
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/output.png',
            ]),
            'replicate.delivery/*' => Http::response('<script>alert(1)</script>', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not contain the declared image type');

        Image::of('Anything')->generate('replicate', 'google/nano-banana');
    }

    public function test_output_downloads_enforce_the_configured_size_limit(): void
    {
        config(['photostudio.http.max_output_bytes' => 3]);

        Http::fake([
            'api.replicate.com/*' => Http::response([
                'status' => 'succeeded',
                'output' => 'https://replicate.delivery/output.png',
            ]),
            'replicate.delivery/*' => Http::response('four', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeded the configured download limit');

        Image::of('Anything')->generate('replicate', 'google/nano-banana');
    }

    public function test_rejection_logs_and_exceptions_do_not_retain_prompt_or_provider_content(): void
    {
        Log::spy();

        Http::fake([
            'api.replicate.com/*' => Http::response(['detail' => 'secret provider detail'], 422),
        ]);

        try {
            Image::of('private customer prompt')->generate('replicate', 'google/nano-banana');
            $this->fail('Expected the provider rejection to throw.');
        } catch (ImageGenerationRejectedException $exception) {
            $this->assertStringNotContainsString('private customer prompt', $exception->getMessage());
            $this->assertStringNotContainsString('secret provider detail', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('secret provider detail', (string) $exception);
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'Replicate rejected an image generation request.',
                \Mockery::on(fn (array $context): bool => $context === [
                    'status' => 422,
                    'prompt_sha256' => hash('sha256', 'private customer prompt'),
                    'prompt_bytes' => strlen('private customer prompt'),
                ]),
            );
    }
}
