<?php

namespace App\Http\Requests\Settings;

use App\Enums\AiAuditEvent;
use App\Enums\AiModelPurpose;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportAiTrustAuditRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->account_erasure_started_at === null
            && $user->currentAccount !== null
            && $user->can('manageAi', $user->currentAccount);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event' => ['nullable', Rule::enum(AiAuditEvent::class)],
            'outcome' => ['nullable', Rule::in(['started', 'succeeded', 'failed', 'prevented', 'recorded'])],
            'actor' => ['nullable', 'integer', 'min:1'],
            'purpose' => ['nullable', Rule::enum(AiModelPurpose::class)],
            'provider' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'model' => ['nullable', 'string', 'max:80'],
            'operation_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }
}
