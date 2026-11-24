<?php

use Causette\Localization\DisplayPreferences;
use Causette\Localization\Locale;
use Causette\Calls\CallSignals;
use Causette\Calls\CallTurnConfiguration;

require_once __DIR__ . '/../core/CallSignals.php';
require_once __DIR__ . '/../core/CallTurnConfiguration.php';

class CallController extends Controller {
    private const CALL_USER_LIMIT = 99;

    private function callError(string $key, int $status): void {
        $this->json(['error' => Locale::current()->text('calls.' . $key)], $status);
    }

    private function supportsSystemEvents(): bool {
        static $supports = null;
        if ($supports !== null) {
            return $supports;
        }

        $result = Database::query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_system_events'"
        )->fetchColumn();

        $supports = ((int)$result) > 0;
        return $supports;
    }

    private function getActiveParticipantCount(int $callId): int {
        return (int)Database::query(
            "SELECT COUNT(DISTINCT cp.user_id)
             FROM call_participants cp
             JOIN calls c ON c.id = cp.call_id
             JOIN chat_members cm ON cm.chat_id = c.chat_id AND cm.user_id = cp.user_id
             JOIN users u ON u.id = cp.user_id AND COALESCE(u.is_banned, 0) = 0
             WHERE cp.call_id = ? AND cp.left_at IS NULL",
            [$callId]
        )->fetchColumn();
    }

    private function isUserActivelyInCall(int $callId, int $userId): bool {
        $count = (int)Database::query(
            "SELECT COUNT(*)
             FROM call_participants
             WHERE call_id = ?
               AND user_id = ?
               AND left_at IS NULL",
            [$callId, $userId]
        )->fetchColumn();

        return $count > 0;
    }

    private function closeEmptyGroupCall(int $callId): bool {
        $closed = Database::query(
            "UPDATE calls SET status = 'ended', ended_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'active' AND NOT EXISTS (
                 SELECT 1 FROM call_participants cp
                 JOIN chat_members cm ON cm.chat_id = calls.chat_id AND cm.user_id = cp.user_id
                 JOIN users u ON u.id = cp.user_id AND COALESCE(u.is_banned, 0) = 0
                 WHERE cp.call_id = calls.id AND cp.left_at IS NULL
             )",
            [$callId],
        )->rowCount() > 0;
        if ($closed) {
            Database::query(
                "UPDATE call_participants SET left_at = CURRENT_TIMESTAMP WHERE call_id = ? AND left_at IS NULL",
                [$callId],
            );
        }
        return $closed;
    }

    private function isUserInJoinedActiveCall(int $userId, int $excludeChatId = 0): bool {
        if ($userId <= 0) {
            return false;
        }

        $params = [$userId];
        $excludeChatFilter = '';
        if ($excludeChatId > 0) {
            $excludeChatFilter = ' AND c.chat_id != ?';
            $params[] = $excludeChatId;
        }

        $count = (int)Database::query(
            "SELECT COUNT(*)
             FROM calls c
             JOIN call_participants cp ON cp.call_id = c.id
             JOIN chat_members cm ON cm.chat_id = c.chat_id AND cm.user_id = cp.user_id
             WHERE c.status = 'active'
               AND cp.user_id = ?
               AND cp.left_at IS NULL" . $excludeChatFilter,
            $params
        )->fetchColumn();

        return $count > 0;
    }

    private function upsertActiveParticipant(int $callId, int $userId, int $video = 0): void {
        $active = Database::query(
            "SELECT id
             FROM call_participants
             WHERE call_id = ?
               AND user_id = ?
               AND left_at IS NULL
             ORDER BY id DESC
             LIMIT 1",
            [$callId, $userId]
        )->fetch();

        if ($active) {
            $activeId = (int)$active->id;
            Database::query(
                "UPDATE call_participants
                 SET joined_at = CURRENT_TIMESTAMP,
                     left_at = NULL,
                     video = ?
                 WHERE id = ?",
                [$video, $activeId]
            );

            Database::query(
                "UPDATE call_participants
                 SET left_at = COALESCE(left_at, NOW())
                 WHERE call_id = ?
                   AND user_id = ?
                   AND left_at IS NULL
                   AND id != ?",
                [$callId, $userId, $activeId]
            );
            return;
        }

        Database::query(
            "INSERT INTO call_participants (call_id, user_id, video) VALUES (?, ?, ?)",
            [$callId, $userId, $video]
        );
    }

    private function isPersonalChatPeerBanned(int $chatId, int $userId): bool {
        $chat = Database::query("SELECT id, type FROM chats WHERE id = ?", [$chatId])->fetch();
        if (!$chat || Chat::isGroupType($chat->type ?? null)) {
            return false;
        }

        $peer = Database::query(
            "SELECT u.is_banned
             FROM chat_members cm
             JOIN users u ON u.id = cm.user_id
             WHERE cm.chat_id = ? AND cm.user_id != ?
             LIMIT 1",
            [$chatId, $userId]
        )->fetch();

        return (int)($peer->is_banned ?? 0) === 1;
    }

    private function formatCallDuration(int $durationSeconds): string {
        $safeDuration = max(0, $durationSeconds);
        $minutes = intdiv($safeDuration, 60);
        $seconds = $safeDuration % 60;
        return $minutes . ':' . str_pad((string)$seconds, 2, '0', STR_PAD_LEFT);
    }

    private function appendCallHistoryMessage(int $callId): void {
        $call = Database::query(
            "SELECT c.id,
                    c.chat_id,
                    ch.type AS chat_type,
                    c.started_by,
                    c.started_at,
                    c.ended_at,
                    u.username AS starter_username,
                    (
                        SELECT u2.username
                        FROM chat_members cm
                        JOIN users u2 ON u2.id = cm.user_id
                        WHERE cm.chat_id = c.chat_id
                          AND cm.user_id != c.started_by
                        ORDER BY cm.joined_at ASC
                        LIMIT 1
                    ) AS callee_username,
                    (SELECT COUNT(DISTINCT cp.user_id)
                     FROM call_participants cp
                     WHERE cp.call_id = c.id) AS participant_total
             FROM calls c
             JOIN chats ch ON ch.id = c.chat_id
             JOIN users u ON u.id = c.started_by
             WHERE c.id = ?",
            [$callId]
        )->fetch();

        if (!$call) {
            return;
        }

        $participantTotal = max(0, (int)($call->participant_total ?? 0));
        $durationSeconds = 0;

        if ($participantTotal > 1) {
            $startedAt = strtotime((string)($call->started_at ?? ''));
            $endedAt = strtotime((string)($call->ended_at ?? ''));
            if ($startedAt !== false && $endedAt !== false) {
                $durationSeconds = max(0, $endedAt - $startedAt);
            }
        }

        $durationLabel = $this->formatCallDuration($durationSeconds);
        $starterUsername = trim((string)($call->starter_username ?? 'Someone'));
        if ($starterUsername === '') {
            $starterUsername = 'Someone';
        }

        $calleeUsername = trim((string)($call->callee_username ?? 'Someone'));
        if ($calleeUsername === '') {
            $calleeUsername = 'Someone';
        }

        $isGroupCall = Chat::isGroupType($call->chat_type ?? null);
        $content = $isGroupCall
            ? ($starterUsername . ' started a call (' . $durationLabel . ')')
            : ($starterUsername . ' called ' . $calleeUsername . ' (' . $durationLabel . ')');

        if (!$isGroupCall && $this->supportsSystemEvents()) {
            Database::query(
                "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'call_completed', ?)",
                [(int)$call->chat_id, $content]
            );
            return;
        }

        Database::query(
            "INSERT INTO messages (chat_id, user_id, content) VALUES (?, ?, ?)",
            [(int)$call->chat_id, (int)$call->started_by, $content]
        );
    }

    private function appendPrivateCallDeclinedMessage(int $chatId, int $declinerUserId): void {
        if ($chatId <= 0 || $declinerUserId <= 0) {
            return;
        }

        $chatType = Database::query("SELECT type FROM chats WHERE id = ?", [$chatId])->fetchColumn();
        if (Chat::isGroupType($chatType)) {
            return;
        }

        $declinerUsername = trim((string)Database::query("SELECT username FROM users WHERE id = ?", [$declinerUserId])->fetchColumn());
        if ($declinerUsername === '') {
            $declinerUsername = 'Someone';
        }

        $content = $declinerUsername . ' declined a call';

        if ($this->supportsSystemEvents()) {
            Database::query(
                "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'call_declined', ?)",
                [$chatId, $content]
            );
            return;
        }

        Database::query("INSERT INTO messages (chat_id, user_id, content) VALUES (?, ?, ?)", [$chatId, $declinerUserId, $content]);
    }

    private function appendGroupCallPresenceMessage(int $chatId, int $userId, string $action): void {
        if ($chatId <= 0 || $userId <= 0) {
            return;
        }

        if (!in_array($action, ['joined', 'left'], true)) {
            return;
        }

        $chatType = Database::query("SELECT type FROM chats WHERE id = ?", [$chatId])->fetchColumn();
        if (!Chat::isGroupType($chatType)) {
            return;
        }

        $username = trim((string)Database::query("SELECT username FROM users WHERE id = ?", [$userId])->fetchColumn());
        if ($username === '') {
            $username = 'Someone';
        }

        $content = $username . ($action === 'joined' ? ' joined call' : ' left call');

        if ($this->supportsSystemEvents()) {
            $eventType = $action === 'joined' ? 'call_joined' : 'call_left';
            Database::query(
                "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, ?, ?)",
                [$chatId, $eventType, $content]
            );
            return;
        }

        Database::query("INSERT INTO messages (chat_id, user_id, content) VALUES (?, ?, ?)", [$chatId, $userId, $content]);
    }

    public function startCall() {
        Auth::requireAuth();
        Auth::csrfValidate();
        $chatId = CallSignals::id($_POST['chat_id'] ?? null, 1);
        $userId = (int) Auth::user()->id;

        if ($chatId === null) {
            $this->callError('invalid_chat', 400);
            return;
        }

        $connection = Database::getInstance();
        $connection->beginTransaction();
        try {
            // Serialize creation in a conversation and concurrent joins by one account.
            $chat = Database::query("SELECT id, type FROM chats WHERE id = ? FOR UPDATE", [$chatId])->fetch();
            if (!$chat) {
                $connection->rollBack();
                $this->callError('chat_not_found', 404);
                return;
            }

            Database::query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$userId])->fetch();
            $member = Database::query(
                "SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ?",
                [$chatId, $userId],
            )->fetch();
            if (!$member) {
                $connection->rollBack();
                $this->callError('access_denied', 403);
                return;
            }

            if ($this->isPersonalChatPeerBanned($chatId, $userId)) {
                $connection->rollBack();
                $this->callError('cannot_call_banned_user', 403);
                return;
            }

            if ($this->isUserInJoinedActiveCall($userId, $chatId)) {
                $connection->rollBack();
                $this->callError('already_in_another_call', 409);
                return;
            }

            $group = Chat::isGroupType($chat->type ?? null);
            $activeCall = Database::query(
                "SELECT id, started_by FROM calls WHERE chat_id = ? AND status = 'active'
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                [$chatId],
            )->fetch();
            if ($activeCall && $group && $this->closeEmptyGroupCall((int) $activeCall->id)) {
                $activeCall = null;
            }

            $joinedExisting = (bool) $activeCall;
            if ($joinedExisting) {
                $callId = (int) $activeCall->id;
                $wasAlreadyActive = $this->isUserActivelyInCall($callId, $userId);
                if (!$wasAlreadyActive && $this->getActiveParticipantCount($callId) >= self::CALL_USER_LIMIT) {
                    $connection->rollBack();
                    $this->callError('call_participant_limit', 429);
                    return;
                }

                if (!$wasAlreadyActive && !$group && (int) $activeCall->started_by !== $userId) {
                    Database::query('UPDATE calls SET started_at = CURRENT_TIMESTAMP WHERE id = ?', [$callId]);
                }
            } else {
                Database::query("INSERT INTO calls (chat_id, started_by) VALUES (?, ?)", [$chatId, $userId]);
                $callId = $connection->lastInsertId();
                $wasAlreadyActive = false;
            }

            if (!$wasAlreadyActive) {
                $this->upsertActiveParticipant((int) $callId, $userId, 0);
                if ($group) {
                    $this->appendGroupCallPresenceMessage($chatId, $userId, 'joined');
                }
            }
            $connection->commit();
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }

        $response = ['call_id' => $callId, 'joined_existing' => $joinedExisting];
        $turn = CallTurnConfiguration::issueFor((int) $callId, $userId);
        if ($turn !== null) {
            header('Cache-Control: private, no-store');
            $response['turn'] = $turn;
        }
        $this->json($response);
    }

    public function signal() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $callId = CallSignals::id($_POST['call_id'] ?? null, 1);
        $toUserId = CallSignals::id($_POST['to_user_id'] ?? 0, 0);
        $userId = (int) Auth::user()->id;
        $type = $_POST['type'] ?? null;
        $packet = $_POST['data'] ?? null;
        $locale = Locale::current();
        if ($callId === null || $toUserId === null || $toUserId === $userId
            || !is_string($type) || !is_string($packet)) {
            $this->json(['error' => $locale->text('calls.invalid_signal')], 400);
            return;
        }
        if (strlen($packet) > CallSignals::MAX_PACKET_BYTES) {
            $this->json(['error' => $locale->text('calls.signal_too_large')], 413);
            return;
        }
        $decoded = CallSignals::decode($type, $packet, $toUserId);
        if ($decoded === null) {
            $this->json(['error' => $locale->text('calls.invalid_signal')], 400);
            return;
        }

        $error = CallSignals::store($callId, $userId, $toUserId, $type, $packet, $decoded);
        if ($error !== null) {
            $status = match ($error) {
                'call_not_found' => 404,
                'access_denied' => 403,
                'peer_not_joined' => 409,
                'signal_budget_exceeded' => 429,
                'signal_busy' => 503,
            };
            $response = ['error' => $locale->text('calls.' . $error)];
            if ($status === 503) {
                $response['retry_after_ms'] = 80;
            }
            $this->json($response, $status);
            return;
        }

        $this->json(['success' => true]);
    }

    public function declineCall() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $callId = (int)($_POST['call_id'] ?? 0);
        $user = Auth::user();
        $userId = (int)$user->id;

        if ($callId <= 0) {
            $this->callError('invalid_call', 400);
        }

        $call = Database::query(
            "SELECT c.id, c.chat_id, c.started_by, c.status, ch.chat_number, ch.type AS chat_type
             FROM calls c
             JOIN chats ch ON ch.id = c.chat_id
             WHERE c.id = ?",
            [$callId]
        )->fetch();
        if (!$call) {
            $this->callError('call_not_found', 404);
        }

        $member = Database::query(
            "SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ?",
            [(int)$call->chat_id, $userId]
        )->fetch();
        if (!$member) {
            $this->callError('access_denied', 403);
        }

        $this->upsertActiveParticipant((int)$callId, $userId, 0);

        $leaveStatement = Database::query(
            "UPDATE call_participants
             SET left_at = COALESCE(left_at, NOW())
             WHERE call_id = ?
               AND user_id = ?",
            [$callId, $userId]
        );

        if (Chat::isGroupType($call->chat_type ?? null) && $leaveStatement->rowCount() > 0) {
            $this->appendGroupCallPresenceMessage((int)$call->chat_id, $userId, 'left');
        }

        $ended = false;
        if (!Chat::isGroupType($call->chat_type ?? null) && (string)($call->status ?? '') === 'active') {
            Database::query(
                "UPDATE call_participants
                 SET left_at = COALESCE(left_at, NOW())
                 WHERE call_id = ?
                   AND left_at IS NULL",
                [$callId]
            );

            $endStatement = Database::query(
                "UPDATE calls
                 SET status = 'ended',
                     ended_at = COALESCE(ended_at, NOW())
                 WHERE id = ?
                   AND status = 'active'",
                [$callId]
            );

            if ($endStatement->rowCount() > 0) {
                $ended = true;
                $this->appendPrivateCallDeclinedMessage((int)$call->chat_id, $userId);
            }
        }

        $notifiedCaller = false;
        if ((int)$call->started_by > 0 && (int)$call->started_by !== $userId && (string)($call->status ?? '') === 'active') {
            $locale = DisplayPreferences::localeForRecipient((int) $call->started_by);
            Notification::create(
                (int)$call->started_by,
                'call',
                $locale->text('notifications.call_declined_title'),
                $locale->text('notifications.call_declined_message', ['username' => (string) $user->username]),
                '/c/' . User::formatUserNumber((int)$call->chat_number)
            );
            $notifiedCaller = true;
        }

        $this->json(['success' => true, 'notified_caller' => $notifiedCaller, 'ended' => $ended]);
    }

    public function endCall() {
        Auth::requireAuth();
        Auth::csrfValidate();
        $callId = (int)($_POST['call_id'] ?? 0);
        $userId = Auth::user()->id;

        $call = Database::query(
            "SELECT c.id, c.chat_id, c.status, ch.type AS chat_type
             FROM calls c
             JOIN chats ch ON ch.id = c.chat_id
             WHERE c.id = ?",
            [$callId]
        )->fetch();
        if (!$call) {
            $this->callError('call_not_found', 404);
        }

        $member = Database::query("SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ?", [$call->chat_id, $userId])->fetch();
        if (!$member) {
            $this->callError('access_denied', 403);
        }

                $leaveStatement = Database::query(
            "UPDATE call_participants
             SET left_at = COALESCE(left_at, NOW())
             WHERE call_id = ?
               AND user_id = ?",
            [$callId, $userId]
        );

        if ((string)($call->status ?? '') !== 'active') {
            $this->json(['success' => true, 'ended' => true]);
        }

        if (Chat::isGroupType($call->chat_type ?? null)) {
            if ($leaveStatement->rowCount() > 0) {
                $this->appendGroupCallPresenceMessage((int)$call->chat_id, (int)$userId, 'left');
            }
            $this->json(['success' => true, 'ended' => $this->closeEmptyGroupCall((int)$callId)]);
        }

        $remainingParticipants = (int)Database::query(
            "SELECT COUNT(DISTINCT user_id)
             FROM call_participants
             WHERE call_id = ?
               AND left_at IS NULL",
            [$callId]
        )->fetchColumn();

        $ended = false;
        if ($remainingParticipants <= 1) {
            Database::query(
                "UPDATE call_participants
                 SET left_at = COALESCE(left_at, NOW())
                 WHERE call_id = ?
                   AND left_at IS NULL",
                [$callId]
            );

            $endStatement = Database::query(
                "UPDATE calls
                 SET status = 'ended',
                     ended_at = COALESCE(ended_at, NOW())
                 WHERE id = ?
                   AND status = 'active'",
                [$callId]
            );

            if ($endStatement->rowCount() > 0) {
                $ended = true;
                $this->appendCallHistoryMessage($callId);
            }
        }

        $this->json(['success' => true, 'ended' => $ended]);
    }

    public function leaveCall() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $callId = (int)($_POST['call_id'] ?? 0);
        $userId = Auth::user()->id;

        if ($callId <= 0) {
            $this->callError('invalid_call', 400);
            return;
        }

        $call = Database::query(
            "SELECT c.id, c.chat_id, ch.type AS chat_type
             FROM calls c
             JOIN chats ch ON ch.id = c.chat_id
             WHERE c.id = ?
               AND c.status = 'active'",
            [$callId]
        )->fetch();

        if (!$call) {
            $this->json(['success' => true]);
            return;
        }

        $leaveStatement = Database::query(
            "UPDATE call_participants
             SET left_at = COALESCE(left_at, NOW())
             WHERE call_id = ? AND user_id = ? AND left_at IS NULL",
            [$callId, $userId]
        );

        if (Chat::isGroupType($call->chat_type ?? null) && $leaveStatement->rowCount() > 0) {
            $this->appendGroupCallPresenceMessage((int)$call->chat_id, (int)$userId, 'left');
            $this->closeEmptyGroupCall((int)$callId);
        }

        $this->json(['success' => true]);
    }

    public function pokeMember() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $user = Auth::user();
        $userId = (int)$user->id;

        if ($chatId <= 0 || $targetUserId <= 0) {
            $this->callError('invalid_poke', 400);
        }

        if ($targetUserId === $userId) {
            $this->callError('cannot_poke_self', 400);
        }

        $chat = Database::query(
            "SELECT id, chat_number, type
             FROM chats
             WHERE id = ?",
            [$chatId]
        )->fetch();
        if (!$chat) {
            $this->callError('chat_not_found', 404);
        }

        if (!Chat::isGroupType($chat->type ?? null)) {
            $this->callError('pokes_require_group', 400);
        }

        $isRequesterMember = Database::query(
            "SELECT id
             FROM chat_members
             WHERE chat_id = ?
               AND user_id = ?
             LIMIT 1",
            [$chatId, $userId]
        )->fetch();
        if (!$isRequesterMember) {
            $this->callError('access_denied', 403);
        }

        $isTargetMember = Database::query(
            "SELECT id
             FROM chat_members
             WHERE chat_id = ?
               AND user_id = ?
             LIMIT 1",
            [$chatId, $targetUserId]
        )->fetch();
        if (!$isTargetMember) {
            $this->callError('peer_not_in_group', 404);
        }

        $activeCall = Database::query(
            "SELECT id
             FROM calls
             WHERE chat_id = ?
               AND status = 'active'
             ORDER BY id DESC
             LIMIT 1",
            [$chatId]
        )->fetch();
        if (!$activeCall) {
            $this->callError('no_active_group_call', 409);
        }

        $callId = (int)($activeCall->id ?? 0);
        if ($callId <= 0 || !$this->isUserActivelyInCall($callId, $userId)) {
            $this->callError('join_before_poking', 409);
        }

        if ($this->isUserActivelyInCall($callId, $targetUserId)) {
            $this->callError('peer_already_in_call', 409);
        }

        $locale = DisplayPreferences::localeForRecipient($targetUserId);
        $senderUsername = trim((string) ($user->username ?? ''));
        if ($senderUsername === '') {
            $senderUsername = $locale->text('notifications.someone');
        }

        Notification::create(
            $targetUserId,
            'poke',
            $locale->text('notifications.call_poke_title'),
            $locale->text('notifications.call_poke_message', ['username' => $senderUsername]),
            '/c/' . User::formatUserNumber((int)$chat->chat_number) . '?join_call=1&join_source=poke&call_id=' . $callId
        );

        $this->json(['success' => true]);
    }
}
