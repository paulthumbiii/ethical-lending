<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\BorrowerFinancialProfile;
use App\Models\MpesaStatementUpload;
use App\Services\Underwriting\MpesaStatementParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('analyzes raw mpesa statement text correctly and counts fuliza and gambling occurrences', function () {
    /** @var MpesaStatementParserService $parser */
    $parser = $this->app->make(MpesaStatementParserService::class);

    $rawSampleText = <<<TEXT
2026-01-10 10:15:30 Customer Transfer Received From John Maina 254711223344 Completed 12,500.00 Balance 15,200.00
2026-01-12 14:22:10 Paybill to Sportpesa 522522 Completed 3,000.00 Balance 12,200.00
2026-01-15 08:05:00 Fuliza Overdraft Repayment Interest Deducted Completed 1,200.00 Balance 11,000.00
2026-01-20 18:30:11 Paybill to Betika 290290 Completed 2,500.00 Balance 8,500.00
2026-01-25 11:10:00 Business Paybill From Supermarket Inflow Completed 45,000.00 Balance 53,500.00
TEXT;

    $metrics = $parser->analyzeTransactions($rawSampleText);

    expect($metrics['total_inflows'])->toBe('57500.00')
        ->and($metrics['total_outflows'])->toBe('6700.00')
        ->and($metrics['fuliza_count'])->toBe(1)
        ->and($metrics['gambling_count'])->toBe(2)
        ->and($metrics['monthly_average_inflow'])->toBe('19166.66');
});

test('parses statement upload record and updates borrower financial profile in database', function () {
    /** @var Borrower $borrower */
    $borrower = Borrower::create([
        'full_name' => 'Kiprono Koech',
        'id_number' => '28910293',
        'phone_number' => '254722998877',
        'credit_limit' => '0.00',
        'risk_band' => \App\Enums\RiskBand::C,
        'is_blacklisted' => false,
    ]);

    /** @var MpesaStatementUpload $upload */
    $upload = MpesaStatementUpload::create([
        'borrower_id' => $borrower->id,
        'file_path' => 'statements/test_statement.pdf',
        'pdf_password_hash' => bcrypt('28910293'),
        'parsing_status' => 'pending',
        'total_inflows_detected' => '0.00',
        'total_outflows_detected' => '0.00',
        'gambling_transaction_count' => 0,
        'fuliza_deduction_count' => 0,
    ]);

    /** @var MpesaStatementParserService $parser */
    $parser = $this->app->make(MpesaStatementParserService::class);

    $results = $parser->parseStatement($upload->id, '28910293');

    $this->assertDatabaseHas('mpesa_statement_uploads', [
        'id' => $upload->id,
        'parsing_status' => 'completed',
        'fuliza_deduction_count' => 3,
        'gambling_transaction_count' => 2,
    ]);

    $this->assertDatabaseHas('borrower_financial_profiles', [
        'borrower_id' => $borrower->id,
        'mpesa_average_monthly_inflow' => '50000.00',
    ]);
});