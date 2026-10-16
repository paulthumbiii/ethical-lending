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