<?php

declare(strict_types=1);

namespace Causette\Http;

final class ChatResponse
{
    private const MESSAGE_FIELDS = [
        'id', 'chat_id', 'user_id', 'content', 'created_at', 'edited_at',
        'quoted_message_id', 'quoted_user_id', 'quoted_content', 'quoted_username', 'quoted_user_number', 'quoted_bot_name',
        'bot_name', 'username', 'user_number', 'avatar_url',
        'effective_status', 'effective_status_label', 'effective_status_text_class', 'effective_status_dot_class',
        'mention_map', 'quote_mention_map', 'reactions', 'attachments', 'is_quoted', 'has_attachments',
        'is_system_event', 'event_type',
    ];

    private const PIN_FIELDS = [
        'id', 'chat_id', 'user_id', 'content', 'created_at', 'username', 'bot_name', 'user_number', 'avatar_url', 'mention_map',
    ];

    private const SEARCH_FIELDS = [
        'message_id', 'content', 'created_at', 'sender_id', 'sender_username', 'sender_user_number', 'sender_avatar_url', 'bot_name',
        'chat_id', 'chat_number', 'chat_type', 'chat_number_formatted', 'chat_type_normalized', 'chat_title',
    ];

    private const PREVIEW_FIELDS = [
        'id', 'chat_number', 'type', 'created_at', 'custom_title', 'chat_title', 'chat_number_formatted',
        'last_message', 'last_message_at', 'last_message_user_id', 'last_message_sender_username', 'last_message_bot_name',
        'unread_count', 'other_user_id', 'other_username', 'active_call_participant_count', 'is_favorite', 'has_custom_title',
        'effective_status', 'effective_status_label', 'effective_status_text_class', 'effective_status_dot_class',
    ];

    public static function messages(array $messages): array
    {
        return self::selectRows($messages, self::MESSAGE_FIELDS);
    }

    public static function pinned(object $message): object
    {
        return (object) array_intersect_key(get_object_vars($message), array_flip(self::PIN_FIELDS));
    }

    public static function searchResults(array $messages): array
    {
        return self::selectRows($messages, self::SEARCH_FIELDS);
    }

    public static function conversations(array $chats): array
    {
        return self::selectRows($chats, self::PREVIEW_FIELDS);
    }

    private static function selectRows(array $rows, array $fields): array
    {
        $allowed = array_flip($fields);
        return array_map(
            static fn(object $row): object => (object) array_intersect_key(get_object_vars($row), $allowed),
            $rows,
        );
    }
}
