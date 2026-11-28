<?php

declare(strict_types=1);

namespace Causette\Messaging;

use Database;
use Message;
use Setting;

final class ConversationNotifications
{
    public const MODES = ['all' => 'ui.notify_chat_all', 'mentions' => 'ui.notify_chat_mentions', 'none' => 'ui.notify_chat_none'];

    public static function mode(int $chatId, int $userId): string
    {
        return self::normalize(Setting::get(self::key($chatId, $userId)));
    }

    public static function save(int $chatId, int $userId, string $mode): void
    {
        Setting::set(self::key($chatId, $userId), $mode);
    }

    /** @return list<int> */
    public static function recipients(int $chatId, int $senderId, string $content): array
    {
        $members = Database::query('SELECT cm.user_id, u.user_number FROM chat_members cm
            JOIN users u ON u.id = cm.user_id WHERE cm.chat_id = ? AND cm.user_id <> ?', [$chatId, $senderId])->fetchAll();
        $keys = array_map(static fn(object $member): string => self::key($chatId, (int)$member->user_id), $members);
        $modes = Setting::getMany($keys);
        $mentions = Message::mentionedUserNumbers($content);
        $recipients = [];
        foreach ($members as $index => $member) {
            $mode = self::normalize($modes[$keys[$index]] ?? null);
            if ($mode === 'all' || ($mode === 'mentions' && isset($mentions[(string)$member->user_number]))) {
                $recipients[] = (int)$member->user_id;
            }
        }
        return $recipients;
    }

    /** @param list<int> $followers @return list<int> */
    public static function threadRecipients(int $chatId, array $followers): array
    {
        $keys = array_map(static fn(int $id): string => self::key($chatId, $id), $followers);
        $modes = Setting::getMany($keys);
        return array_values(array_filter($followers,
            static fn(int $id): bool => ($modes[self::key($chatId, $id)] ?? null) !== 'none'));
    }

    private static function normalize(?string $mode): string
    {
        return isset(self::MODES[$mode ?? '']) ? $mode : 'all';
    }

    private static function key(int $chatId, int $userId): string
    {
        return 'chat_notify_' . $chatId . '_' . $userId;
    }
}
