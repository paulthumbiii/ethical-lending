<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Agreement & Key Facts Statement - {{ $loan->loan_number }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 12px; color: #1e293b; line-height: 1.5; padding: 24px; }
        .header { border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 20px; }
        .title { font-size: 20px; font-weight: bold; color: #0f172a; text-transform: uppercase; }
        .subtitle { font-size: 11px; color: #64748b; }
        .kfs-box { background-color: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
        .kfs-title { font-size: 14px; font-weight: bold; color: #047857; text-transform: uppercase; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 11px; }
        th { background-color: #f1f5f9; font-weight: 600; color: #334155; }
        .amount-col { text-align: right; font-family: 'Courier New', Courier, monospace; font-weight: bold; }
        .statutory-badge { background-color: #e0f2fe; border: 1px solid #7dd3fc; color: #0369a1; padding: 8px 12px; border-radius: 6px; font-size: 10px; margin-bottom: 16px; }
        .qr-section { border-top: 1px dashed #cbd5e1; padding-top: 14px; margin-top: 24px; font-size: 10px; color: #64748b; }
        .hash-code { font-family: 'Courier New', Courier, monospace; font-size: 9px; color: #0f172a; word-break: break-all; }
    </style>
</head>
<body>

    <div class="header">
        <div class="title">Ethical Lending Core LMS</div>
        <div class="subtitle">Regulated Digital Credit Provider | Central Bank of Kenya (CBK) DCP Act 2022</div>
        <div class="subtitle">Facility Contract Number: <strong>{{ $loan->loan_number }}</strong> | Date: {{ $generatedAt->format('d M Y') }}</div>
    </div>

    <!-- BORROWER KYC SUMMARY -->
    <table>
        <tr>
            <th>Borrower Full Name</th>
            <td>{{ $borrower->full_name }}</td>
            <th>National ID Number</th>
            <td>{{ $borrower->id_number }}</td>
        </tr>
        <tr>
            <th>Mobile Number (M-Pesa)</th>
            <td>{{ $borrower->phone_number }}</td>
            <th>KRA PIN</th>
            <td>{{ $borrower->kra_pin ?? 'NOT_PROVIDED' }}</td>
        </tr>
    </table>

    <!-- STANDARDIZED CBK KEY FACTS STATEMENT (KFS) -->
    <div class="kfs-box">
        <div class="kfs-title">Standardized Key Facts Statement (CBK DCP Reg. 18 & 19)</div>
        <table>
            <tr>
                <td><strong>1. Principal Amount Disbursed</strong></td>
                <td class="amount-col">KES {{ number_format((float)$principal, 2) }}</td>
            </tr>
            <tr>
                <td><strong>2. Nominal Interest Rate</strong></td>
                <td class="amount-col">{{ $annualRate }}% p.a.</td>
            </tr>
            <tr>
                <td><strong>3. Total Interest Over Tenure ({{ $tenureDays }} Days)</strong></td>
                <td class="amount-col">KES {{ number_format((float)$interestOwed, 2) }}</td>
            </tr>
            <tr>
                <td><strong>4. Loan Processing Fee (5%)</strong></td>
                <td class="amount-col">KES {{ number_format((float)$processingFee, 2) }}</td>
            </tr>
            <tr>
                <td><strong>5. Mandatory KRA Excise Duty (20% on Fee)</strong></td>
                <td class="amount-col">KES {{ number_format((float)$exciseDuty, 2) }}</td>
            </tr>
            <tr style="background-color: #f1f5f9; font-size: 12px;">
                <td><strong>6. TOTAL COST OF CREDIT (Interest + Fees + Tax)</strong></td>
                <td class="amount-col" style="color: #047857;">KES {{ number_format((float)$totalCostOfCredit, 2) }}</td>
            </tr>
            <tr style="background-color: #e2e8f0; font-size: 13px;">
                <td><strong>7. TOTAL REPAYABLE AMOUNT</strong></td>
                <td class="amount-col" style="color: #0f172a;">KES {{ number_format((float)$totalRepayable, 2) }}</td>
            </tr>
            <tr>
                <td><strong>8. Annual Percentage Rate (APR) Equivalent</strong></td>
                <td class="amount-col">{{ $apr }}%</td>
            </tr>
        </table>
    </div>

    <!-- STATUTORY GUARANTEES -->
    <div class="statutory-badge">
        <strong>🛡️ Section 44A Banking Act In-Duplum Protection:</strong>
        In the event of default, interest and late fees are legally capped at the principal outstanding at default (KES {{ number_format((float)$principal, 2) }}). Total charges will never exceed this statutory limit.
    </div>

    <div class="statutory-badge" style="background-color: #fef3c7; border-color: #fde68a; color: #92400e;">
        <strong>📢 30-Day CRB Pre-Listing Notice (CRB Reg. 28):</strong>
        No adverse credit reporting will occur without 30 days prior written notice. If experiencing hardship, flexible restructuring options are available.
    </div>

    <!-- DIGITAL VERIFICATION FOOTER -->
    <div class="qr-section">
        <div><strong>Tamper-Proof Cryptographic Verification:</strong></div>
        <div>Verification URL: <a href="{{ $verificationUrl }}">{{ $verificationUrl }}</a></div>
        <div>Verification Code: <strong>{{ $qrCode }}</strong></div>
        <div>Contract SHA-256 Digest:</div>
        <div class="hash-code">{{ hash('sha256', $loan->loan_number . $borrower->id_number . $principal) }}</div>
        <div style="margin-top: 8px;">Digitally signed & sealed under Kenyan law at {{ $generatedAt->toIso8601String() }}</div>
    </div>

</body>
</html>