<?php

declare(strict_types=1);

namespace Causette\Messaging;

use Chat;
use Database;
use PDO;
use Setting;
use Throwable;

final class SavedMessages
{
    public const LIMIT = 100;

    /** @return list<int> */
    public static function ids(int $accountId): array
    {
        $stored = json_decode((string)Setting::get(self::key($accountId)), true);
        if (!is_array($stored) || !array_is_list($stored)) {
            return [];
        }
        $ids = array_filter($stored, static fn(mixed $id): bool => is_int($id) && $id > 0);
        return array_slice(array_values(array_unique($ids)), 0, self::LIMIT);
    }

    /** @return 'updated'|'unavailable'|'full' */
    public static function update(int $accountId, int $messageId, bool $save): string
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $key = self::key($accountId);
            // The duplicate-key update locks this account's list until commit.
            Database::query('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = `value`', [$key, '[]']);
            $ids = array_values(array_diff(self::ids($accountId), [$messageId]));
            if ($save) {
                if (self::accessibleIds($accountId, [$messageId]) === []) {
                    $db->rollBack();
                    return 'unavailable';
                }
                if (count($ids) >= self::LIMIT) {
                    $ids = self::accessibleIds($accountId, $ids);
                }
                if (count($ids) >= self::LIMIT) {
                    $db->rollBack();
                    return 'full';
                }
                $ids[] = $messageId;
            }
            Setting::set($key, json_encode($ids, JSON_THROW_ON_ERROR));
            $db->commit();
            return 'updated';
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    /** @param list<int> $ids @return list<int> */
    private static function accessibleIds(int $accountId, array $ids): array
    {
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $archive = Chat::supportsSoftDelete() ? ' AND c.deleted_at IS NULL' : '';
        return array_map('intval', Database::query(
            'SELECT m.id FROM messages m JOIN chats c ON c.id = m.chat_id '
            . 'JOIN chat_members cm ON cm.chat_id = c.id AND cm.user_id = ? '
            . "WHERE m.id IN ($placeholders)$archive FOR UPDATE",
            [$accountId, ...$ids],
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function key(int $accountId): string
    {
        return 'saved_messages_' . $accountId;
    }
}
