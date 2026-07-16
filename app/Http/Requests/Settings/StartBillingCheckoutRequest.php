<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartBillingCheckoutRequest extends FormRequest
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

        return $user->can('manageBilling', $account);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'interval' => ['required', 'string', Rule::in(['monthly', 'yearly'])],
        ];
    }
}
