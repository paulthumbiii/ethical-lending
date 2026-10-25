# Ethical Lending

A loan management system for a Kenyan digital lender, built as a skill-learning project. Theme: **lending that shows its working**. Every charge is explained to the borrower, and the lender's staff follow rules that are enforced in code, not just written in a policy.

It has two front ends: a desktop dashboard for lender staff and a mobile-first portal for borrowers.

## The loan journey (all working in the browser)

1. Borrower registers (with data-protection consent) and applies for a loan.
2. Borrower uploads an M-Pesa statement (PDF) with a consent tick box.
3. A credit officer reviews the statement and accepts or rejects it (all views are audited).
4. The credit check runs four hard rules (blacklist, CRB defaults, debt-to-income, M-Pesa inflow).
5. A **second person** approves in the Review Desk (maker-checker). Nobody approves their own request.
6. A finance officer or admin sends the money (M-Pesa B2C, with a double-send guard and a SIM-swap guard).
7. When the payout lands, the 5% processing fee is added to the balance, split 80% revenue and 20% excise in the general ledger.
8. The borrower sees what they were approved for, what they receive, what they owe, and can repay by STK Push.

The borrower timeline tells them in plain English where they are, including when they are approved for less than they asked.

## What is built

- **Append-only loan ledger.** Balances always come from the latest ledger row. Nothing is updated in place.
- **Double-entry general ledger** with an excise-duty split and accounting periods.
- **Payment waterfall:** fees, then interest, then principal, then overpayment credit, under row locks.
- **In-duplum engine:** after default, interest, penalties and collection fees can never exceed the principal at default, and charges then freeze.
- **Fair collections:** outreach only 08:00 to 18:00 EAT, a 48-hour cooldown, no contacting third parties, and a promise to pay pauses escalation. The workbench is read-only by design.
- **Restructuring:** relief for struggling borrowers that forgives penalties and keeps the agreed processing fee.
- **Maker-checker** governance and a **hash-chained outbox** of events.
- **Auth audit trail,** idempotent M-Pesa callbacks, USSD menu engine, IFRS 9 provisioning, CBK returns and CRB Metro 2 exports.

## Rules this project holds itself to

- Money is `DECIMAL(12,2)` in the database and BCMath strings in code. No floats in calculations.
- `declare(strict_types=1)`, backed enums, FormRequests, service classes.
- Transactions and `lockForUpdate()` wherever money or state moves.
- Documents (statements) are private, and only people who decide on them can open them.
- Software never decides forgery: automatic flags go to a human, who is named in the audit trail.

## Setup (Windows, XAMPP)

Requirements: PHP 8.2, Composer, Node, MySQL 8.

```
composer install
npm install
copy .env.example .env
php artisan key:generate
```

Create an empty MySQL database called `ethical_lending`, set `DB_*` in `.env`, then:

```
php artisan migrate
php artisan db:seed --class=ChartOfAccountSeeder
php artisan db:seed --class=DemoWalkthroughSeeder
npm run build
php artisan serve
```

Open http://127.0.0.1:8000.

### Demo accounts (local only, password `Demo@12345`)

| Who | Email | What to look at |
|---|---|---|
| Lender admin | admin@ethical-lending.test | Everything |
| Credit officer | credit@ethical-lending.test | Applications, statement review, credit check |
| Finance officer | finance@ethical-lending.test | Review Desk, Money out |
| Collections officer | collections@ethical-lending.test | Collections workbench |
| Borrower with a paid-out loan | njeri@ethical-lending.test | Balance including the fee |
| Borrower waiting for payout | otieno@ethical-lending.test | "Approved for less than you asked" |

Demo statements are database rows with no PDF behind them, so "Open PDF" does not work for the seeded ones. Upload a real PDF as a new borrower to see the full statement flow.

**Never run the demo seeders on a live database.** `DemoWalkthroughSeeder` refuses to run outside the local and testing environments.

## Tests

```
php artisan test
```

Tests run on SQLite in memory. Two lock tests need real MySQL and are skipped by default. A guard in `tests/TestCase.php` refuses to run against any database that is not in-memory or named `*_test`. It exists because an early test cleanup wiped a real database (see "Lessons").

## Money out without Safaricom

Payouts normally need Safaricom credentials and a public HTTPS callback URL. Locally, the **Simulate payout** button (local and testing environments only) completes a payout so the whole journey can be demonstrated with no M-Pesa setup. To try the Daraja sandbox, add your consumer key and secret to `.env` and expose the callback routes with a tunnel such as ngrok.

## Known limitations (read before trusting this with money)

**Proven:** the ledger, general ledger, in-duplum, maker-checker, audit trail, idempotency and the loan journey, by automated tests.

**Not proven or not built:**

- **Never tested against real Safaricom.** The B2C and STK code paths are tested with faked HTTP responses only.
- **Excise duty treatment is unverified.** The code treats the 20% excise as part of the 5% fee. Excise on fees is normally charged on top of the fee, and the legal rule needs checking before any real use.
- **B2C call inside a database transaction.** If the process dies after Safaricom accepts a request but before it is saved, money could go out with no record. There is no reconciliation job.
- **No screen to resolve a stuck payout** (a B2C timeout leaves it as "initiated" and blocks a second send, by design).
- **B2C and C2B callbacks are unauthenticated.** They need Safaricom IP allow-listing, and `config/mpesa.php` needs `initiator_name` and `security_credential` for real B2C.
- **Automatic statement checks are not built.** `MpesaStatementParserService` is not used by the upload flow and must not be trusted on real statements. Humans read the statements and type the verified inflow.
- **Statements have no retention or deletion policy and no malware scan.** The PDF check is a header check only.
- **Maker-checker executes only loan-application approvals.** Other action types are recorded and audited but do nothing yet.
- **A lender admin can both approve an application (as the second person) and send its money.**
- **Repayment split after restructuring is approximate.** Repayments clear fees first but do not record which fee they cleared, so the engine treats any repayment as clearing penalties first.
- **Not production-ready:** no queue worker, scheduler, backups or monitoring. There is no 2FA, password reset or session timeout for staff.
- **No operations pages yet** for staff to browse borrowers, ledgers or reports.

## Going live (rough checklist, verify against Safaricom's guide)

A CBK digital credit provider licence, Safaricom go-live approval, a real shortcode and initiator, production keys with `MPESA_ENV=production`, the B2C initiator credentials in config, public HTTPS callbacks with IP allow-listing, and the hardening items above.

## Lessons

- A test cleanup that listed tables through a Laravel schema helper truncated every table on the server, not just the test database, including other projects' databases. Always scope destructive code to the current schema and assert the database name first.
- AI-assisted code needs proof: save-and-verify checks (`findstr`), test counts, and distrust of any handoff document that disagrees with the disk.