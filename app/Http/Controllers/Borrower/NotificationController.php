<?php

declare(strict_types=1);

namespace App\Http\Controllers\Borrower;

use App\Http\Controllers\Controller;
use App\Models\Borrower;
use App\Models\BorrowerNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $borrower = $request->user()?->borrower;

        $items = $borrower instanceof Borrower
            ? BorrowerNotification::query()
                ->where('borrower_id', $borrower->id)
                ->latest('id')
                ->limit(50)
                ->get()
            : collect();

        return view('borrower.notifications', [
            'borrower' => $borrower,
            'items'    => $items,
        ]);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $borrower = $request->user()?->borrower;

        if ($borrower instanceof Borrower) {
            BorrowerNotification::query()
                ->where('borrower_id', $borrower->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return redirect()->route('borrower.notifications');
    }
}