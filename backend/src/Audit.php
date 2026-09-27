<?php
// The audit log: who did what, and when. Written, never edited.

declare(strict_types=1);

namespace Ismile;

final class Audit
{
    public static function log(?int $userId, string $action, ?string $targetType = null, ?int $targetId = null, array|string|null $details = null): void
    {
        Db::insert('audit_log', [
            'user_id'     => $userId,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'details'     => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
            'ip'          => App::clientIp(),
            'created_at'  => App::now(),
        ]);
    }
}
