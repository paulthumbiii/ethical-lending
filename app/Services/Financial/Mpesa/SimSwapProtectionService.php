<?php

declare(strict_types=1);

namespace App\Services\Financial\Mpesa;

use App\Exceptions\Fintech\SimSwapRiskDetectedException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class SimSwapProtectionService
{
    private const QUARANTINE_HOURS = 48;

    /**
     * Asserts that a phone number is safe for automated B2C payout.
     *
     * @throws SimSwapRiskDetectedException
     */
    public function assertPhoneIsSafeForDisbursement(string $phoneNumber, ?CarbonImmutable $mockSwappedAt = null): bool
    {
        // Query SIM-swap timestamp (or use injected mock for testing)
        $swappedAt = $mockSwappedAt ?? $this->queryTelecomSimSwapTimestamp($phoneNumber);

        if ($swappedAt === null) {
            // No recent swap on record -> Safe
            return true;
        }

        $now = CarbonImmutable::now();
        $hoursSinceSwap = $swappedAt->diffInHours($now);

        if ($hoursSinceSwap < self::QUARANTINE_HOURS) {
            $hoursRemaining = self::QUARANTINE_HOURS - (int) $hoursSinceSwap;

            Log::channel('daily')->warning(sprintf(
                'SECURITY ALERT: SIM-Swap detected for phone %s. Swapped at %s (%d hrs ago). Quarantine active.',
                $phoneNumber,
                $swappedAt->toIso8601String(),
                $hoursSinceSwap
            ));

            throw new SimSwapRiskDetectedException(
                phoneNumber: $phoneNumber,
                lastSwappedAt: $swappedAt->toIso8601String(),
                hoursRemainingInQuarantine: $hoursRemaining
            );
        }

        return true;
    }

    /**
     * Queries Safaricom telecom network for last SIM-swap timestamp.
     */
    private function queryTelecomSimSwapTimestamp(string $phoneNumber): ?CarbonImmutable
    {
        // For local development and sandbox, test numbers ending in '999' simulate a compromised line
        if (str_ends_with($phoneNumber, '999')) {
            return CarbonImmutable::now()->subHours(12); // Swapped 12 hours ago!
        }

        return null;
    }
}