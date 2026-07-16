<?php

namespace App\Http\Requests;

use App\Services\Accounts\CurrentAccount;
use App\Services\BankingSetupGuide;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBankingChecklistItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(CurrentAccount $currentAccount): bool
    {
        $key = $this->route('key');
        $user = $this->user();
        $business = $currentAccount->account()->business;

        return is_string($key)
            && BankingSetupGuide::canCompleteKey($key)
            && $user !== null
            && $business !== null
            && $user->can('operate', $business);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'completed' => ['required', 'boolean'],
        ];
    }
}
