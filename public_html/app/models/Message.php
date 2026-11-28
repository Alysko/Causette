<?php
use Causette\Chat\ReactionCatalog;

require_once __DIR__ . '/../core/ReactionCatalog.php';

class Message extends Model {
    private const STORED_MENTION_PATTERN = '/@\[(\d{16})\|([a-z][a-z0-9]{3,31})\]/i';
    public const REACTION_CODES = ReactionCatalog::CODES;

    /** @return array<int|string, true> */
    public static function mentionedUserNumbers(string $content): array
    {
        preg_match_all(self::STORED_MENTION_PATTERN, $content, $matches);
        return array_fill_keys($matches[1], true);
    }

    public static function getRecent($chatId) {
        $messages = Database::query("SELECT m.*, u.username FROM messages m JOIN users u ON m.user_id = u.id WHERE m.chat_id = ? ORDER BY m.created_at DESC LIMIT 50", [$chatId])->fetchAll();
        foreach ($messages as $message) {
            if (!empty($message->bot_name)) {
                $message->username = $message->bot_name;
            }
        }
        return $messages;
    }

    public static function timelineForView(int $chatId, ?int $anchorId = null): array
    {
        $anchorJoin = '';
        $parameters = [$chatId];
        if ($anchorId !== null) {
            $anchorJoin = ' JOIN messages anchor ON anchor.id = ? AND anchor.chat_id = m.chat_id
                AND (m.created_at < anchor.created_at OR (m.created_at = anchor.created_at AND m.id <= anchor.id))';
            $parameters = [$anchorId, $chatId];
        }
        $sql = "SELECT m.*, u.username, u.email AS user_email, u.user_number, %s u.presence_status, u.last_active_at,
                qu.username AS quoted_username, qu.user_number AS quoted_user_number, qm.bot_name AS quoted_bot_name
            FROM messages m$anchorJoin
            JOIN users u ON u.id = m.user_id
            LEFT JOIN users qu ON qu.id = m.quoted_user_id
            LEFT JOIN messages qm ON qm.id = m.quoted_message_id AND qm.chat_id = m.chat_id
            WHERE m.chat_id = ? ORDER BY m.created_at DESC, m.id DESC LIMIT 100";
        try {
            return Database::query(sprintf($sql, 'u.avatar_filename,'), $parameters)->fetchAll();
        } catch (Throwable $error) {
            if (stripos($error->getMessage(), 'avatar_filename') === false) throw $error;
            return Database::query(sprintf($sql, ''), $parameters)->fetchAll();
        }
    }

    public static function encodeMentionsForChat($chatId, $content) {
        $text = (string)$content;
        if ($text === '') {
            return $text;
        }

        $memberRows = Database::query(
            "SELECT u.username, u.user_number
             FROM chat_members cm
             JOIN users u ON u.id = cm.user_id
             WHERE cm.chat_id = ?",
            [(int)$chatId]
        )->fetchAll();

        if (!$memberRows || count($memberRows) === 0) {
            return $text;
        }

        $userNumberByUsername = [];
        foreach ($memberRows as $memberRow) {
            $normalizedUsername = User::normalizeUsername($memberRow->username ?? '');
            $normalizedUserNumber = self::normalizeStoredUserNumber($memberRow->user_number ?? '');
            if ($normalizedUsername === '' || $normalizedUserNumber === '') {
                continue;
            }

            $userNumberByUsername[$normalizedUsername] = $normalizedUserNumber;
        }

        if (count($userNumberByUsername) === 0) {
            return $text;
        }

        return preg_replace_callback(
            '/(^|[^a-z0-9_])@([a-z][a-z0-9]{3,31})\b/i',
            function ($matches) use ($userNumberByUsername) {
                $prefix = (string)($matches[1] ?? '');
                $username = User::normalizeUsername($matches[2] ?? '');

                if ($username === '' || !isset($userNumberByUsername[$username])) {
                    return $matches[0];
                }

                $userNumber = $userNumberByUsername[$username];
                return $prefix . '@[' . $userNumber . '|' . $username . ']';
            },
            $text
        );
    }

    public static function attachMentionMaps(array &$messages): void {
        if (count($messages) === 0) {
            return;
        }

        $userNumbers = [];
        foreach ($messages as $message) {
            $message->mention_map = (object)[];
            $content = (string)($message->content ?? '');
            if ($content === '') {
                continue;
            }

            if (!preg_match_all(self::STORED_MENTION_PATTERN, $content, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $userNumber = self::normalizeStoredUserNumber($match[1] ?? '');
                if ($userNumber === '') {
                    continue;
                }
                $userNumbers[$userNumber] = true;
            }
        }

        if (count($userNumbers) === 0) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($userNumbers), '?'));
        $rows = Database::query(
            "SELECT user_number, username FROM users WHERE user_number IN ($placeholders)",
            array_keys($userNumbers)
        )->fetchAll();

        $usernameByNumber = [];
        foreach ($rows as $row) {
            $userNumber = self::normalizeStoredUserNumber($row->user_number ?? '');
            $username = User::normalizeUsername($row->username ?? '');
            if ($userNumber === '' || $username === '') {
                continue;
            }
            $usernameByNumber[$userNumber] = $username;
        }

