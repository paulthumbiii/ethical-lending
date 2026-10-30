<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBorrowerPromiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'amount'        => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'promised_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required'              => 'Enter the amount you can pay.',
            'amount.regex'                 => 'Enter the amount as a number, for example 2500 or 2500.50.',
            'promised_date.required'       => 'Choose the date you will pay.',
            'promised_date.date_format'    => 'Choose the date from the calendar.',
        ];
    }
}