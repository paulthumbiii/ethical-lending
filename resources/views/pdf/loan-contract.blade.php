<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Agreement and Key Facts Statement - {{ $loan->loan_number }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1e293b; line-height: 1.5; padding: 24px; }
        .header { border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 20px; }
        .title { font-size: 20px; font-weight: bold; color: #0f172a; text-transform: uppercase; }
        .subtitle { font-size: 11px; color: #64748b; }
        .kfs-box { background-color: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
        .kfs-title { font-size: 14px; font-weight: bold; color: #047857; text-transform: uppercase; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 11px; }
        th { background-color: #f1f5f9; font-weight: 600; color: #334155; }
        .amount-col { text-align: right; font-family: 'Courier New', Courier, monospace; font-weight: bold; }
        .note { font-size: 10px; color: #64748b; font-weight: normal; }
        .statutory-badge { background-color: #e0f2fe; border: 1px solid #7dd3fc; color: #0369a1; padding: 8px 12px; border-radius: 6px; font-size: 10px; margin-bottom: 16px; }
        .qr-section { border-top: 1px dashed #cbd5e1; padding-top: 14px; margin-top: 24px; font-size: 10px; color: #64748b; }
    </style>
</head>
<body>

    <div class="header">
        <div class="title">Ethical Lending</div>
        <div class="subtitle">Loan agreement and Key Facts Statement</div>
        <div class="subtitle">Contract number: <strong>{{ $loan->loan_number }}</strong> | Date: {{ $generatedAt->format('d M Y') }}</div>
    </div>

    <table>
        <tr>
            <th>Borrower full name</th>
            <td>{{ $borrower->full_name }}</td>
            <th>National ID number</th>
            <td>{{ $borrower->id_number }}</td>
        </tr>
        <tr>
            <th>Mobile number (M-Pesa)</th>
            <td>{{ $borrower->phone_number }}</td>
            <th>KRA PIN</th>
            <td>{{ $borrower->kra_pin ?? 'Not provided' }}</td>
        </tr>
    </table>

    <div class="kfs-box">
        <div class="kfs-title">Key Facts Statement</div>
        <table>
            <tr>
                <td><strong>Principal amount sent to you</strong></td>
                <td class="amount-col">KES {{ number_format((float) $principal, 2) }}</td>
            </tr>
            <tr>
                <td><strong>Interest rate</strong></td>
                <td class="amount-col">{{ $annualRate }}% per year</td>
            </tr>
            <tr>
                <td>
                    <strong>Estimated interest over {{ $tenureDays }} days</strong><br>
                    <span class="note">Simple interest, if you repay on the due date.</span>
                </td>
                <td class="amount-col">KES {{ number_format((float) $interestOwed, 2) }}</td>
            </tr>
            @forelse ($fees as $fee)
                <tr>
                    <td>
                        <strong>{{ $fee['name'] }}</strong>
                        @if ($fee['excise'])
                            <br><span class="note">Includes 20% excise duty (KES {{ number_format((float) bcmul($fee['amount'], '0.20', 2), 2) }}). Nothing extra is charged on top.</span>
                        @endif
                    </td>
                    <td class="amount-col">KES {{ number_format((float) $fee['amount'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td><strong>Fees</strong></td>
                    <td class="amount-col">None</td>
                </tr>
            @endforelse
            <tr style="background-color: #f1f5f9; font-size: 12px;">
                <td><strong>Total cost of credit (interest + fees)</strong></td>
                <td class="amount-col" style="color: #047857;">KES {{ number_format((float) $totalCostOfCredit, 2) }}</td>
            </tr>
            <tr style="background-color: #e2e8f0; font-size: 13px;">
                <td><strong>Total to repay</strong></td>
                <td class="amount-col" style="color: #0f172a;">KES {{ number_format((float) $totalRepayable, 2) }}</td>
            </tr>
            <tr>
                <td>
                    <strong>Effective yearly cost</strong><br>
                    <span class="note">Interest and fees as a share of the principal, scaled to a year. Simple, not compounded.</span>
                </td>
                <td class="amount-col">{{ $apr }}%</td>
            </tr>
        </table>
    </div>

    <div class="statutory-badge">
        <strong>In-duplum protection (Banking Act s.44A):</strong>
        If you default, interest, penalties and collection fees together can never exceed the principal still owed at default (KES {{ number_format((float) $principal, 2) }}).
    </div>

    <div class="statutory-badge" style="background-color: #fef3c7; border-color: #fde68a; color: #92400e;">
        <strong>30-day notice before credit reporting:</strong>
        We will not report you to a credit reference bureau without 30 days written notice. If you are struggling to repay, ask about restructuring.
    </div>

    <div class="qr-section">
        <div><strong>Verify this contract:</strong></div>
        <div>Verification link: <a href="{{ $verificationUrl }}">{{ $verificationUrl }}</a></div>
        <div>Verification code: <strong>{{ $qrCode }}</strong></div>
        <div>Opening the link shows the contract's digital fingerprint (SHA-256) held on our record.</div>
        <div style="margin-top: 8px;">Generated {{ $generatedAt->toIso8601String() }}</div>
        <div style="margin-top: 8px;"><strong>Demonstration document.</strong> This is a skill-learning project, not a real credit agreement.</div>
    </div>

</body>
</html>