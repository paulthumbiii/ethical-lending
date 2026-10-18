<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

final class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;

    /** @var array<int, string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'is_active',
    ];

    /** @var array<int, string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function isBorrower(): bool
    {
        return $this->hasRole(UserRole::Borrower->value);
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(UserRole::staff());
    }

    public function borrower(): HasOne
    {
        return $this->hasOne(Borrower::class, 'user_id');
    }

    public function makerRequests(): HasMany
    {
        return $this->hasMany(MakerCheckerRequest::class, 'maker_user_id');
    }

    public function checkerRequests(): HasMany
    {
        return $this->hasMany(MakerCheckerRequest::class, 'checker_user_id');
    }

    public function auditTrails(): HasMany
    {
        return $this->hasMany(AuditTrail::class, 'user_id');
    }
}