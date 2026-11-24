<?php

declare(strict_types=1);

namespace Causette\Chat;

use Causette\Localization\Locale;

require_once __DIR__ . '/Locale.php';

final class GroupHistoryText
{
    // Older events are stored as English sentences. Match only recognized event
    // types and shapes so that older or customized event text remains readable.
    private const LEGACY_PATTERNS = [
        'group_created' => '/\A(?<actor>[^\r\n]+) created the group\z/u',
        'user_auto_joined' => '/\A(?<actor>[^\r\n]+) joined via auto-join\z/u',
        'user_added' => '/\A(?<actor>[^\r\n]+?) added (?<member>[^\r\n]+) to group\z/u',
        'user_removed' => '/\A(?<actor>[^\r\n]+?) removed (?<member>[^\r\n]+) from group\z/u',
        'ownership_transferred' => '/\A(?<actor>[^\r\n]+?) transferred group ownership to (?<member>[^\r\n]+)\z/u',
        'user_left' => '/\A(?<actor>[^\r\n]+) left the group\z/u',
        'ownership_taken' => '/\A(?<actor>[^\r\n]+?) took group ownership(?: from (?<member>[^\r\n]+))?\z/u',
        'moderator_promoted' => '/\A(?<actor>[^\r\n]+?) promoted (?<member>[^\r\n]+) to moderator\z/u',
        'moderator_demoted' => '/\A(?<actor>[^\r\n]+?) removed moderator role from (?<member>[^\r\n]+)\z/u',
        'user_muted' => '/\A(?<actor>[^\r\n]+?) muted (?<member>[^\r\n]+)\z/u',
        'user_unmuted' => '/\A(?<actor>[^\r\n]+?) unmuted (?<member>[^\r\n]+)\z/u',
        'join_request_approved' => '/\A(?<actor>[^\r\n]+?) approved join request for (?<member>[^\r\n]+)\z/u',
        'join_request_denied' => '/\A(?<actor>[^\r\n]+?) denied join request for (?<member>[^\r\n]+)\z/u',
    ];

    public static function encodeRename(string $previous, string $current): string
    {
        return json_encode([
            'event' => 'group-rename-v1',
            'previous' => $previous,
            'current' => $current,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    }

    public static function localize(string $eventType, string $content, ?Locale $locale = null): string
    {
        $locale ??= Locale::current();
        if ($eventType === 'chat_renamed') {
            return self::rename($content, $locale);
        }
        if ($eventType === 'poll_expired') {
            return self::pollExpired($content, $locale);
        }
        if ($eventType === 'user_deleted' && preg_match('~\AUser deleted\. (/c/\d{4}-\d{4}-\d{4}-\d{4}/delete)\z~', $content, $matches)) {
            return $locale->text('history.user_deleted', ['path' => $matches[1]]);
        }

        $pattern = self::LEGACY_PATTERNS[$eventType] ?? null;
        if ($pattern === null || !preg_match($pattern, $content, $matches)) {
            return $content;
        }

        $member = $matches['member'] ?? '';
        if ($eventType === 'ownership_taken' && $member !== '') {
            $eventType = 'ownership_taken_from';
        }
        return $locale->text('history.' . $eventType, ['actor' => $matches['actor'], 'member' => $member]);
    }

    public static function archived(string $eventType, string $content, Locale $locale): string
    {
        // The live timeline parses this record into a heading and a poll body.
        if ($eventType === 'poll_created' && preg_match('/\APoll created by ([^:\r\n]+): (.*)\z/su', $content, $parts)) {
            return $locale->text('chat.poll_created_by', ['creator' => $parts[1]]) . "\n" . $parts[2];
        }
        return self::localize($eventType, $content, $locale);
    }

    private static function rename(string $content, Locale $locale): string
    {
        $rename = json_decode($content, true, 4);
        if (is_array($rename) && ($rename['event'] ?? '') === 'group-rename-v1'
            && is_string($rename['previous'] ?? null) && is_string($rename['current'] ?? null)
        ) {
            return $locale->text('history.chat_renamed', $rename);
        }

        $prefix = 'Chat renamed from ';
        if (str_starts_with($content, $prefix) && substr_count($content, ' to ') === 1) {
            [$previous, $current] = explode(' to ', substr($content, strlen($prefix)), 2);
            return $locale->text('history.chat_renamed', ['previous' => $previous, 'current' => $current]);
        }
        return $content;
    }

    private static function pollExpired(string $content, Locale $locale): string
    {
        $lines = explode("\n", $content);
        if (!preg_match('/\APoll expired by (?<actor>[^:\r\n]+)(?:: (?<question>[^\r\n]+))?\z/u', $lines[0], $header)) {
            return $content;
        }

        $question = $header['question'] ?? '';
        $lines[0] = $locale->text($question === '' ? 'history.poll_expired' : 'history.poll_expired_with_question', [
            'actor' => $header['actor'],
            'question' => $question,
        ]);
        foreach (array_slice($lines, 1, null, true) as $index => $line) {
            if (preg_match('/\A• (?<option>.+) — (?<count>[0-9]+) votes?\z/u', $line, $result)) {
                $count = (int) $result['count'];
                $lines[$index] = $locale->text('history.poll_result', [
                    'option' => $result['option'],
                    'votes' => $locale->text($count === 1 ? 'chat.vote_count_one' : 'chat.vote_count_other', ['count' => $count]),
                ]);
            }
        }
        return implode("\n", $lines);
    }
}
