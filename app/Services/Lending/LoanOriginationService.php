<?php

declare(strict_types=1);

namespace App\Services\Lending;

use App\Enums\LoanStatus;
use App\Models\AuditTrail;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApplication;
use App\Models\ProductInterestRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class LoanOriginationService
{
    public const APPLICATION_APPROVED = 'approved';

    /**
     * Turns an approved application into a loan that waits for disbursement.
     * No money moves here.
     */
    public function originate(int $applicationId, int $userId, string $ip, string $userAgent): Loan
    {
        return DB::transaction(function () use ($applicationId, $userId, $ip, $userAgent): Loan {
            $application = LoanApplication::query()
                ->whereKey($applicationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($application->status !== self::APPLICATION_APPROVED) {
                throw new RuntimeException('Only an approved application can become a loan.');
            }

            if (Loan::query()->where('loan_application_id', $application->id)->exists()) {
                throw new RuntimeException('A loan has already been created for this application.');
            }

            $borrower = Borrower::query()
                ->whereKey($application->borrower_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($borrower->is_blacklisted) {
                throw new RuntimeException('This borrower is blacklisted. No loan can be created.');
            }

            $rule = ProductInterestRule::query()
                ->where('loan_product_id', $application->loan_product_id)
                ->orderBy('id')
                ->first();

            if ($rule === null) {
                throw new RuntimeException('This product has no interest rate set up.');
            }

            $requested = (string) $application->requested_principal;
            $limit = (string) $borrower->credit_limit;
            $capped = bccomp($limit, $requested, 2) < 0 ? $limit : $requested;

            // M-Pesa B2C sends whole shillings only: cut down, never round up.
            $wholeShillings = bcadd($capped, '0', 0);
            $principal = bcadd($wholeShillings, '0.00', 2);

            if (bccomp($principal, '0.00', 2) <= 0) {
                throw new RuntimeException('The approved amount is zero, so no loan can be created.');
            }

            $tenureDays = (int) $application->requested_tenure_days;

            $loan = Loan::create([
                'loan_number'          => 'LN-' . now()->format('ymd') . '-' . strtoupper(Str::random(6)),
                'loan_application_id'  => $application->id,
                'borrower_id'          => $borrower->id,
                'loan_product_id'      => $application->loan_product_id,
                'principal_disbursed'  => $principal,
                'annual_interest_rate' => (string) $rule->annual_rate,
                'tenure_days'          => $tenureDays,
                'status'               => LoanStatus::APPROVAL_HOLD,
                'disbursed_at'         => null,
                'due_date'             => now()->addDays($tenureDays),
                'is_in_duplum_locked'  => false,
            ]);

            AuditTrail::create([
                'user_id'        => $userId,
                'action'         => 'LOAN_CREATED',
                'auditable_type' => Loan::class,
                'auditable_id'   => $loan->id,
                'old_values'     => null,
                'new_values'     => [
                    'loan_number'      => $loan->loan_number,
                    'application'      => $application->application_number,
                    'requested'        => $requested,
                    'credit_limit'     => $limit,
                    'principal'        => $principal,
                    'annual_rate'      => (string) $rule->annual_rate,
                ],
                'ip_address'     => $ip,
                'user_agent'     => mb_substr($userAgent, 0, 255),
            ]);

            return $loan;
        });
    }
}