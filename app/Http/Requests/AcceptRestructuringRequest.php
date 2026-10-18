<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Customer\BorrowerPortalService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AcceptRestructuringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'tenure_extension_days' => ['required', 'integer', Rule::in(BorrowerPortalService::EXTENSION_OPTIONS)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tenure_extension_days.required' => 'Choose how much extra time you need.',
            'tenure_extension_days.in' => 'Choose one of the time options shown.',
        ];
    }
}