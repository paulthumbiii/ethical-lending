<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateApplicationInflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'mpesa_average_monthly_inflow' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mpesa_average_monthly_inflow.required' => 'Enter the verified monthly M-Pesa inflow.',
            'mpesa_average_monthly_inflow.regex' => 'Use a plain amount such as 7500 or 7500.50 (no commas, no negatives).',
        ];
    }
}