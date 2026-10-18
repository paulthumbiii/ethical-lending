<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StkPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:250000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required' => 'Enter how much you want to pay.',
            'amount.integer'  => 'Enter a whole number of shillings, for example 500.',
            'amount.min'      => 'The smallest payment is KES 1.',
            'amount.max'      => 'M-Pesa allows up to KES 250,000 in one payment.',
        ];
    }
}