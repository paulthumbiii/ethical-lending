<?php

declare(strict_types=1);

use App\Models\MakerCheckerRequest;
use App\Models\User;
use App\Services\Governance\MakerCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function mciUser(string $phone): User
{
    return User::create([
        'name'         => 'Integrity ' . $phone,
        'email'        => 'integrity.' . $phone . '@ethical-lending.test',
        'password'     => 'Demo@12345',
        'phone_number' => $phone,
        'is_active'    => true,
    ]);
}

function mciRequest(User $maker): MakerCheckerRequest
{
    return app(MakerCheckerService::class)->createPendingRequest(
        $maker->id,
        'WAIVE_INTEREST',
        'App\\Models\\Loan',
        1,
        ['reason' => 'Hardship case']
    );
}

it('writes an audit row when a request is rejected', function () {
    $maker = mciUser('254700000301');
    $checker = mciUser('254700000302');
    $request = mciRequest($maker);

    app(MakerCheckerService::class)->rejectRequest($request->id, $checker->id, 'Amount does not match the hardship letter');

    $row = DB::table('audit_trails')->where('action', 'MAKER_CHECKER_REJECTED')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->user_id)->toBe($checker->id)
        ->and((int) $row->auditable_id)->toBe($request->id);

    $new = json_decode((string) $row->new_values, true);

    expect($new['status'])->toBe('rejected')
        ->and($new['reason'])->toBe('Amount does not match the hardship letter')
        ->and($new['action_type'])->toBe('WAIVE_INTEREST');
});

it('refuses a maker rejecting their own request and writes no audit row', function () {
    $maker = mciUser('254700000303');
    $request = mciRequest($maker);

    expect(fn () => app(MakerCheckerService::class)->rejectRequest($request->id, $maker->id, 'Changed my mind'))
        ->toThrow(RuntimeException::class);

    expect($request->fresh()->status)->toBe('pending')
        ->and(DB::table('audit_trails')->where('action', 'MAKER_CHECKER_REJECTED')->count())->toBe(0);
});

it('refuses a maker approving their own request', function () {
    $maker = mciUser('254700000304');
    $request = mciRequest($maker);

    expect(fn () => app(MakerCheckerService::class)->authorizeRequest($request->id, $maker->id))
        ->toThrow(RuntimeException::class);

    expect($request->fresh()->status)->toBe('pending');
});

it('does not let a rejected request be approved afterwards', function () {
    $maker = mciUser('254700000305');
    $checker = mciUser('254700000306');
    $request = mciRequest($maker);
    $service = app(MakerCheckerService::class);

    $service->rejectRequest($request->id, $checker->id, 'Not supported by documents');

    expect(fn () => $service->authorizeRequest($request->id, $checker->id))
        ->toThrow(RuntimeException::class);

    expect($request->fresh()->status)->toBe('rejected');
});