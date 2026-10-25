<?php

declare(strict_types=1);

namespace App\Enums;

enum StaffPermission: string
{
    case BorrowersView = 'borrowers.view';
    case LoansView = 'loans.view';
    case LedgerView = 'ledger.view';
    case FinanceView = 'finance.view';
    case AuditView = 'audit.view';
    case ReportsView = 'reports.view';
    case StatementsView = 'statements.view';
    case CrbManage = 'crb.manage';
    case DsrManage = 'dsr.manage';

    /**
     * Permissions each role holds. Borrowers hold none: they never enter the staff area.
     *
     * @return list<string>
     */
    public static function valuesFor(UserRole $role): array
    {
        $cases = match ($role) {
            UserRole::LenderAdmin => self::cases(),
            UserRole::CreditOfficer => [self::BorrowersView, self::LoansView, self::StatementsView],
            UserRole::CollectionsOfficer => [self::BorrowersView, self::LoansView],
            UserRole::FinanceOfficer => [
                self::BorrowersView, self::LoansView, self::LedgerView, self::FinanceView, self::ReportsView,
            ],
            UserRole::ComplianceOfficer => [
                self::BorrowersView, self::LoansView, self::AuditView, self::ReportsView,
                self::CrbManage, self::DsrManage,
            ],
            UserRole::Auditor => [
                self::BorrowersView, self::LoansView, self::LedgerView, self::FinanceView,
                self::AuditView, self::ReportsView,
            ],
            UserRole::SupportAgent => [self::BorrowersView, self::LoansView],
            UserRole::Borrower => [],
        };

        return array_map(static fn (self $p): string => $p->value, $cases);
    }
}