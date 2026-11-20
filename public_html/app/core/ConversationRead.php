<?php

declare(strict_types=1);

namespace Causette\Security;

final class ConversationRead
{
    public static function allows(?object $chat, bool $isMember): bool
    {
        return $chat !== null && empty($chat->deleted_at) && ($isMember || (
            self::isGroup($chat) && ($chat->non_member_visibility ?? null) === 'public'
        ));
    }

    public static function allowsTrash(object $chat, object $viewer): bool
    {
        return self::isGroup($chat) && !empty($chat->deleted_at)
            && strtolower((string)($viewer->role ?? '')) === 'admin';
    }

    private static function isGroup(object $chat): bool
    {
        return strtolower(trim((string)($chat->type ?? ''))) === 'group';
    }
}
