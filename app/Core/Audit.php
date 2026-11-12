<?php
declare(strict_types=1);

namespace App\Core;

/** Writes "who did what" to audit_logs (RA 10173 accountability). */
final class Audit
{
    public static function log(string $action, ?string $entity = null, ?int $entityId = null, array|string|null $details = null): void
    {
        if (is_array($details)) {
            $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        DB::insert('audit_logs', [
            'user_id'    => Auth::id(),
            'action'     => mb_substr($action, 0, 100),
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'details'    => $details,
            'ip'         => client_ip(),
            'created_at' => Clock::now(),
        ]);
    }
}
