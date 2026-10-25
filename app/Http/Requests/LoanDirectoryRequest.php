<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\LoanStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LoanDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access control is done by the permission middleware on the route.
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'q'      => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(LoanStatus::class)],
            'frozen' => ['nullable', 'in:1'],
            'page'   => ['nullable', 'integer', 'min:1'],
        ];
    }
}