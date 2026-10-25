<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FinanceViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is controlled by the finance.view route middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tab'     => ['nullable', Rule::in(['journal', 'trial', 'excise', 'mpesa'])],
            'source'  => ['nullable', Rule::in(['c2b', 'stk', 'b2c'])],
            'status'  => ['nullable', Rule::in(['all', 'accrued', 'remitted'])],
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date', 'after_or_equal:from'],
            'account' => ['nullable', 'string', 'max:20'],
            'page'    => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function tab(): string
    {
        return (string) ($this->validated('tab') ?? 'journal');
    }
}