<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is controlled by the audit.view route middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['nullable', 'string', 'max:100'],
            'user'   => ['nullable', 'string', 'max:100'],
            'entity' => ['nullable', 'string', 'max:100'],
            'from'   => ['nullable', 'date'],
            'to'     => ['nullable', 'date', 'after_or_equal:from'],
            'page'   => ['nullable', 'integer', 'min:1'],
        ];
    }
}