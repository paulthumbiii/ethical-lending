<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AcceptStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'verified_inflow' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'verified_inflow.required' => 'Enter the average monthly inflow you verified from the statement.',
            'verified_inflow.regex' => 'Use a plain amount such as 7500 or 7500.50 (no commas, no negatives).',
        ];
    }
}