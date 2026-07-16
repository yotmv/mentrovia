<?php

namespace App\Http\Requests;

use App\Enums\FeedbackCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserFeedbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(FeedbackCategory::class)],
            'page' => ['nullable', 'string', 'max:2048'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }
}
