<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BorrowerDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access control is done by the permission middleware on the route.
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'q'      => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,blacklisted,clear'],
            'page'   => ['nullable', 'integer', 'min:1'],
        ];
    }
}