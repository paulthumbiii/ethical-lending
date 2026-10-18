<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case LenderAdmin = 'lender_admin';
    case CreditOfficer = 'credit_officer';
    case CollectionsOfficer = 'collections_officer';
    case FinanceOfficer = 'finance_officer';
    case Borrower = 'borrower';

    /** @return list<string> */
    public static function staff(): array
    {
        return [
            self::LenderAdmin->value,
            self::CreditOfficer->value,
            self::CollectionsOfficer->value,
            self::FinanceOfficer->value,
        ];
    }

    /** Pipe-separated list, the format Spatie's role middleware expects. */
    public static function staffPipe(): string
    {
        return implode('|', self::staff());
    }

    /**
     * Roles allowed to approve or reject maker-checker requests.
     *
     * @return list<string>
     */
    public static function approvers(): array
    {
        return [
            self::LenderAdmin->value,
            self::FinanceOfficer->value,
        ];
    }

    public static function approversPipe(): string
    {
        return implode('|', self::approvers());
    }

    /**
     * Roles allowed to open the read-only collections workbench.
     *
     * @return list<string>
     */
    public static function collectionsViewers(): array
    {
        return [
            self::LenderAdmin->value,
            self::CollectionsOfficer->value,
            self::FinanceOfficer->value,
        ];
    }

    public static function collectionsViewersPipe(): string
    {
        return implode('|', self::collectionsViewers());
    }
}