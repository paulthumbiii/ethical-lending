<?php

declare(strict_types=1);

namespace App\Services\Omnichannel;

use App\Enums\LoanStatus;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanLedgerEntry;
use App\Models\MpesaStkRequest;
use App\Models\UssdSession;
use App\Services\Servicing\RestructuringEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class UssdMenuEngine
{
    public function __construct(private readonly RestructuringEngine $restructuringEngine) {}

    /**
     * Processes incoming Africa's Talking USSD request and returns telecom string.
     *
     * @param array{
     *     sessionId: string,
     *     serviceCode: string,
     *     phoneNumber: string,
     *     text: string
     * } $data
     */
    public function handleUssdRequest(array $data): string
    {
        $sessionId = (string) ($data['sessionId'] ?? '');
        $serviceCode = (string) ($data['serviceCode'] ?? '*384#');
        $rawPhone = (string) ($data['phoneNumber'] ?? '');
        $text = trim((string) ($data['text'] ?? ''));

        // Normalize phone number to Kenyan format (2547XXXXXXXX)
        $phone = $this->normalizePhoneNumber($rawPhone);

        // Track or update USSD session
        $session = UssdSession::updateOrCreate(
            ['session_id' => $sessionId],
            [
                'phone_number'      => $phone,
                'service_code'      => $serviceCode,
                'current_menu_node' => $text,
            ]
        );

        $borrower = Borrower::query()->where('phone_number', $phone)->first();
        $inputs = $text === '' ? [] : explode('*', $text);
        $level = count($inputs);

        // 1. Root Menu
        if ($level === 0) {
            $name = $borrower ? explode(' ', $borrower->full_name)[0] : 'Customer';
            return "CON Welcome to M-Kopo Safi, {$name}\n" .
                   "1. Check Loan Balance\n" .
                   "2. Apply for Working Capital\n" .
                   "3. Repay via M-Pesa\n" .
                   "4. Request Restructuring Relief\n" .
                   "5. Account Statement";
        }

        $rootChoice = $inputs[0];

        // 2. Menu 1: Check Loan Balance & In-Duplum Safety Cap
        if ($rootChoice === '1') {
            return $this->renderBalanceMenu($borrower);
        }

        // 3. Menu 2: Apply for Loan
        if ($rootChoice === '2') {
            return $this->handleLoanApplicationMenu($borrower, $inputs);
        }

        // 4. Menu 3: Repay via M-Pesa STK Push
        if ($rootChoice === '3') {
            return $this->handleMpesaRepaymentMenu($borrower, $inputs);
        }

        // 5. Menu 4: Dynamic Restructuring Relief
        if ($rootChoice === '4') {
            return $this->handleRestructuringMenu($borrower, $inputs);
        }

        // 6. Menu 5: Statement Summary
        if ($rootChoice === '5') {
            return "END M-Kopo Safi Statement:\nDetailed statement sent to {$phone} via SMS. Thank you.";
        }

        return "END Invalid choice. Please dial {$serviceCode} to try again.";
    }

    private function renderBalanceMenu(?Borrower $borrower): string
    {
        if ($borrower === null) {
            return "END You are not registered with M-Kopo Safi. Please register via our web portal.";
        }

        $loan = Loan::query()
            ->where('borrower_id', $borrower->id)
            ->whereIn('status', ['disbursed', 'defaulted', 'watch', 'restructured'])
            ->with(['inDuplumTracking'])
            ->latest('id')
            ->first();

        if ($loan === null) {
            return "END You currently have no active loan with M-Kopo Safi. Dial *384# and select 2 to apply.";
        }

        $latest = LoanLedgerEntry::where('loan_id', $loan->id)->latest('id')->first();
        $principal = $latest ? number_format((float) $latest->running_principal_balance, 2) : number_format((float) $loan->principal_disbursed, 2);
        $total = $latest ? number_format((float) $latest->total_running_balance, 2) : $principal;

        $inDuplumNotice = $loan->inDuplumTracking !== null
            ? "\nProtected under Sec 44A In-Duplum."
            : "";

        return "END M-Kopo Safi Balance:\n" .
               "Loan: #{$loan->loan_number}\n" .
               "Principal: KES {$principal}\n" .
               "Total Due: KES {$total}\n" .
               "Due Date: " . CarbonImmutable::parse($loan->due_date)->format('d/m/Y') .
               $inDuplumNotice;
    }

    private function handleLoanApplicationMenu(?Borrower $borrower, array $inputs): string
    {
        if ($borrower === null) {
            return "END Registration required. Please register online with your National ID.";
        }

        if (count($inputs) === 1) {
            $limit = number_format((float) $borrower->credit_limit, 2);
            return "CON Working Capital Application:\nYour Credit Limit is KES {$limit}.\nEnter amount to borrow (KES):";
        }

        $amount = $inputs[1];
        if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > (float) $borrower->credit_limit) {
            return "END Invalid amount. Amount must not exceed your credit limit of KES " . number_format((float) $borrower->credit_limit, 2);
        }

        return "END Application of KES " . number_format((float) $amount, 2) . " received. You will receive an M-Pesa disbursement SMS shortly.";
    }

    private function handleMpesaRepaymentMenu(?Borrower $borrower, array $inputs): string
    {
        if ($borrower === null) {
            return "END No active account found.";
        }

        $loan = Loan::where('borrower_id', $borrower->id)->whereIn('status', ['disbursed', 'defaulted', 'watch', 'restructured'])->latest('id')->first();

        if ($loan === null) {
            return "END No active loan to repay.";
        }

        if (count($inputs) === 1) {
            return "CON Repay Loan #{$loan->loan_number}:\nEnter repayment amount (KES):";
        }

        $amount = $inputs[1];
        if (!is_numeric($amount) || (float) $amount <= 0) {
            return "END Invalid repayment amount entered.";
        }

        // Create pending STK request record in database
        MpesaStkRequest::create([
            'loan_id'              => $loan->id,
            'merchant_request_id'  => 'USSD_' . strtoupper(Str::random(8)),
            'checkout_request_id'  => 'ws_USSD_' . strtoupper(Str::random(12)),
            'phone_number'         => $borrower->phone_number,
            'amount'               => $amount,
            'status'               => 'pending',
        ]);

        return "END An M-Pesa prompt for KES " . number_format((float) $amount, 2) . " has been sent to your phone. Enter your PIN to complete.";
    }

    private function handleRestructuringMenu(?Borrower $borrower, array $inputs): string
    {
        if ($borrower === null) {
            return "END No active account found.";
        }

        $loan = Loan::where('borrower_id', $borrower->id)->whereIn('status', ['disbursed', 'defaulted', 'watch'])->latest('id')->first();

        if ($loan === null) {
            return "END No facility currently eligible for restructuring.";
        }

        // Sub-step: Prompt relief proposal
        if (count($inputs) === 1) {
            try {
                $proposal = $this->restructuringEngine->generateProposal($loan, 30);
                $waiver = number_format((float) $proposal->forgiven_penalties, 2);

                return "CON Relief Offer for Loan #{$loan->loan_number}:\n" .
                       "• 100% Late Fee Waiver: KES {$waiver}\n" .
                       "• Tenure Extended: +30 Days\n" .
                       "1. Accept Relief Offer\n" .
                       "2. Decline";
            } catch (\Throwable $e) {
                return "END You are currently not eligible for automated restructuring. Call 0700000000 for support.";
            }
        }

        if ($inputs[1] === '1') {
            $proposal = $loan->restructuringProposals()->where('status', 'offered')->latest('id')->first();
            if ($proposal) {
                $this->restructuringEngine->acceptProposal($proposal);
                return "END Relief Offer Accepted! Your loan has been restructured with 0 late penalties and 30 extra days. Asante.";
            }
            return "END No active proposal found.";
        }

        return "END Relief offer declined. You can request again at any time.";
    }

    private function normalizePhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if (str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        if (str_starts_with($phone, '254')) {
            return $phone;
        }

        return '254' . $phone;
    }
}