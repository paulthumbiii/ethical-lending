<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'loan_product_id' => ['required', 'integer', 'exists:loan_products,id'],
            'amount' => ['required', 'regex:/^\d{1,9}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter the amount in whole shillings, for example 5000.',
        ];
    }
}