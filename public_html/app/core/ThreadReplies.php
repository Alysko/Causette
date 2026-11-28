<?php

declare(strict_types=1);

namespace Causette\Messaging;

use Database;
use User;

final class ThreadReplies
{
    public const PAGE_SIZE = 40;
    public const MAX_LENGTH = 4096;

    public static function root(int $id, int $accountId, bool $lock = false): ?object
    {
        $root = Database::query(
            'SELECT m.id, m.chat_id, m.content, m.created_at, m.bot_name, u.username, u.email,
                    c.type, c.chat_number, cm.is_muted
             FROM messages m JOIN chats c ON c.id = m.chat_id
             JOIN chat_members cm ON cm.chat_id = m.chat_id AND cm.user_id = ?
             JOIN users u ON u.id = m.user_id
             WHERE m.id = ? AND c.deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : ''),
            [$accountId, $id],
        )->fetch();
        return $root ?: null;
    }

    public static function page(int $rootId, ?int $before, ?int $after): array
    {
        $cutoff = $after !== null ? ' AND r.id > ?' : ($before !== null ? ' AND r.id < ?' : '');
        $direction = $after !== null ? 'ASC' : 'DESC';
        $cursor = $after ?? $before;
        $limit = self::PAGE_SIZE + 1;
        $rows = Database::query(
            self::replySelect() . " WHERE r.root_id = ?$cutoff ORDER BY r.id $direction LIMIT $limit",
            $cursor === null ? [$rootId] : [$rootId, $cursor],
        )->fetchAll();
        $hasExtra = count($rows) > self::PAGE_SIZE;
        if ($hasExtra) {
            array_pop($rows);
        }
        return ['replies' => array_map(self::project(...), $after !== null ? $rows : array_reverse($rows)),
            'has_older' => $after === null && $hasExtra];
    }

    /** @param list<object> $messages */
    public static function attachCounts(array $messages): void
    {
        if ($messages === []) {
            return;
        }
        $ids = array_column($messages, 'id');
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $counts = Database::query("SELECT root_id, COUNT(*) FROM thread_replies WHERE root_id IN ($placeholders) GROUP BY root_id", $ids)
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach ($messages as $message) {
            $message->thread_replies = (int)($counts[$message->id] ?? 0);
        }
    }

    public static function reply(int $rootId, int $accountId, string $key, string $content): ?array
    {
        $select = self::replySelect() . ' WHERE r.root_id = ? AND r.user_id = ? AND r.client_key = ?';
        $parameters = [$rootId, $accountId, $key];
        $row = Database::query($select, $parameters)->fetch();
        if ($row) {
            return $row->content === $content ? ['reply' => self::project($row), 'created' => false] : null;
        }
        Database::query('INSERT INTO thread_replies (root_id, user_id, client_key, content) VALUES (?, ?, ?, ?)',
            [...$parameters, $content]);
        return ['reply' => self::project(Database::query($select, $parameters)->fetch()), 'created' => true];
    }

    public static function following(int $rootId, int $accountId): bool
    {
        return Database::query('SELECT 1 FROM thread_followers WHERE root_id = ? AND user_id = ?', [$rootId, $accountId])->fetchColumn() !== false;
    }

    public static function follow(int $rootId, int $accountId, bool $following): void
    {
        $sql = $following
            ? 'INSERT INTO thread_followers (root_id, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id = user_id'
            : 'DELETE FROM thread_followers WHERE root_id = ? AND user_id = ?';
        Database::query($sql, [$rootId, $accountId]);
    }

    /** @return list<int> */
    public static function followers(int $rootId, int $author): array
    {
        return array_map('intval', Database::query('SELECT tf.user_id FROM thread_followers tf
            JOIN messages m ON m.id = tf.root_id JOIN chats c ON c.id = m.chat_id
            JOIN chat_members cm ON cm.chat_id = m.chat_id AND cm.user_id = tf.user_id
            WHERE tf.root_id = ? AND tf.user_id <> ? AND c.deleted_at IS NULL', [$rootId, $author])->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function project(object $row): array
    {
        return [
            'id' => (int)$row->id,
            'author' => !empty($row->bot_name) ? (string)$row->bot_name : User::decorateDeletedRetainedUsername($row->username, $row->email),
            'content' => (string)$row->content,
            'created_at' => (string)$row->created_at,
        ];
    }

    private static function replySelect(): string
    {
        return 'SELECT r.id, r.content, r.created_at, u.username, u.email
                FROM thread_replies r JOIN users u ON u.id = r.user_id';
    }
}
