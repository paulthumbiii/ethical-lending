<?php

declare(strict_types=1);

namespace App\Services\Lending;

use App\Enums\LoanStatus;
use App\Models\AuditTrail;
use App\Models\Loan;
use App\Models\MpesaB2cDisbursement;
use App\Services\Financial\Mpesa\B2cDisbursementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class LoanDisbursementWorkflowService
{
    public function __construct(
        private readonly LoanOriginationService $origination,
        private readonly B2cDisbursementService $b2c,
        private readonly LoanFeeService $fees
    ) {}

    /**
     * Simulated payout results are for local demos and tests only.
     */
    public static function simulationAllowed(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /**
     * Creates the loan if needed, then asks M-Pesa to send the money.
     * Pressing again after a failed attempt resumes with the same loan.
     */
    public function disburse(int $applicationId, int $userId, string $ip, string $userAgent): MpesaB2cDisbursement
    {
        $loan = Loan::query()->where('loan_application_id', $applicationId)->first();

        if ($loan === null) {
            $loan = $this->origination->originate($applicationId, $userId, $ip, $userAgent);
        }

        // Never send money we could not book properly afterwards.
        $this->fees->assertLedgerReady($loan);

        $disbursement = $this->b2c->initiateDisbursement($loan);

        if ($disbursement->status === 'failed') {
            throw new RuntimeException(
                'Safaricom did not accept the payout request ('
                . mb_substr((string) $disbursement->response_description, 0, 150)
                . '). Nothing was sent. You can try again.'
            );
        }

        return $disbursement;
    }

    /**
     * Local demo only: pretends Safaricom reported a successful payout.
     */
    public function simulateResult(int $applicationId, int $userId, string $ip, string $userAgent): MpesaB2cDisbursement
    {
        if (! self::simulationAllowed()) {
            throw new RuntimeException('Simulated results are only available on a local test setup.');
        }

        $loan = Loan::query()->where('loan_application_id', $applicationId)->first();

        if ($loan === null) {
            throw new RuntimeException('Create the loan first.');
        }

        $disbursement = DB::transaction(function () use ($loan, $userId, $ip, $userAgent): MpesaB2cDisbursement {
            $locked = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== LoanStatus::APPROVAL_HOLD) {
                throw new RuntimeException('This loan is not waiting for money to be sent.');
            }

            $row = MpesaB2cDisbursement::query()
                ->where('loan_id', $locked->id)
                ->where('status', 'initiated')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $row = MpesaB2cDisbursement::create([
                    'loan_id'                    => $locked->id,
                    'originator_conversation_id' => 'SIM-' . strtoupper(Str::random(12)),
                    'conversation_id'            => 'SIMCONV-' . strtoupper(Str::random(12)),
                    'recipient_phone'            => (string) $locked->borrower->phone_number,
                    'amount'                     => (string) $locked->principal_disbursed,
                    'status'                     => 'initiated',
                    'response_code'              => '0',
                    'response_description'       => 'Simulated: no request was sent to Safaricom.',
                ]);
            }

            AuditTrail::create([
                'user_id'        => $userId,
                'action'         => 'LOAN_DISBURSEMENT_SIMULATED',
                'auditable_type' => MpesaB2cDisbursement::class,
                'auditable_id'   => $row->id,
                'old_values'     => null,
                'new_values'     => [
                    'loan_number' => $locked->loan_number,
                    'amount'      => (string) $locked->principal_disbursed,
                ],
                'ip_address'     => $ip,
                'user_agent'     => mb_substr($userAgent, 0, 255),
            ]);

            return $row;
        });

        return $this->b2c->processCallback([
            'Result' => [
                'ResultCode'               => 0,
                'ResultDesc'               => 'Simulated: the service request is processed successfully.',
                'OriginatorConversationID' => (string) $disbursement->originator_conversation_id,
                'ConversationID'           => (string) $disbursement->conversation_id,
                'TransactionID'            => 'SIM' . strtoupper(Str::random(8)),
            ],
        ]);
    }
}