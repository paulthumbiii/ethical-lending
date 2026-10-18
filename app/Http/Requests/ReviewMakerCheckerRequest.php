<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReviewMakerCheckerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $isReject = $this->routeIs('lender.maker-checker.reject');

        return [
            'notes' => $isReject
                ? ['required', 'string', 'min:5', 'max:500']
                : ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.required' => 'Please give a reason. It is kept on the audit record.',
            'notes.min'      => 'Please give a little more detail (at least 5 characters).',
        ];
    }
}