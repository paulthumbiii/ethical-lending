<?php

declare(strict_types=1);

namespace App\Http\Controllers\Borrower;

use App\Http\Controllers\Controller;
use App\Services\Customer\BorrowerPortalService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PortalController extends Controller
{
    public function __construct(private readonly BorrowerPortalService $portal)
    {
    }

    public function show(Request $request): View
    {
        $borrower = $request->user()?->borrower;

        return view('borrower.dashboard', [
            'borrower' => $borrower,
            'portal'   => $borrower !== null ? $this->portal->summary($borrower) : null,
        ]);
    }
}