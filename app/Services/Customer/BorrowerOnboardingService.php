<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Enums\RiskBand;
use App\Enums\UserRole;
use App\Exceptions\Fintech\DataProtectionConsentMissingException;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\BorrowerFinancialProfile;
use App\Models\DataProtectionConsent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class BorrowerOnboardingService
{
    /**
     * Executes borrower registration, financial profiling, KDPA 2019 consent logging, login creation
     * (when a password is supplied) and audit tracking inside an atomic transaction.
     *
     * @param array $data Validated input data from BorrowerRegistrationRequest
     * @param string|null $ipAddress Client IP address
     * @param string|null $userAgent Client User Agent string
     * @return Borrower
     * @throws DataProtectionConsentMissingException
     * @throws ValidationException When the phone or email already belongs to a login account
     */
    public function register(array $data, ?string $ipAddress = null, ?string $userAgent = null): Borrower
    {
        $consentGranted = filter_var($data['data_protection_consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $consentGranted) {
            throw new DataProtectionConsentMissingException($data['id_number'] ?? 'UNKNOWN');
        }

        return DB::transaction(function () use ($data, $ipAddress, $userAgent) {
            $normalizedPhone = $this->normalizePhoneNumber((string) $data['phone_number']);

            $password = isset($data['password']) && $data['password'] !== ''
                ? (string) $data['password']
                : null;

            $email = isset($data['email']) && $data['email'] !== ''
                ? (string) $data['email']
                : null;

            $user = null;

            if ($password !== null) {
                $this->assertLoginDetailsAvailable($normalizedPhone, $email);

                /** @var User $user */
                $user = User::create([
                    'name' => $data['full_name'],
                    'email' => $email ?? $this->placeholderEmail($normalizedPhone),
                    'password' => $password, // hashed by the User model cast
                    'phone_number' => $normalizedPhone,
                    'is_active' => true,
                ]);

                Role::findOrCreate(UserRole::Borrower->value, 'web');
                $user->assignRole(UserRole::Borrower->value);
            }

            /** @var Borrower $borrower */
            $borrower = Borrower::create([
                'user_id' => $user?->id,
                'full_name' => $data['full_name'],
                'id_number' => $data['id_number'],
                'kra_pin' => isset($data['kra_pin']) ? strtoupper((string) $data['kra_pin']) : null,
                'phone_number' => $normalizedPhone,
                'email' => $data['email'] ?? null,
                'credit_limit' => '0.00',
                'risk_band' => RiskBand::C,
                'is_blacklisted' => false,
            ]);

            BorrowerFinancialProfile::create([
                'borrower_id' => $borrower->id,
                'monthly_income' => $data['monthly_income'],
                'monthly_expenses' => $data['monthly_expenses'],
                'mpesa_average_monthly_inflow' => $data['mpesa_average_monthly_inflow'] ?? '0.00',
                'existing_debt_obligations' => $data['existing_debt_obligations'] ?? '0.00',
            ]);

            DataProtectionConsent::create([
                'borrower_id' => $borrower->id,
                'consent_type' => 'CREDIT_SCORING_AND_M_PESA_DATA_PROCESSING',
                'is_granted' => true,
                'ip_address' => $ipAddress ?? '127.0.0.1',
                'user_agent' => $userAgent ?? 'SYSTEM',
                'granted_at' => now(),
            ]);

            AuditTrail::create([
                'user_id' => auth()->id(),
                'action' => 'BORROWER_ONBOARDED',
                'auditable_type' => Borrower::class,
                'auditable_id' => $borrower->id,
                'old_values' => null,
                'new_values' => [
                    'id_number' => $borrower->id_number,
                    'phone_number' => $borrower->phone_number,
                    'risk_band' => $borrower->risk_band->value,
                    'login_created' => $user !== null,
                ],
                'ip_address' => $ipAddress ?? '127.0.0.1',
                'user_agent' => $userAgent ?? 'SYSTEM',
            ]);

            Log::channel('fintech')->info("Borrower onboarding successful: ID {$borrower->id} [{$borrower->id_number}]");

            return $borrower;
        });
    }

    /**
     * Stops registration cleanly if the phone or email already belongs to a login account.
     *
     * @throws ValidationException
     */
    private function assertLoginDetailsAvailable(string $phone, ?string $email): void
    {
        $errors = [];

        if (User::query()->where('phone_number', $phone)->exists()) {
            $errors['phone_number'] = 'This phone number already has an account. Try signing in instead.';
        }

        if ($email !== null && User::query()->where('email', $email)->exists()) {
            $errors['email'] = 'This email address already has an account.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Borrowers without an email sign in by phone. The reserved .invalid ending can never be a real address.
     */
    private function placeholderEmail(string $normalizedPhone): string
    {
        return $normalizedPhone . '@borrowers.ethical-lending.invalid';
    }

    /**
     * Normalizes Kenyan phone numbers to E.164 Safaricom format (2547XXXXXXXX or 2541XXXXXXXX).
     */
    private function normalizePhoneNumber(string $phone): string
    {
        $cleaned = preg_replace('/\D/', '', $phone);

        if (str_starts_with($cleaned, '0')) {
            return '254'.substr($cleaned, 1);
        }

        if (str_starts_with($cleaned, '7') || str_starts_with($cleaned, '1')) {
            return '254'.$cleaned;
        }

        return $cleaned;
    }
}