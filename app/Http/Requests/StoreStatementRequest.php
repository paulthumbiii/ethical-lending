<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'statement' => ['required', 'file', 'max:5120', 'extensions:pdf'],
            'statement_password' => ['nullable', 'string', 'max:100'],
            'consent' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'statement.required' => 'Choose your M-Pesa statement PDF.',
            'statement.max' => 'The file is too large. The limit is 5 MB.',
            'statement.extensions' => 'Upload the statement as a PDF file.',
            'consent.accepted' => 'Please agree to let us read your statement so we can assess your application.',
        ];
    }
}