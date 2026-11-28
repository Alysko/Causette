<?php
class Notification extends Model {
    /** @param list<array{int, string, string, string, string}> $notifications */
    public static function createInAppBatch(array $notifications): void
    {
        if ($notifications === []) return;
        $values = implode(', ', array_fill(0, count($notifications), '(?, ?, ?, ?, ?)'));
        Database::query('INSERT INTO notifications (user_id, type, title, message, link) VALUES ' . $values,
            array_merge(...$notifications));
    }

    public static function create($userId, $type, $title, $message, $link = null) {
        Database::query("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)", [$userId, $type, $title, $message, $link]);

        try {
            WebPushService::sendForNotification((int)$userId, (string)$type);
        } catch (Throwable) {
            WebPushService::reportUnexpectedFailure();
        }
    }
}
