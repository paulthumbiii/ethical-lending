<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Throwable;

final class RecordAuthActivity
{
    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->write('LOGIN_SUCCEEDED', $user->id, User::class, (int) $user->id, [
            'guard' => $event->guard,
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        $typed = (string) (
            $event->credentials['email']
            ?? $event->credentials['login']
            ?? $event->credentials['phone_number']
            ?? ''
        );

        $this->write(
            'LOGIN_FAILED',
            $user?->id,
            $user ? User::class : 'auth_attempt',
            $user ? (int) $user->id : 0,
            [
                'guard' => $event->guard,
                'identifier' => self::maskIdentifier($typed),
            ]
        );
    }

    public function handleLogout(Logout $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->write('LOGOUT', $user->id, User::class, (int) $user->id, [
            'guard' => $event->guard,
        ]);
    }

    public static function maskIdentifier(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '(empty)';
        }

        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);

            return mb_substr($local, 0, 1) . '***@' . $domain;
        }

        $length = mb_strlen($value);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return mb_substr($value, 0, 2)
            . str_repeat('*', $length - 4)
            . mb_substr($value, -2);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function write(
        string $action,
        int|string|null $userId,
        string $auditableType,
        int $auditableId,
        array $details
    ): void {
        try {
            $request = request();

            AuditTrail::create([
                'user_id' => $userId !== null ? (int) $userId : null,
                'action' => $action,
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'old_values' => null,
                'new_values' => $details,
                'ip_address' => substr((string) ($request->ip() ?? 'cli'), 0, 45),
                'user_agent' => substr((string) ($request->userAgent() ?? 'unknown'), 0, 255),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}