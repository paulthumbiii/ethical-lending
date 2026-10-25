<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('renders the page header with subtitle and actions', function (): void {
    $html = Blade::render('<x-page-header subtitle="All borrowers"><x-slot:actions>ACTIONS_HERE</x-slot:actions></x-page-header>');

    expect($html)->toContain('All borrowers')->toContain('ACTIONS_HERE');
});

it('renders the filter bar as a GET form with a reset link', function (): void {
    $html = Blade::render('<x-filter-bar action="/lender/borrowers"><input name="q"></x-filter-bar>');

    expect($html)->toContain('method="GET"')
        ->toContain('action="/lender/borrowers"')
        ->toContain('name="q"')
        ->toContain('Apply')
        ->toContain('Reset');
});

it('renders data table headings and rows', function (): void {
    $html = Blade::render('<x-data-table :headings="[\'Name\', \'Phone\']"><tr><td>Amina</td><td>254700000010</td></tr></x-data-table>');

    expect($html)->toContain('Name')->toContain('Phone')->toContain('Amina');
});

it('shows the empty state instead of a table when there are no rows', function (): void {
    $html = Blade::render('<x-data-table :headings="[\'Name\']" :is-empty="true" empty-title="No borrowers found" empty-text="Try clearing the filters." />');

    expect($html)->toContain('No borrowers found')
        ->toContain('Try clearing the filters.')
        ->not->toContain('<table');
});

it('marks the active tab and links tabs by query string', function (): void {
    $html = Blade::render('<x-tabs :tabs="[\'overview\' => \'Overview\', \'loans\' => \'Loans\']" active="loans" />');

    expect($html)->toContain('Overview')
        ->toContain('Loans')
        ->toContain('tab=loans')
        ->toContain('aria-current="page"');
});

it('renders the pager summary and hides it when empty', function (): void {
    $page = new LengthAwarePaginator(['a', 'b'], 5, 2, 1, ['path' => '/lender/borrowers']);
    $html = Blade::render('<x-pager :paginator="$p" />', ['p' => $page]);

    expect($html)->toContain('Showing 1 to 2 of 5')->toContain('Next');

    $none = new LengthAwarePaginator([], 0, 2, 1, ['path' => '/lender/borrowers']);

    expect(trim(Blade::render('<x-pager :paginator="$p" />', ['p' => $none])))->toBe('');
});

it('shows the session status message in a toast and stays silent otherwise', function (): void {
    expect(trim(Blade::render('<x-toast />')))->toBe('');

    $this->withSession(['status' => 'Saved successfully']);

    expect(Blade::render('<x-toast />'))->toContain('Saved successfully');
});

it('turns status strings into readable coloured badges', function (): void {
    $good = Blade::render('<x-status-badge status="disbursed" />');
    $bad = Blade::render('<x-status-badge status="written_off" />');
    $wait = Blade::render('<x-status-badge status="approved_pending_checker" />');

    expect($good)->toContain('Disbursed')->toContain('emerald')
        ->and($bad)->toContain('Written Off')->toContain('rose')
        ->and($wait)->toContain('Approved Pending Checker')->toContain('amber');
});