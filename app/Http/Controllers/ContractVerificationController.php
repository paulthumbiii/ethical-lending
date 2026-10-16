<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Servicing\ContractGeneratorService;
use Illuminate\Http\JsonResponse;

final class ContractVerificationController extends Controller
{
    public function __construct(private readonly ContractGeneratorService $contractService) {}

    /**
     * Public verification endpoint for QR codes embedded in loan contracts.
     */
    public function verify(string $code): JsonResponse
    {
        $verification = $this->contractService->verifyContract($code);

        if ($verification === null) {
            return response()->json([
                'is_valid' => false,
                'error'    => 'INVALID_OR_TAMPERED_CONTRACT: Verification code not found on official ledger.',
            ], 404);
        }

        return response()->json($verification, 200);
    }
}