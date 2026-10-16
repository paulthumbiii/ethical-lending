<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BorrowerRegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:3', 'max:255'],
            'id_number' => ['required', 'string', 'regex:/^[0-9]{6,9}$/', 'unique:borrowers,id_number'],
            'phone_number' => ['required', 'string', 'regex:/^(?:254|\+254|0)?([71][0-9]{8})$/'],
            'kra_pin' => ['nullable', 'string', 'regex:/^[A-Z][0-9]{9}[A-Z]$/'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:borrowers,email'],
            'monthly_income' => ['required', 'numeric', 'min:0'],
            'monthly_expenses' => ['required', 'numeric', 'min:0'],
            'mpesa_average_monthly_inflow' => ['nullable', 'numeric', 'min:0'],
            'existing_debt_obligations' => ['nullable', 'numeric', 'min:0'],
            'data_protection_consent' => ['required', 'boolean', 'accepted'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id_number.regex' => 'The ID number must be a valid Kenyan National ID (6 to 9 digits).',
            'phone_number.regex' => 'The phone number must be a valid Kenyan mobile number (Safaricom/Airtel format).',
            'kra_pin.regex' => 'The KRA PIN must match standard format (e.g., A123456789X).',
            'data_protection_consent.accepted' => 'Explicit statutory consent under the Kenya Data Protection Act 2019 is required to register.',
        ];
    }
}