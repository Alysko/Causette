<?php

declare(strict_types=1);

namespace Causette\Messaging;

use Chat;
use Database;
use DateTimeImmutable;
use DateTimeZone;

final class MessageSearch
{
    private const MODIFIERS = 'from|in|before|after|on';

    /** @param list<array{string, string, bool}> $filters */
    private function __construct(private readonly array $filters, private readonly DateTimeZone $timezone)
    {
    }

    public static function savedOnly(): self
    {
        return new self([], new DateTimeZone('UTC'));
    }

    public static function parse(mixed $input, string $timezone): ?self
    {
        if (!is_string($input) || strlen($input) > 800 || !mb_check_encoding($input, 'UTF-8')) {
            return null;
        }
        $input = trim($input);
        if (mb_strlen($input) < 2 || mb_strlen($input) > 200) {
            return null;
        }
        $zone = new DateTimeZone('UTC');
        if (preg_match('/\AUTC([+-])(\d{1,2})(?::([0-5]\d))?\z/', $timezone, $parts) && (int)$parts[2] <= 14) {
            $zone = new DateTimeZone(sprintf('%s%02d:%02d', $parts[1], (int)$parts[2], (int)($parts[3] ?? 0)));
        }

        if (preg_match('~"|(?:^|\s)(?:-\S|-?(?:' . self::MODIFIERS . '):)~iu', $input) === 0) {
            return new self([['text', $input, false]], $zone);
        }

        $pattern = '~\G\s*(-)?(?:(' . self::MODIFIERS . '):)?(?:"([^"\r\n]+)"|([^\s"]+))(?=\s|$)~iu';
        $count = preg_match_all($pattern, $input, $tokens, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        if (!$count || $count > 12 || implode('', array_column($tokens, 0)) !== $input) {
            return null;
        }
        $filters = [];
        foreach ($tokens as [, $minus, $field, $phrase, $word]) {
            $field = strtolower($field ?? 'text');
            $value = $phrase ?? $word;
            if ($field === 'text' && $phrase === null && preg_match('~^\-?(?:' . self::MODIFIERS . '):$~i', $value)) {
                return null;
            }
            if (in_array($field, ['before', 'after', 'on'], true) && ($minus !== null || !self::validDay($value))) {
                return null;
            }
            $filters[] = [$field, $value, $minus !== null];
        }
        return new self($filters, $zone);
    }

    /** @return list<string> */
    public function terms(): array
    {
        return array_values(array_map(
            static fn(array $filter): string => $filter[1],
            array_filter($this->filters, static fn(array $filter): bool => $filter[0] === 'text' && !$filter[2]),
        ));
    }

    /** @param list<int>|null $savedIds */
    public function results(int $userId, int $page, bool $supportsChatTitle, ?array $savedIds): array
    {
        if ($savedIds === []) {
            return ['rows' => [], 'total' => 0, 'total_pages' => 0, 'page' => 1];
        }
        [$conditions, $parameters] = $this->conditions($userId, $supportsChatTitle);
        if ($savedIds !== null) {
            $conditions .= ' AND m.id IN (' . implode(', ', array_fill(0, count($savedIds), '?')) . ')';
            $parameters = [...$parameters, ...$savedIds];
        }
        // Only fixed predicates and generated placeholders form the SQL. Search text always remains a bound value.
        $scope = "FROM messages m JOIN chats c ON c.id = m.chat_id
            JOIN chat_members cm ON cm.chat_id = c.id AND cm.user_id = ?
            JOIN users u ON u.id = m.user_id WHERE $conditions";
        if (Chat::supportsSoftDelete()) {
            $scope .= ' AND c.deleted_at IS NULL';
        }
        $perPage = 10;
        $total = (int)Database::query('SELECT COUNT(*) ' . $scope, [$userId, ...$parameters])->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $titleField = $supportsChatTitle ? 'c.title' : 'NULL';
        $query = "SELECT m.id AS message_id, m.content, m.created_at, m.user_id AS sender_id, m.bot_name,
                u.username AS sender_username, u.email AS sender_email, u.user_number AS sender_user_number,
                u.avatar_filename AS sender_avatar_filename, c.id AS chat_id, c.chat_number, c.type AS chat_type,
                $titleField AS chat_custom_title,
                (SELECT u2.username FROM chat_members cm2 JOIN users u2 ON u2.id = cm2.user_id
                 WHERE cm2.chat_id = c.id AND cm2.user_id != ? ORDER BY cm2.joined_at ASC LIMIT 1) AS other_username
            $scope ORDER BY m.created_at DESC, m.id DESC LIMIT $perPage OFFSET $offset";
        $rows = Database::query($query, [$userId, $userId, ...$parameters])->fetchAll();
        return ['rows' => $rows, 'total' => $total, 'total_pages' => $totalPages, 'page' => $page];
    }

    /** @return array{string, list<int|string>} */
    private function conditions(int $accountId, bool $supportsChatTitle): array
    {
        $conditions = [];
        $parameters = [];
        foreach ($this->filters as [$field, $value, $negative]) {
            switch ($field) {
                case 'text':
                    $conditions[] = $negative ? "m.content NOT LIKE ? ESCAPE '!'" : "m.content LIKE ? ESCAPE '!'";
                    $parameters[] = '%' . strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                    break;
                case 'from':
                    $conditions[] = $negative ? 'u.username <> ?' : 'u.username = ?';
                    $parameters[] = $value;
                    break;
                case 'in':
                    if (preg_match('/\A(?:\d{16}|\d{4}(?:-\d{4}){3})\z/', $value)) {
                        $condition = 'c.chat_number = ?';
                        $parameters[] = str_replace('-', '', $value);
                    } else {
                        $condition = "(c.type = 'personal' AND EXISTS (
                            SELECT 1 FROM chat_members peer JOIN users person ON person.id = peer.user_id
                            WHERE peer.chat_id = c.id AND peer.user_id <> ? AND person.username = ?
                        ))";
                        if ($supportsChatTitle) {
                            $condition = "(COALESCE(c.title, '') = ? OR $condition)";
                            $parameters[] = $value;
                        }
                        $parameters[] = $accountId;
                        $parameters[] = $value;
                    }
                    $conditions[] = $negative ? 'NOT (' . $condition . ')' : $condition;
                    break;
                default:
                    $start = new DateTimeImmutable($value, $this->timezone);
                    if ($field === 'on' || $field === 'after') {
                        $conditions[] = 'm.created_at >= ?';
                        $parameters[] = self::utc($field === 'after' ? $start->modify('+1 day') : $start);
                    }
                    if ($field === 'on' || $field === 'before') {
                        $conditions[] = 'm.created_at < ?';
                        $parameters[] = self::utc($field === 'on' ? $start->modify('+1 day') : $start);
                    }
            }
        }
        return [$conditions === [] ? '1 = 1' : implode(' AND ', $conditions), $parameters];
    }

    private static function validDay(string $value): bool
    {
        return preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts) === 1
            && (int)$parts[1] >= 1970 && (int)$parts[1] < 9999
            && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
    }

    private static function utc(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