        foreach ($messages as $message) {
            $content = (string)($message->content ?? '');
            if ($content === '') {
                continue;
            }

            if (!preg_match_all(self::STORED_MENTION_PATTERN, $content, $matches, PREG_SET_ORDER)) {
                continue;
            }

            $mentionMap = [];
            foreach ($matches as $match) {
                $userNumber = self::normalizeStoredUserNumber($match[1] ?? '');
                if ($userNumber === '') {
                    continue;
                }

                $fallbackUsername = User::normalizeUsername($match[2] ?? '');
                $mentionMap[$userNumber] = $usernameByNumber[$userNumber] ?? $fallbackUsername;
            }

            $message->mention_map = (object)$mentionMap;
        }
    }

    public static function attachQuoteMentionMaps(array &$messages): void {
        if (count($messages) === 0) {
            return;
        }

        $userNumbers = [];
        foreach ($messages as $message) {
            $message->quote_mention_map = (object)[];
            $content = (string)($message->quoted_content ?? '');
            if ($content === '') {
                continue;
            }

            if (!preg_match_all(self::STORED_MENTION_PATTERN, $content, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $userNumber = self::normalizeStoredUserNumber($match[1] ?? '');
                if ($userNumber === '') {
                    continue;
                }
                $userNumbers[$userNumber] = true;
            }
        }

        if (count($userNumbers) === 0) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($userNumbers), '?'));
        $rows = Database::query(
            "SELECT user_number, username FROM users WHERE user_number IN ($placeholders)",
            array_keys($userNumbers)
        )->fetchAll();

        $usernameByNumber = [];
        foreach ($rows as $row) {
            $userNumber = self::normalizeStoredUserNumber($row->user_number ?? '');
            $username = User::normalizeUsername($row->username ?? '');
            if ($userNumber === '' || $username === '') {
                continue;
            }
            $usernameByNumber[$userNumber] = $username;
        }

        foreach ($messages as $message) {
            $content = (string)($message->quoted_content ?? '');
            if ($content === '') {
                continue;
            }

            if (!preg_match_all(self::STORED_MENTION_PATTERN, $content, $matches, PREG_SET_ORDER)) {
                continue;
            }

            $mentionMap = [];
            foreach ($matches as $match) {
                $userNumber = self::normalizeStoredUserNumber($match[1] ?? '');
                if ($userNumber === '') {
                    continue;
                }

                $fallbackUsername = User::normalizeUsername($match[2] ?? '');
                $mentionMap[$userNumber] = $usernameByNumber[$userNumber] ?? $fallbackUsername;
            }

            $message->quote_mention_map = (object)$mentionMap;
        }
    }

    public static function attachReactions(array &$messages, int $currentUserId): void {
        if (count($messages) === 0) {
            return;
        }

        $messageIds = [];
        foreach ($messages as $message) {
            $messageId = (int)($message->id ?? 0);
            if ($messageId <= 0) {
                continue;
            }
            $messageIds[$messageId] = true;
            $message->reactions = [];
        }

        if (count($messageIds) === 0) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $params = array_keys($messageIds);
        $rows = Database::query(
            "SELECT mr.message_id, mr.reaction_code, mr.user_id, u.username
             FROM message_reactions mr
             JOIN users u ON u.id = mr.user_id
             WHERE mr.message_id IN ($placeholders)
             ORDER BY mr.id ASC",
            $params
        )->fetchAll();

        $aggregatedByMessage = [];
        foreach ($rows as $row) {
            $messageId = (int)($row->message_id ?? 0);
            $reactionCode = self::normalizeReactionCode($row->reaction_code ?? '');
            $reactionUserId = (int)($row->user_id ?? 0);
            $username = User::normalizeUsername($row->username ?? '');

            if ($messageId <= 0 || $reactionCode === '' || $reactionUserId <= 0 || $username === '') {
                continue;
            }

            if (!isset($aggregatedByMessage[$messageId])) {
                $aggregatedByMessage[$messageId] = [];
            }
            if (!isset($aggregatedByMessage[$messageId][$reactionCode])) {
                $aggregatedByMessage[$messageId][$reactionCode] = [
                    'reaction_code' => $reactionCode,
                    'count' => 0,
                    'users' => [],
                    'reacted_by_current_user' => false
                ];
            }

            $aggregatedByMessage[$messageId][$reactionCode]['count'] += 1;
            $aggregatedByMessage[$messageId][$reactionCode]['users'][] = $username;
            if ($reactionUserId === $currentUserId) {
                $aggregatedByMessage[$messageId][$reactionCode]['reacted_by_current_user'] = true;
            }
        }

        foreach ($messages as $message) {
            $messageId = (int)($message->id ?? 0);
            if (!isset($aggregatedByMessage[$messageId])) {
                $message->reactions = [];
                continue;
            }

            $reactionRows = [];
            foreach ($aggregatedByMessage[$messageId] as $entry) {
                $reactionRows[] = (object)[
                    'reaction_code' => (string)$entry['reaction_code'],
                    'count' => (int)$entry['count'],
                    'users' => array_values($entry['users']),
                    'reacted_by_current_user' => (bool)$entry['reacted_by_current_user']
                ];
            }

            $message->reactions = $reactionRows;
        }
    }

    public static function normalizeReactionCode($value): string {
        return ReactionCatalog::normalize($value);
    }

    private static function normalizeStoredUserNumber($value) {
        $digits = preg_replace('/\D+/', '', (string)$value);
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) < 16) {
            $digits = str_pad($digits, 16, '0', STR_PAD_LEFT);
        }

        if (strlen($digits) > 16) {
            $digits = substr($digits, 0, 16);
        }

        return $digits;
    }
}
