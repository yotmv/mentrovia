<?php

namespace App\Support\Ai;

use App\Ai\Text\Exceptions\TextGenerationRoleException;
use App\Exceptions\PaidAiUnavailable;
use App\Services\Advertising\AdvertisingKitGenerationException;
use App\Services\Branding\BrandKitGenerationException;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class AiFailurePresentation
{
    public function __construct(
        public string $message,
        public bool $showsSettingsAction = false,
    ) {}

    public static function fromException(Throwable $exception): self
    {
        if ($exception instanceof PaidAiUnavailable) {
            if ($exception->requiresSettings()) {
                return new self(
                    __('AI cannot run with the current account settings. Review Paid AI, provider, API key, and model settings, then retry.'),
                    true,
                );
            }

            if ($exception->isBudgetExceeded()) {
                return new self(
                    __('This request is blocked by the account AI spending limit. Review the limit or choose a supported model, then retry.'),
                    true,
                );
            }

            if ($exception->isConcurrencyExceeded()) {
                return new self(__('Another AI operation is already running for this account. Wait for it to finish, then retry.'));
            }

            return new self(__('AI could not complete the required secure audit. Retry. If the problem continues, contact support.'));
        }

        if ($exception instanceof TextGenerationRoleException) {
            if ($exception->isConfigurationFailure()) {
                return new self(
                    __('AI cannot run with the current model configuration. Review the account AI settings, then retry.'),
                    true,
                );
            }

            return new self(__('The AI provider could not complete this request right now. Retry in a moment. Nothing new was saved.'));
        }

        if ($exception instanceof BrandKitGenerationException || $exception instanceof AdvertisingKitGenerationException) {
            return new self(__('The AI provider did not return usable results. Retry in a moment. Nothing new was saved.'));
        }

        Log::warning('Customer-facing AI action failed.', [
            'exception_class' => $exception::class,
        ]);

        return new self(__('AI could not start this request. Retry. If the problem continues, contact support.'));
    }
}
