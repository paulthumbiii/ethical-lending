<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class GenerateCoreBankingModels extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'banking:generate-models';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generates and populates all 48 core banking Eloquent models and PHP 8.2 Backed Enums automatically';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting automated model and enum generation for Ethical Lending Core Banking...');

        $enumsDir = app_path('Enums');
        $modelsDir = app_path('Models');

        if (!File::exists($enumsDir)) {
            File::makeDirectory($enumsDir, 0755, true);
        }

        if (!File::exists($modelsDir)) {
            File::makeDirectory($modelsDir, 0755, true);
        }

        $this->generateEnums($enumsDir);
        $this->generateModels($modelsDir);

        $this->newLine();
        $this->info('Successfully generated and populated all Core Banking PHP 8.2 Backed Enums and 48 Eloquent Models!');

        return Command::SUCCESS;
    }

    private function generateEnums(string $dir): void
    {
        $enums = [
            'LoanStatus.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Enums;

enum LoanStatus: string
{
    case PENDING = 'pending';
    case APPROVAL_HOLD = 'approval_hold';
    case DISBURSED = 'disbursed';
    case RESTRUCTURED = 'restructured';
    case DEFAULTED = 'defaulted';
    case SETTLED = 'settled';
    case WRITTEN_OFF = 'written_off';
}
PHP,
            'RiskBand.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Enums;

enum RiskBand: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
}
PHP,
            'VerificationStatus.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Enums;

enum VerificationStatus: string
{
    case PENDING = 'pending';
    case VERIFIED = 'verified';
    case REJECTED = 'rejected';
}
PHP,
        ];

        foreach ($enums as $fileName => $content) {
            File::put("{$dir}/{$fileName}", $content);
            $this->line("Created Enum: App\\Enums\\" . pathinfo($fileName, PATHINFO_FILENAME));
        }
    }

    private function generateModels(string $dir): void
    {
        $models = [
            'Borrower.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RiskBand;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Borrower extends Model
{
    use HasFactory;

    protected $fillable = [
        'full_name',
        'id_number',
        'kra_pin',
        'phone_number',
        'email',
        'credit_limit',
        'risk_band',
        'is_blacklisted',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit'   => 'decimal:2',
            'risk_band'      => RiskBand::class,
            'is_blacklisted' => 'boolean',
        ];
    }

    public function financialProfile(): HasOne
    {
        return $this->hasOne(BorrowerFinancialProfile::class, 'borrower_id');
    }

    public function kycDocuments(): HasMany
    {
        return $this->hasMany(BorrowerKycDocument::class, 'borrower_id');
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(Guarantor::class, 'borrower_id');
    }

    public function crbInquiries(): HasMany
    {
        return $this->hasMany(CrbInquiryRecord::class, 'borrower_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'borrower_id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(DataProtectionConsent::class, 'borrower_id');
    }

    public function optOuts(): HasMany
    {
        return $this->hasMany(BorrowerOptOut::class, 'borrower_id');
    }
}
PHP,
            'BorrowerKycDocument.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BorrowerKycDocument extends Model
{
    protected $fillable = [
        'borrower_id',
        'document_type',
        'file_path',
        'verification_status',
        'verified_by_user_id',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'verification_status' => VerificationStatus::class,
            'verified_at'         => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
PHP,
            'BorrowerFinancialProfile.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BorrowerFinancialProfile extends Model
{
    protected $fillable = [
        'borrower_id',
        'monthly_income',
        'monthly_expenses',
        'mpesa_average_monthly_inflow',
        'existing_debt_obligations',
    ];

    protected function casts(): array
    {
        return [
            'monthly_income'               => 'decimal:2',
            'monthly_expenses'              => 'decimal:2',
            'mpesa_average_monthly_inflow' => 'decimal:2',
            'existing_debt_obligations'    => 'decimal:2',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}
PHP,
            'Guarantor.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Guarantor extends Model
{
    protected $fillable = [
        'borrower_id',
        'full_name',
        'id_number',
        'phone_number',
        'guaranteed_amount',
        'verification_token',
        'status',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'guaranteed_amount' => 'decimal:2',
            'signed_at'         => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}
PHP,
            'CrbInquiryRecord.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CrbInquiryRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'borrower_id',
        'bureau_name',
        'credit_score',
        'open_accounts_count',
        'defaulted_accounts_count',
        'total_npl_amount',
        'inquiry_reference',
    ];

    protected function casts(): array
    {
        return [
            'total_npl_amount' => 'decimal:2',
            'created_at'       => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}
PHP,
            'LoanProduct.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LoanProduct extends Model
{
    protected $fillable = [
        'name',
        'product_code',
        'min_principal',
        'max_principal',
        'default_tenure_days',
        'interest_calculation_method',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_principal' => 'decimal:2',
            'max_principal' => 'decimal:2',
            'is_active'     => 'boolean',
        ];
    }

    public function interestRules(): HasMany
    {
        return $this->hasMany(ProductInterestRule::class, 'loan_product_id');
    }

    public function feeMatrices(): HasMany
    {
        return $this->hasMany(FeeMatrix::class, 'loan_product_id');
    }

    public function penaltyPolicies(): HasMany
    {
        return $this->hasMany(PenaltyPolicy::class, 'loan_product_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'loan_product_id');
    }
}
PHP,
            'ProductInterestRule.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductInterestRule extends Model
{
    protected $fillable = [
        'loan_product_id',
        'tier_name',
        'annual_rate',
        'monthly_rate',
        'compounding_frequency',
    ];

    protected function casts(): array
    {
        return [
            'annual_rate'  => 'decimal:2',
            'monthly_rate' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }
}
PHP,
            'FeeMatrix.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FeeMatrix extends Model
{
    protected $table = 'fee_matrices';

    protected $fillable = [
        'loan_product_id',
        'fee_name',
        'calculation_type',
        'fee_amount',
        'fee_percentage',
        'is_excise_duty_applicable',
    ];

    protected function casts(): array
    {
        return [
            'fee_amount'                 => 'decimal:2',
            'fee_percentage'             => 'decimal:2',
            'is_excise_duty_applicable' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }
}
PHP,
            'PenaltyPolicy.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PenaltyPolicy extends Model
{
    protected $fillable = [
        'loan_product_id',
        'grace_period_days',
        'penalty_rate_daily',
        'fixed_late_fee',
    ];

    protected function casts(): array
    {
        return [
            'penalty_rate_daily' => 'decimal:2',
            'fixed_late_fee'     => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }
}
PHP,
            'LoanApplication.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class LoanApplication extends Model
{
    protected $fillable = [
        'application_number',
        'borrower_id',
        'loan_product_id',
        'requested_principal',
        'requested_tenure_days',
        'status',
        'underwriter_id',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_principal' => 'decimal:2',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function underwriter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'underwriter_id');
    }

    public function ruleEvaluations(): HasMany
    {
        return $this->hasMany(CreditRuleEvaluation::class, 'loan_application_id');
    }

    public function loan(): HasOne
    {
        return $this->hasOne(Loan::class, 'loan_application_id');
    }
}
PHP,
            'CreditRuleEvaluation.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CreditRuleEvaluation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'loan_application_id',
        'rule_identifier',
        'rule_description',
        'status',
        'actual_value',
        'threshold_value',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }
}
PHP,
            'Loan.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Loan extends Model
{
    protected $fillable = [
        'loan_number',
        'loan_application_id',
        'borrower_id',
        'loan_product_id',
        'principal_disbursed',
        'annual_interest_rate',
        'tenure_days',
        'status',
        'disbursed_at',
        'due_date',
        'defaulted_at',
        'is_in_duplum_locked',
    ];

    protected function casts(): array
    {
        return [
            'principal_disbursed'  => 'decimal:2',
            'annual_interest_rate' => 'decimal:2',
            'status'               => LoanStatus::class,
            'is_in_duplum_locked'  => 'boolean',
            'disbursed_at'         => 'datetime',
            'due_date'             => 'datetime',
            'defaulted_at'         => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LoanLedgerEntry::class, 'loan_id');
    }

    public function inDuplumTracking(): HasOne
    {
        return $this->hasOne(InDuplumTracking::class, 'loan_id');
    }

    public function amortizationSchedules(): HasMany
    {
        return $this->hasMany(AmortizationSchedule::class, 'loan_id');
    }

    public function restructuringProposals(): HasMany
    {
        return $this->hasMany(RestructuringProposal::class, 'loan_id');
    }

    public function collectionCase(): HasOne
    {
        return $this->hasOne(CollectionCase::class, 'loan_id');
    }

    public function legalContract(): HasOne
    {
        return $this->hasOne(LoanLegalContract::class, 'loan_id');
    }

    public function writeOff(): HasOne
    {
        return $this->hasOne(LoanWriteOff::class, 'loan_id');
    }
}
PHP,
            'AmortizationSchedule.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AmortizationSchedule extends Model
{
    protected $fillable = [
        'loan_id',
        'installment_number',
        'due_date',
        'principal_due',
        'interest_due',
        'fees_due',
        'total_installment_due',
        'principal_paid',
        'interest_paid',
        'fees_paid',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'principal_due'         => 'decimal:2',
            'interest_due'          => 'decimal:2',
            'fees_due'              => 'decimal:2',
            'total_installment_due' => 'decimal:2',
            'principal_paid'        => 'decimal:2',
            'interest_paid'         => 'decimal:2',
            'fees_paid'             => 'decimal:2',
            'due_date'              => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}
PHP,
            'LoanLedgerEntry.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LoanLedgerEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'loan_id',
        'entry_type',
        'debit',
        'credit',
        'running_principal_balance',
        'running_interest_balance',
        'running_fees_balance',
        'total_running_balance',
        'reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'debit'                     => 'decimal:2',
            'credit'                    => 'decimal:2',
            'running_principal_balance' => 'decimal:2',
            'running_interest_balance'  => 'decimal:2',
            'running_fees_balance'      => 'decimal:2',
            'total_running_balance'     => 'decimal:2',
            'created_at'                => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}
PHP,
            'InDuplumTracking.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InDuplumTracking extends Model
{
    protected $table = 'in_duplum_tracking';

    protected $fillable = [
        'loan_id',
        'defaulted_at',
        'default_principal_snapshot',
        'total_post_default_interest',
        'total_post_default_penalties',
        'statutory_cap_limit',
        'is_frozen',
        'frozen_at',
    ];

    protected function casts(): array
    {
        return [
            'default_principal_snapshot'  => 'decimal:2',
            'total_post_default_interest' => 'decimal:2',
            'total_post_default_penalties'=> 'decimal:2',
            'statutory_cap_limit'         => 'decimal:2',
            'is_frozen'                   => 'boolean',
            'defaulted_at'                => 'datetime',
            'frozen_at'                   => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}
PHP,
            'RestructuringProposal.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RestructuringProposal extends Model
{
    protected $fillable = [
        'loan_id',
        'proposal_code',
        'status',
        'forgiven_interest',
        'forgiven_penalties',
        'capitalized_principal',
        'new_interest_rate',
        'tenure_extension_days',
        'repayment_ratio_snapshot',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'forgiven_interest'        => 'decimal:2',
            'forgiven_penalties'       => 'decimal:2',
            'capitalized_principal'    => 'decimal:2',
            'new_interest_rate'        => 'decimal:2',
            'repayment_ratio_snapshot' => 'decimal:2',
            'expires_at'               => 'datetime',
            'accepted_at'              => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(RestructuringSchedule::class, 'restructuring_proposal_id');
    }
}
PHP,
            'RestructuringSchedule.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RestructuringSchedule extends Model
{
    protected $fillable = [
        'restructuring_proposal_id',
        'installment_number',
        'due_date',
        'installment_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'installment_amount' => 'decimal:2',
            'due_date'           => 'date',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(RestructuringProposal::class, 'restructuring_proposal_id');
    }
}
PHP,
            'ChartOfAccount.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ChartOfAccount extends Model
{
    protected $fillable = [
        'account_code',
        'account_name',
        'account_type',
        'currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function journalItems(): HasMany
    {
        return $this->hasMany(JournalItem::class, 'chart_of_account_id');
    }
}
PHP,
            'AccountingPeriod.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AccountingPeriod extends Model
{
    protected $fillable = [
        'period_name',
        'start_date',
        'end_date',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'is_locked'  => 'boolean',
            'start_date' => 'date',
            'end_date'   => 'date',
        ];
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'accounting_period_id');
    }
}
PHP,
            'JournalEntry.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class JournalEntry extends Model
{
    protected $fillable = [
        'entry_number',
        'accounting_period_id',
        'reference_type',
        'reference_id',
        'description',
        'posted_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(JournalItem::class, 'journal_entry_id');
    }
}
PHP,
            'JournalItem.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class JournalItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'journal_entry_id',
        'chart_of_account_id',
        'debit',
        'credit',
    ];

    protected function casts(): array
    {
        return [
            'debit'      => 'decimal:2',
            'credit'     => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }
}
PHP,
            'TaxWithholdingLedger.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TaxWithholdingLedger extends Model
{
    protected $fillable = [
        'loan_id',
        'fee_name',
        'fee_charged',
        'excise_rate',
        'excise_duty_amount',
        'remittance_status',
        'remitted_at',
    ];

    protected function casts(): array
    {
        return [
            'fee_charged'        => 'decimal:2',
            'excise_rate'        => 'decimal:2',
            'excise_duty_amount' => 'decimal:2',
            'remitted_at'        => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}
PHP,
            'MpesaApiToken.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MpesaApiToken extends Model
{
    protected $fillable = [
        'access_token',
        'expires_in',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
PHP,
            'MpesaB2cDisbursement.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MpesaB2cDisbursement extends Model
{
    protected $table = 'mpesa_b2c_disbursements';

    protected $fillable = [
        'loan_id',
        'originator_conversation_id',
        'conversation_id',
        'transaction_id',
        'recipient_phone',
        'amount',
        'status',
        'response_code',
        'response_description',
        'disbursed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'disbursed_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}
PHP,
            'MpesaC2bCallback.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MpesaC2bCallback extends Model
{
    public $timestamps = false;

    protected $table = 'mpesa_c2b_callbacks';

    protected $fillable = [
        'transaction_type',
        'trans_id',
        'trans_time',
        'trans_amount',
        'business_short_code',
        'bill_ref_number',
        'msisdn',
        'first_name',
        'is_reconciled',
    ];

    protected function casts(): array
    {
        return [
            'trans_amount'  => 'decimal:2',
            'is_reconciled' => 'boolean',
            'created_at'    => 'datetime',
        ];
    }
}
PHP,
            'MpesaStkRequest.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MpesaStkRequest extends Model
{
    protected $fillable = [
        'loan_id',
        'merchant_request_id',
        'checkout_request_id',
        'phone_number',
        'amount',
        'mpesa_receipt_number',
        'result_code',
        'result_desc',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}
PHP,
            'ReconciliationBatch.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ReconciliationBatch extends Model
{
    protected $fillable = [
        'batch_date',
        'total_mpesa_records',
        'total_system_records',
        'matched_records',
        'unreconciled_discrepancies',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'batch_date' => 'date',
        ];
    }
}
PHP,
            'CollectionCase.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CollectionCase extends Model
{
    protected $fillable = [
        'loan_id',
        'borrower_id',
        'dpd_bucket',
        'assigned_agent_user_id',
        'case_status',
        'last_contacted_at',
    ];

    protected function casts(): array
    {
        return [
            'last_contacted_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_user_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(CollectionAction::class, 'collection_case_id');
    }

    public function promisesToPay(): HasMany
    {
        return $this->hasMany(PromiseToPay::class, 'collection_case_id');
    }
}
PHP,
            'CollectionAction.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CollectionAction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'collection_case_id',
        'performed_by_user_id',
        'action_type',
        'message_payload',
        'is_compliant_hours',
        'gateway_status',
    ];

    protected function casts(): array
    {
        return [
            'is_compliant_hours' => 'boolean',
            'created_at'         => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(CollectionCase::class, 'collection_case_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
PHP,
            'RegulatoryCooldownLock.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RegulatoryCooldownLock extends Model
{
    protected $fillable = [
        'borrower_id',
        'lock_type',
        'locked_until',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'locked_until' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}
PHP,
            'PromiseToPay.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PromiseToPay extends Model
{
    protected $table = 'promise_to_pays';

    protected $fillable = [
        'collection_case_id',
        'promised_date',
        'promised_amount',
        'status',
        'honored_at',
    ];

    protected function casts(): array
    {
        return [
            'promised_amount' => 'decimal:2',
            'promised_date'   => 'date',
            'honored_at'      => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(CollectionCase::class, 'collection_case_id');
    }
}
PHP,
            'DataProtectionConsent.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DataProtectionConsent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'borrower_id',
        'consent_type',
        'is_granted',
        'ip_address',
        'user_agent',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_granted' => 'boolean',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}
PHP,
            'Ifrs9LoanStage.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Ifrs9LoanStage extends Model
{
    protected $fillable = [
        'loan_id',
        'current_stage',
        'previous_stage',
        'sicr_flag',
        'transition_reason',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'sicr_flag'    => 'boolean',
            'evaluated_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}
PHP,
            'EclProvision.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EclProvision extends Model
{
    protected $fillable = [
        'loan_id',
        'reporting_date',
        'exposure_at_default',
        'probability_of_default',
        'loss_given_default',
        'calculated_ecl',
    ];

    protected function casts(): array
    {
        return [
            'exposure_at_default'    => 'decimal:2',
            'probability_of_default' => 'decimal:4',
            'loss_given_default'     => 'decimal:4',
            'calculated_ecl'         => 'decimal:2',
            'reporting_date'         => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}
PHP,
            'CrbSubmissionLog.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CrbSubmissionLog extends Model
{
    protected $fillable = [
        'batch_month',
        'bureau_name',
        'total_records_submitted',
        'performing_records',
        'defaulted_records',
        'file_path',
        'status',
    ];
}
PHP,
            'MakerCheckerRequest.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MakerCheckerRequest extends Model
{
    protected $fillable = [
        'maker_user_id',
        'action_type',
        'target_entity_type',
        'target_entity_id',
        'payload',
        'status',
        'checker_user_id',
        'checker_notes',
        'actioned_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'     => 'array',
            'actioned_at' => 'datetime',
        ];
    }

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_user_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_user_id');
    }
}
PHP,
            'AuditTrail.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuditTrail extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
PHP,
            'MpesaStatementUpload.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MpesaStatementUpload extends Model
{
    protected $fillable = [
        'borrower_id',
        'file_path',
        'pdf_password_hash',
        'parsing_status',
        'total_inflows_detected',
        'total_outflows_detected',
        'gambling_transaction_count',
        'fuliza_deduction_count',
        'parsed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_inflows_detected'  => 'decimal:2',
            'total_outflows_detected' => 'decimal:2',
            'parsed_at'               => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}
PHP,
            'UssdSession.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UssdSession extends Model
{
    protected $fillable = [
        'session_id',
        'phone_number',
        'service_code',
        'current_menu_node',
        'session_data',
    ];

    protected function casts(): array
    {
        return [
            'session_data' => 'array',
        ];
    }
}
PHP,
            'OutboxEvent.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OutboxEvent extends Model
{
    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'event_name',
        'payload',
        'previous_event_hash',
        'current_event_hash',
        'is_published',
        'published_at',
        'retry_count',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}
PHP,
            'InvestorFacility.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class InvestorFacility extends Model
{
    protected $fillable = [
        'investor_name',
        'facility_code',
        'total_facility_amount',
        'drawn_amount',
        'annual_cost_of_funds',
        'start_date',
        'maturity_date',
    ];

    protected function casts(): array
    {
        return [
            'total_facility_amount' => 'decimal:2',
            'drawn_amount'          => 'decimal:2',
            'annual_cost_of_funds'  => 'decimal:2',
            'start_date'            => 'date',
            'maturity_date'         => 'date',
        ];
    }
}
PHP,
            'LoanLegalContract.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LoanLegalContract extends Model
{
    protected $fillable = [
        'loan_id',
        'contract_pdf_path',
        'contract_sha256_hash',
        'digital_signature_timestamp',
        'qr_verification_code',
    ];

    protected function casts(): array
    {
        return [
            'digital_signature_timestamp' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}
PHP,
            'LoanWriteOff.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LoanWriteOff extends Model
{
    protected $fillable = [
        'loan_id',
        'written_off_principal',
        'written_off_interest',
        'written_off_fees',
        'total_written_off',
        'write_off_reason',
        'approved_by_user_id',
        'written_off_at',
        'post_write_off_recovered_amount',
    ];

    protected function casts(): array
    {
        return [
            'written_off_principal'           => 'decimal:2',
            'written_off_interest'            => 'decimal:2',
            'written_off_fees'                => 'decimal:2',
            'total_written_off'               => 'decimal:2',
            'post_write_off_recovered_amount' => 'decimal:2',
            'written_off_at'                  => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
PHP,
            'LedgerAdjustment.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LedgerAdjustment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'loan_id',
        'adjustment_type',
        'amount',
        'debit_account_code',
        'credit_account_code',
        'reason',
        'authorized_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }
}
PHP,
            'WebhookIdempotencyKey.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WebhookIdempotencyKey extends Model
{
    protected $fillable = [
        'idempotency_key',
        'source_provider',
        'status',
        'response_payload',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'locked_until'     => 'datetime',
        ];
    }
}
PHP,
            'BorrowerOptOut.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BorrowerOptOut extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'borrower_id',
        'channel',
        'opt_out_reason',
        'opted_out_at',
    ];

    protected function casts(): array
    {
        return [
            'opted_out_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }
}
PHP,
            'CrbDisputeLock.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CrbDisputeLock extends Model
{
    protected $fillable = [
        'loan_id',
        'dispute_reference',
        'bureau_name',
        'dispute_reason',
        'status',
        'lock_expires_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'lock_expires_at' => 'datetime',
            'resolved_at'      => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}
PHP,
        ];

        foreach ($models as $fileName => $content) {
            File::put("{$dir}/{$fileName}", $content);
            $this->line("Created Model: App\\Models\\" . pathinfo($fileName, PATHINFO_FILENAME));
        }
    }
}