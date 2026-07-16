<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreOpenRouterCredentialRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(CurrentAccount $currentAccount): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        try {
            $account = $currentAccount->resolve($user);
        } catch (AuthorizationException) {
            return false;
        }

        return $user->can('manageAi', $account);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'openrouter_api_key' => ['required', 'string', 'min:20', 'max:255'],
        ];
    }
}
