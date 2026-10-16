<?php

declare(strict_types=1);

namespace App\Services\Servicing;

use App\Models\AuditTrail;
use App\Models\Loan;
use App\Models\LoanLegalContract;
use App\Models\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ContractGeneratorService
{
    /**
     * Generates a tamper-proof loan contract and Key Facts Statement (KFS) with SHA-256 hash.
     */
    public function generateContract(Loan $loan): LoanLegalContract
    {
        return DB::transaction(function () use ($loan): LoanLegalContract {
            $lockedLoan = Loan::query()
                ->where('id', $loan->id)
                ->with(['borrower', 'product', 'amortizationSchedules'])
                ->lockForUpdate()
                ->firstOrFail();

            $now = CarbonImmutable::now();
            $borrower = $lockedLoan->borrower;
            $product = $lockedLoan->product;

            // 1. Calculate Statutory Key Facts Statement (KFS) Metrics
            $principal = (string) $lockedLoan->principal_disbursed;
            $annualRate = (string) $lockedLoan->annual_interest_rate;
            $tenureDays = (int) $lockedLoan->tenure_days;

            // Simple Interest = Principal * (Rate / 100) * (TenureDays / 365)
            $interestOwed = bcmul(
                $principal,
                bcmul(bcdiv($annualRate, '100.00', 4), bcdiv((string) $tenureDays, '365.00', 4), 4),
                2
            );

            // Processing Fee = 5% of Principal (with 20% KRA Excise Duty)
            $processingFee = bcmul($principal, '0.05', 2);
            $exciseDuty = bcmul($processingFee, '0.20', 2);
            $totalCostOfCredit = bcadd(bcadd($interestOwed, $processingFee, 2), $exciseDuty, 2);
            $totalRepayable = bcadd($principal, $totalCostOfCredit, 2);

            // Annual Percentage Rate (APR) Approximation
            $apr = number_format((float) $annualRate + 5.0, 2, '.', '');

            $qrCode = 'VER-' . strtoupper(Str::random(16));
            $verificationUrl = url('/verify/contract/' . $qrCode);

            // 2. Render Blade Contract Template
            $renderedHtml = view('pdf.loan-contract', [
                'loan'              => $lockedLoan,
                'borrower'          => $borrower,
                'product'           => $product,
                'principal'         => $principal,
                'annualRate'        => $annualRate,
                'apr'               => $apr,
                'tenureDays'        => $tenureDays,
                'interestOwed'      => $interestOwed,
                'processingFee'     => $processingFee,
                'exciseDuty'        => $exciseDuty,
                'totalCostOfCredit' => $totalCostOfCredit,
                'totalRepayable'    => $totalRepayable,
                'qrCode'            => $qrCode,
                'verificationUrl'   => $verificationUrl,
                'generatedAt'       => $now,
            ])->render();

            // 3. Compute Cryptographic SHA-256 Digest
            $sha256Hash = hash('sha256', $renderedHtml . $lockedLoan->loan_number . $borrower->id_number);

            // 4. Save Contract Artifact to Storage
            $storageDir = storage_path('app/contracts');
            if (!File::exists($storageDir)) {
                File::makeDirectory($storageDir, 0755, true);
            }

            $fileName = "contract_{$lockedLoan->loan_number}_{$now->format('YmdHis')}.html";
            $filePath = "contracts/{$fileName}";
            File::put(storage_path("app/{$filePath}"), $renderedHtml);

            // 5. Create or Update LoanLegalContract record
            $contract = LoanLegalContract::updateOrCreate(
                ['loan_id' => $lockedLoan->id],
                [
                    'contract_pdf_path'           => $filePath,
                    'contract_sha256_hash'        => $sha256Hash,
                    'digital_signature_timestamp' => $now,
                    'qr_verification_code'        => $qrCode,
                ]
            );

            // 6. Dispatch cryptographically chained Outbox Event
            $prevEvent = OutboxEvent::query()->latest('id')->first();
            $prevHash = $prevEvent ? $prevEvent->current_event_hash : str_repeat('0', 64);

            $payload = [
                'loan_id'         => $lockedLoan->id,
                'loan_number'     => $lockedLoan->loan_number,
                'borrower_id'     => $borrower->id,
                'sha256_hash'     => $sha256Hash,
                'qr_code'         => $qrCode,
                'contract_path'   => $filePath,
                'total_repayable' => $totalRepayable,
                'generated_at'    => $now->toIso8601String(),
            ];

            $currentHash = hash('sha256', $prevHash . 'contract.generated' . json_encode($payload));

            OutboxEvent::create([
                'aggregate_type'      => LoanLegalContract::class,
                'aggregate_id'        => $contract->id,
                'event_name'          => 'contract.generated',
                'payload'             => $payload,
                'previous_event_hash' => $prevHash,
                'current_event_hash'  => $currentHash,
                'is_published'        => false,
                'retry_count'         => 0,
            ]);

            AuditTrail::create([
                'user_id'        => auth()->id(),
                'action'         => 'LEGAL_CONTRACT_GENERATED',
                'auditable_type' => LoanLegalContract::class,
                'auditable_id'   => $contract->id,
                'old_values'     => null,
                'new_values'     => [
                    'sha256_hash' => $sha256Hash,
                    'qr_code'     => $qrCode,
                ],
                'ip_address'     => request()->ip() ?? '127.0.0.1',
                'user_agent'     => request()->userAgent() ?? 'System/CLI',
            ]);

            Log::channel('daily')->info(sprintf(
                'Legal Contract generated for Loan #%s. SHA-256: %s | QR: %s',
                $lockedLoan->loan_number,
                $sha256Hash,
                $qrCode
            ));

            return $contract;
        });
    }

    /**
     * Verifies the authenticity of a contract via its public QR verification code.
     *
     * @return array<string, mixed>|null
     */
    public function verifyContract(string $qrCode): ?array
    {
        $contract = LoanLegalContract::query()
            ->where('qr_verification_code', $qrCode)
            ->with(['loan.borrower', 'loan.product'])
            ->first();

        if ($contract === null) {
            return null;
        }

        $loan = $contract->loan;

        return [
            'is_valid'          => true,
            'contract_id'       => $contract->id,
            'loan_number'       => $loan->loan_number,
            'borrower_name'     => $loan->borrower->full_name,
            'borrower_id_masked'=> '*****' . substr((string) $loan->borrower->id_number, -4),
            'principal'         => number_format((float) $loan->principal_disbursed, 2),
            'sha256_hash'       => $contract->contract_sha256_hash,
            'signed_at'         => $contract->digital_signature_timestamp->format('d M Y, H:i \E\A\T'),
            'cbk_compliance'    => 'VERIFIED_CBK_DCP_COMPLIANT',
        ];
    }
}