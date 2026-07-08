<?php

namespace Tests\Feature\Ai;

use App\Ai\Images\Exceptions\ImageGenerationRejectedException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Image;
use Tests\TestCase;

class ReplicateImageGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.replicate.key' => 'test-key']);

        Sleep::fake();
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
            'replicate.delivery/*' => Http::response('raw-png-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $response = Image::of('Clean up this countertop photo')
            ->attachments([ImageFile::fromBase64(base64_encode('source-image'), 'image/jpeg')])
            ->landscape()
            ->generate('replicate', 'black-forest-labs/flux-kontext-pro');

        $this->assertSame('raw-png-bytes', $response->firstImage()->content());
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
            'replicate.delivery/*' => Http::response('png', 200, ['Content-Type' => 'image/png']),
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
}
