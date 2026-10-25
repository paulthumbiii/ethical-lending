<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lender;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuditLogRequest;
use App\Models\AuditTrail;
use Illuminate\Contracts\View\View;

/**
 * Read-only audit log. Old/new values and user agents are deliberately
 * never selected: they can hold personal data.
 */
final class AuditLogController extends Controller
{
    public function index(AuditLogRequest $request): View
    {
        $action = $request->validated('action');
        $user   = $request->validated('user');
        $entity = $request->validated('entity');
        $from   = $request->validated('from');
        $to     = $request->validated('to');

        $rows = AuditTrail::query()
            ->with('user:id,name,email')
            ->select(['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'ip_address', 'created_at'])
            ->when($action, fn ($q) => $q->where('action', $action))
            ->when($entity, fn ($q) => $q->where('auditable_type', $entity))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->when($user, fn ($q) => $q->whereHas('user', fn ($u) => $u
                ->where('name', 'like', '%' . $user . '%')
                ->orWhere('email', 'like', '%' . $user . '%')))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('lender.audit.index', [
            'rows'     => $rows,
            'actions'  => AuditTrail::query()->distinct()->orderBy('action')->pluck('action'),
            'entities' => AuditTrail::query()->distinct()->orderBy('auditable_type')->pluck('auditable_type'),
            'filters'  => compact('action', 'user', 'entity', 'from', 'to'),
        ]);
    }
}