<?php

namespace App\Ai\Providers;

use App\Ai\Gateway\ReplicateImageGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Providers\Concerns\GeneratesImages;
use Laravel\Ai\Providers\Concerns\HasImageGateway;
use Laravel\Ai\Providers\Provider;

class ReplicateProvider extends Provider implements ImageProvider
{
    use GeneratesImages;
    use HasImageGateway;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config, protected Dispatcher $events)
    {
        //
    }

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new ReplicateImageGateway;
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'google/nano-banana';
    }

    /**
     * Get the default / normalized image options for the provider.
     *
     * @return array<string, mixed>
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        return array_filter([
            'aspect_ratio' => $size,
        ]);
    }
}
