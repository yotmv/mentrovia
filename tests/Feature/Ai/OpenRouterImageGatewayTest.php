<?php

namespace Tests\Feature\Ai;

use App\Ai\Responses\CostAwareImageResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Image;
use RuntimeException;
use Tests\TestCase;

class OpenRouterImageGatewayTest extends TestCase
{
    protected function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII=', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openrouter.key' => 'test-key']);
    }

    protected function fakeImageCompletion(?float $cost): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'model' => 'black-forest-labs/flux.2-pro',
                'choices' => [[
                    'message' => [
                        'images' => [[
                            'image_url' => ['url' => 'data:image/png;base64,'.base64_encode($this->pngBytes())],
                        ]],
                    ],
                ]],
                'usage' => array_filter([
                    'prompt_tokens' => 44444,
                    'completion_tokens' => 3072,
                    'cost' => $cost,
                ], fn ($value) => $value !== null),
            ]),
        ]);
    }

    public function test_it_requests_usage_accounting_and_captures_the_actual_billed_cost(): void
    {
        $this->fakeImageCompletion(0.15);

        $response = Image::of('Recreate this countertop')
            ->attachments([ImageFile::fromBase64(base64_encode('ref'), 'image/jpeg')])
            ->generate('openrouter', 'black-forest-labs/flux.2-pro');

        $this->assertInstanceOf(CostAwareImageResponse::class, $response);
        $this->assertEqualsWithDelta(0.15, $response->costUsd, 0.0001);
        $this->assertSame($this->pngBytes(), $response->firstImage()->content());

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'chat/completions')
                && ($request['usage']['include'] ?? false) === true;
        });
    }

    public function test_a_missing_cost_field_yields_a_null_cost(): void
    {
        $this->fakeImageCompletion(null);

        $response = Image::of('Recreate this countertop')
            ->generate('openrouter', 'black-forest-labs/flux.2-pro');

        $this->assertInstanceOf(CostAwareImageResponse::class, $response);
        $this->assertNull($response->costUsd);
    }

    public function test_spoofed_image_data_is_rejected(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'images' => [[
                            'image_url' => ['url' => 'data:image/png;base64,'.base64_encode('<script>alert(1)</script>')],
                        ]],
                    ],
                ]],
                'usage' => [],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not contain the declared image type');

        Image::of('Anything')->generate('openrouter', 'black-forest-labs/flux.2-pro');
    }

    public function test_image_dimensions_are_bounded_independently_of_compressed_size(): void
    {
        config(['photostudio.http.max_output_dimension' => 8000]);

        $oversizedHeader = substr_replace($this->pngBytes(), pack('N', 8001), 16, 4);

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'images' => [[
                            'image_url' => ['url' => 'data:image/png;base64,'.base64_encode($oversizedHeader)],
                        ]],
                    ],
                ]],
                'usage' => [],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dimensions exceeded the configured safety limit');

        Image::of('Anything')->generate('openrouter', 'black-forest-labs/flux.2-pro');
    }
}
