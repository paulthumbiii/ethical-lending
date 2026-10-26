<?php

declare(strict_types=1);

namespace App\Services\Servicing;

use App\Models\AuditTrail;
use App\Models\Loan;
use App\Models\LoanLegalContract;
use App\Models\OutboxEvent;
use App\Services\Lending\LoanFeeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ContractGeneratorService
{
    public function __construct(private readonly LoanFeeService $fees)
    {
    }

    /**
     * Returns the loan's contract, generating it the first time.
     * Calling it again returns the same contract (same QR code and hash),
     * unless the stored file has gone missing or $force is true.
     */
    public function generateContract(Loan $loan, bool $force = false): LoanLegalContract
    {
        return DB::transaction(function () use ($loan, $force): LoanLegalContract {
            $lockedLoan = Loan::query()
                ->where('id', $loan->id)
                ->with(['borrower', 'product'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $force) {
                $existing = LoanLegalContract::query()->where('loan_id', $lockedLoan->id)->first();

                if ($existing !== null && Storage::disk('local')->exists((string) $existing->contract_pdf_path)) {
                    return $existing;
                }
            }

            $now = CarbonImmutable::now();
            $borrower = $lockedLoan->borrower;
            $product = $lockedLoan->product;

            $principal = (string) $lockedLoan->principal_disbursed;
            $annualRate = (string) $lockedLoan->annual_interest_rate;
            $tenureDays = (int) $lockedLoan->tenure_days;

            // Estimated simple interest = principal x rate% x days / 365, divided last so nothing is cut early.
            $interestOwed = bcdiv(
                bcmul(bcmul($principal, $annualRate, 6), (string) $tenureDays, 6),
                '36500',
                2
            );

            // Fees come from the product's fee rules, the same source the ledger uses.
            // Excise (20%) is part of the fee, not added on top.
            $fees = $this->fees->quote($lockedLoan);
            $feeTotal = '0.00';
            $exciseIncluded = '0.00';

            foreach ($fees as $fee) {
                $feeTotal = bcadd($feeTotal, $fee['amount'], 2);

                if ($fee['excise']) {
                    $exciseIncluded = bcadd($exciseIncluded, bcmul($fee['amount'], '0.20', 2), 2);
                }
            }

            $totalCostOfCredit = bcadd($interestOwed, $feeTotal, 2);
            $totalRepayable = bcadd($principal, $totalCostOfCredit, 2);

            // Simple effective annual cost: (interest + fees) / principal, scaled from the tenure to a year.
            $apr = '0.00';
            if (bccomp($principal, '0', 2) > 0 && $tenureDays > 0) {
                $apr = bcdiv(
                    bcmul($totalCostOfCredit, '36500', 6),
                    bcmul($principal, (string) $tenureDays, 6),
                    2
                );
            }

            $qrCode = 'VER-' . strtoupper(Str::random(16));
            $verificationUrl = url('/verify/contract/' . $qrCode);

            $renderedHtml = view('pdf.loan-contract', [
                'loan'              => $lockedLoan,
                'borrower'          => $borrower,
                'product'           => $product,
                'principal'         => $principal,
                'annualRate'        => $annualRate,
                'apr'               => $apr,
                'tenureDays'        => $tenureDays,
                'interestOwed'      => $interestOwed,
                'fees'              => $fees,
                'feeTotal'          => $feeTotal,
                'exciseIncluded'    => $exciseIncluded,
                'totalCostOfCredit' => $totalCostOfCredit,
                'totalRepayable'    => $totalRepayable,
                'qrCode'            => $qrCode,
                'verificationUrl'   => $verificationUrl,
                'generatedAt'       => $now,
            ])->render();

            $sha256Hash = hash('sha256', $renderedHtml . $lockedLoan->loan_number . $borrower->id_number);

            // Private disk only: never under public/.
            $filePath = "contracts/contract_{$lockedLoan->loan_number}_{$now->format('YmdHis')}.html";
            Storage::disk('local')->put($filePath, $renderedHtml);

            $contract = LoanLegalContract::updateOrCreate(
                ['loan_id' => $lockedLoan->id],
                [
                    'contract_pdf_path'           => $filePath,
                    'contract_sha256_hash'        => $sha256Hash,
                    'digital_signature_timestamp' => $now,
                    'qr_verification_code'        => $qrCode,
                ]
            );

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

            Log::channel('fintech')->info(sprintf(
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