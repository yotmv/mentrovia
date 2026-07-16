<?php

namespace App\Http\Requests;

use App\Models\BusinessTask;
use App\Services\Accounts\CurrentAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(CurrentAccount $currentAccount): bool
    {
        $task = $this->route('task');
        $user = $this->user();
        $business = $currentAccount->account()->business;

        return $task instanceof BusinessTask
            && $user !== null
            && $business !== null
            && $business->id === $task->business_id
            && $task->is_active
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
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
