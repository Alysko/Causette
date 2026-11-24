<?php
use Causette\Chat\GroupHistoryText;
use Causette\Chat\PollDraft;
use Causette\Http\ChatResponse;
use Causette\Localization\DisplayPreferences;
use Causette\Localization\Locale;
use Causette\Security\ConversationRead;
use Causette\Storage\AttachmentBudget;

require_once __DIR__ . '/../core/AttachmentBudget.php';
require_once __DIR__ . '/../core/ChatResponse.php';
require_once __DIR__ . '/../core/ConversationRead.php';
require_once __DIR__ . '/../core/PollDraft.php';

class ChatController extends Controller {
    private const GROUP_CHAT_USER_LIMIT = 999;
    private const GROUP_VISIBILITY_NONE = 'none';
    private const GROUP_VISIBILITY_REQUESTABLE = 'requestable';
    private const GROUP_VISIBILITY_PUBLIC = 'public';

    private function supportsChatMemberRole(): bool {
        static $supports = null;
        if ($supports !== null) {
            return $supports;
        }

        $result = Database::query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_members' AND COLUMN_NAME = 'role'"
        )->fetchColumn();

        $supports = ((int)$result) > 0;
        return $supports;
    }

    private function supportsChatMemberMute(): bool {
        static $supports = null;
        if ($supports !== null) {
            return $supports;
        }

        $result = Database::query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_members' AND COLUMN_NAME = 'is_muted'"
        )->fetchColumn();

        $supports = ((int)$result) > 0;
        return $supports;
    }

    private function supportsGroupVisibility(): bool {
        static $supports = null;
        if ($supports !== null) {
            return $supports;
        }

        $result = Database::query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chats' AND COLUMN_NAME = 'non_member_visibility'"
        )->fetchColumn();

        $supports = ((int)$result) > 0;
        return $supports;
    }

    private function supportsGroupJoinRequests(): bool {
        static $supports = null;
        if ($supports !== null) {
            return $supports;
        }

        $result = Database::query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'group_join_requests'"
        )->fetchColumn();

        $supports = ((int)$result) > 0;
        return $supports;
    }

    private function supportsPolls(): bool {
        return Chat::supportsPolls();
    }

    private function pollTextLength(string $value): int {
        return function_exists('mb_strlen')
            ? (int)mb_strlen($value, 'UTF-8')
            : (int)strlen($value);
    }

    private function normalizePollText($value): string {
        return is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
    }

    private function normalizePollOptions($rawOptions): array {
        $options = is_string($rawOptions) && strlen($rawOptions) <= 8192
            ? json_decode($rawOptions, false, 4) : $rawOptions;
        if (!is_array($options) || !array_is_list($options) || count($options) > PollDraft::MAX_OPTIONS) {
            return [];
        }

        $normalized = [];
        foreach ($options as $option) {
            if (!is_string($option)) {
                return [];
            }
            $value = $this->normalizePollText($option);
            if ($value === '') {
                continue;
            }
            $normalized[] = $value;
        }

        return $normalized;
    }

    private function enrichActivePollPermissions($poll, $chat, int $userId, bool $isCurrentUserAdmin) {
        if (!$poll) {
            return null;
        }

        $canExpire = (int)($poll->creator_user_id ?? 0) === $userId
            || $this->canModerateGroupMembers($chat, $userId, $isCurrentUserAdmin);

        $poll->can_expire = $canExpire;
        return $poll;
    }

    private function appendSystemEvent(int $chatId, string $eventType, string $content): void {
        if (!$this->supportsSystemEvents() || $chatId <= 0 || trim($content) === '') {
            return;
        }

        Database::query(
            "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, ?, ?)",
            [$chatId, $eventType, $content]
        );
    }

    private function buildPollResultsSummary(int $pollId): array {
        if ($pollId <= 0) {
            return [];
        }

        $rows = Database::query(
            "SELECT po.option_text, COUNT(pv.id) AS vote_count
             FROM poll_options po
             LEFT JOIN poll_votes pv ON pv.poll_option_id = po.id AND pv.poll_id = po.poll_id
             WHERE po.poll_id = ?
             GROUP BY po.id, po.option_text, po.sort_order
             ORDER BY po.sort_order ASC",
            [$pollId]
        )->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private function getGroupVisibility($chat): string {
        $value = strtolower(trim((string)($chat->non_member_visibility ?? self::GROUP_VISIBILITY_NONE)));
        if ($value === self::GROUP_VISIBILITY_REQUESTABLE || $value === self::GROUP_VISIBILITY_PUBLIC) {
            return $value;
        }

        return self::GROUP_VISIBILITY_NONE;
    }

    private function getGroupMember(int $chatId, int $userId) {
        if ($chatId <= 0 || $userId <= 0) {
            return null;
        }

        return Database::query(
            "SELECT * FROM chat_members WHERE chat_id = ? AND user_id = ? LIMIT 1",
            [$chatId, $userId]
        )->fetch();
    }

    private function isGroupOwner($chat, int $userId): bool {
        if (!$chat || $userId <= 0) {
            return false;
        }

        return (int)($chat->created_by ?? 0) === $userId;
    }

    private function isGroupModeratorByMember($member): bool {
        if (!$member || !$this->supportsChatMemberRole()) {
            return false;
        }

        return strtolower(trim((string)($member->role ?? 'member'))) === 'moderator';
    }

    private function canManageGroupSettings($chat, int $userId, bool $isAdmin): bool {
        return $isAdmin || $this->isGroupOwner($chat, $userId);
    }

    private function canModerateGroupMembers($chat, int $userId, bool $isAdmin): bool {
        if ($isAdmin || $this->isGroupOwner($chat, $userId)) {
            return true;
        }

        $member = $this->getGroupMember((int)($chat->id ?? 0), $userId);
        return $this->isGroupModeratorByMember($member);
    }

    private function canApproveGroupJoinRequests($chat, int $userId, bool $isAdmin): bool {
        return $this->canModerateGroupMembers($chat, $userId, $isAdmin);
    }

    private function canAddGroupMembers($chat, int $userId, bool $isAdmin): bool {
        return $this->canManageGroupSettings($chat, $userId, $isAdmin);
    }

    private function isGroupMemberMuted(int $chatId, int $userId): bool {
        if (!$this->supportsChatMemberMute() || $chatId <= 0 || $userId <= 0) {
            return false;
        }

        $isMuted = (int)Database::query(
            "SELECT is_muted FROM chat_members WHERE chat_id = ? AND user_id = ? LIMIT 1",
            [$chatId, $userId]
        )->fetchColumn();

        return $isMuted === 1;
    }

    private function getGroupJoinRequestStatus(int $chatId, int $userId): string {
        if (!$this->supportsGroupJoinRequests() || $chatId <= 0 || $userId <= 0) {
            return '';
        }

        $status = (string)Database::query(
            "SELECT status FROM group_join_requests WHERE chat_id = ? AND requester_user_id = ? LIMIT 1",
            [$chatId, $userId]
        )->fetchColumn();

        return strtolower(trim($status));
    }

    private function getPendingGroupJoinRequests(int $chatId): array {
        if (!$this->supportsGroupJoinRequests() || $chatId <= 0) {
            return [];
        }

        return Database::query(
            "SELECT gjr.id, gjr.chat_id, gjr.requester_user_id, gjr.status, gjr.created_at,
                    u.username, u.user_number, u.avatar_filename
             FROM group_join_requests gjr
             JOIN users u ON u.id = gjr.requester_user_id
             WHERE gjr.chat_id = ? AND gjr.status = 'pending'
             ORDER BY gjr.created_at ASC",
            [$chatId]
        )->fetchAll();
    }

    private function getGroupStats(int $chatId): array {
        if ($chatId <= 0) {
            return ['member_count' => 0, 'message_count' => 0];
        }

        $memberCount = (int)Database::query(
            "SELECT COUNT(*) FROM chat_members WHERE chat_id = ?",
            [$chatId]
        )->fetchColumn();

        $messageCount = (int)Database::query(
            "SELECT COUNT(*) FROM messages WHERE chat_id = ?",
            [$chatId]
        )->fetchColumn();

        return [
            'member_count' => max(0, $memberCount),
            'message_count' => max(0, $messageCount),
        ];
    }

    private function getPinnedMessageSettingKey(int $chatId): string {
        return 'pinned_message_chat_' . $chatId;
    }

    private function clearPinnedMessageForChat(int $chatId): void {
        Database::query("DELETE FROM settings WHERE `key` = ?", [$this->getPinnedMessageSettingKey($chatId)]);
    }

    private function getPinnedMessageForChat(int $chatId) {
        if ($chatId <= 0) {
            return null;
        }

        $rawPinnedMessageId = (string)(Setting::get($this->getPinnedMessageSettingKey($chatId)) ?? '');
        $pinnedMessageId = (int)$rawPinnedMessageId;
        if ($pinnedMessageId <= 0) {
            return null;
        }

        try {
            $message = Database::query(
                "SELECT m.id, m.chat_id, m.user_id, m.content, m.created_at, m.bot_name,
                        u.username, u.email AS user_email, u.user_number, u.avatar_filename
                 FROM messages m
                 JOIN users u ON u.id = m.user_id
                 WHERE m.id = ? AND m.chat_id = ?
                 LIMIT 1",
                [$pinnedMessageId, $chatId]
            )->fetch();
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'avatar_filename') === false) {
                throw $e;
            }

            $message = Database::query(
                "SELECT m.id, m.chat_id, m.user_id, m.content, m.created_at, m.bot_name,
                        u.username, u.email AS user_email, u.user_number
                 FROM messages m
                 JOIN users u ON u.id = m.user_id
                 WHERE m.id = ? AND m.chat_id = ?
                 LIMIT 1",
                [$pinnedMessageId, $chatId]
            )->fetch();
        }

        if (!$message) {
            $this->clearPinnedMessageForChat($chatId);
            return null;
        }

        $message->username = User::decorateDeletedRetainedUsername($message->username ?? '', $message->user_email ?? null);

        $message->avatar_url = User::avatarUrl($message);
        $messages = [$message];
        Message::attachMentionMaps($messages);

        return ChatResponse::pinned($message);
    }

    private function isAcceptedFriendship(int $userId, int $otherUserId): bool {
        if ($userId <= 0 || $otherUserId <= 0 || $userId === $otherUserId) {
            return false;
        }

        $count = (int)Database::query(
            "SELECT COUNT(*) FROM friends
             WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))
               AND status = 'accepted'",
            [$userId, $otherUserId, $otherUserId, $userId]
        )->fetchColumn();

        return $count > 0;
    }

    private function getPersonalChatPeerUserId(int $chatId, int $userId): int {
        $chat = Database::query("SELECT id, type FROM chats WHERE id = ?", [$chatId])->fetch();
        if (!$chat || Chat::isGroupType($chat->type ?? null)) {
            return 0;
        }

        $member = Database::query(
            "SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ? LIMIT 1",
            [$chatId, $userId]
        )->fetch();
        if (!$member) {
            return 0;
        }

        $peerUserId = (int)Database::query(
            "SELECT cm.user_id
             FROM chat_members cm
             WHERE cm.chat_id = ? AND cm.user_id != ?
             LIMIT 1",
            [$chatId, $userId]
        )->fetchColumn();

        return $peerUserId > 0 ? $peerUserId : 0;
    }

    private function getPersonalChatPeerMember(int $chatId, int $userId) {
        $chat = Database::query("SELECT id, type FROM chats WHERE id = ?", [$chatId])->fetch();
        if (!$chat || Chat::isGroupType($chat->type ?? null)) {
            return null;
        }

        $member = Database::query(
            "SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ? LIMIT 1",
            [$chatId, $userId]
        )->fetch();
        if (!$member) {
            return null;
        }

        $peer = Database::query(
            "SELECT u.id, u.is_banned
             FROM chat_members cm
             JOIN users u ON u.id = cm.user_id
             WHERE cm.chat_id = ? AND cm.user_id != ?
             LIMIT 1",
            [$chatId, $userId]
        )->fetch();

        return $peer ?: null;
    }

    private function getPersonalChatMessageRestrictionReason(int $chatId, int $userId): ?string {
        $peer = $this->getPersonalChatPeerMember($chatId, $userId);
        if (!$peer) {
            return null;
        }

        if ((int)($peer->is_banned ?? 0) === 1) {
            return 'banned_user';
        }

        $peerUserId = (int)($peer->id ?? 0);
        if ($peerUserId > 0 && !$this->isAcceptedFriendship($userId, $peerUserId)) {
            return 'not_friends';
        }

        return null;
    }

    private function canMessageInChat(int $chatId, int $userId): bool {
        return $this->getPersonalChatMessageRestrictionReason($chatId, $userId) === null;
    }

    private function isUserInJoinedActiveCall(int $userId): bool {
        if ($userId <= 0) {
            return false;
        }

        $count = (int)Database::query(
            "SELECT COUNT(*)
             FROM calls c
             JOIN call_participants cp ON cp.call_id = c.id
             WHERE c.status = 'active'
               AND cp.user_id = ?
               AND cp.left_at IS NULL",
            [$userId]
        )->fetchColumn();

        return $count > 0;
    }

    private function supportsLastSeenMessageId(): bool {
        static $supports = null;
        if ($supports !== null) {
            return $supports;
        }

        $result = Database::query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_members' AND COLUMN_NAME = 'last_seen_message_id'"
        )->fetchColumn();

        $supports = ((int)$result) > 0;
        return $supports;
    }

    private function getFirstUnseenMessageId(int $chatId, int $lastSeenMessageId): int {
        $messageId = (int)Database::query(
            "SELECT id FROM messages WHERE chat_id = ? AND id > ? ORDER BY id ASC LIMIT 1",
            [$chatId, $lastSeenMessageId]
        )->fetchColumn();

        return $messageId > 0 ? $messageId : 0;
    }

    private function getLatestMessageId(int $chatId): int {
        $messageId = (int)Database::query(
            "SELECT id FROM messages WHERE chat_id = ? ORDER BY id DESC LIMIT 1",
            [$chatId]
        )->fetchColumn();

        return $messageId > 0 ? $messageId : 0;
    }

    private function supportsChatTitle(): bool {
        static $supports = null;
        if ($supports !== null) {
            return $supports;
        }

        $result = Database::query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chats' AND COLUMN_NAME = 'title'"
        )->fetchColumn();

        $supports = ((int)$result) > 0;
        return $supports;
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

    private function supportsChatSoftDelete(): bool {
        return Chat::supportsSoftDelete();
    }

    private function chatIsSoftDeleted($chat): bool {
        return Chat::isSoftDeleted($chat);
    }

    private function markChatSeenUpToMessageId(int $chatId, int $userId, int $messageId): void {
        if ($messageId <= 0) {
            return;
        }

        Database::query(
            "UPDATE chat_members
             SET last_seen_message_id = CASE
                 WHEN last_seen_message_id IS NULL OR last_seen_message_id < ? THEN ?
                 ELSE last_seen_message_id
             END
             WHERE chat_id = ? AND user_id = ?",
            [$messageId, $messageId, $chatId, $userId]
        );
    }

    public function show($params) {
        Auth::requireAuth();
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isCurrentUserAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';
        $chatNumber = (string)($params['chat_number'] ?? '');
        if (!preg_match('/^\d{4}-\d{4}-\d{4}-\d{4}$/', $chatNumber)) {
            $this->flash('error', 'invalid_chat');
            $this->redirect('/');
        }

        $chat = Database::query("SELECT * FROM chats WHERE chat_number = ?", [str_replace('-', '', $chatNumber)])->fetch();
        if (!$chat) {
            $this->flash('error', 'invalid_chat');
            $this->redirect('/');
        }

        if ($this->chatIsSoftDeleted($chat)) {
            $this->flash('error', 'invalid_chat');
            $this->redirect('/');
        }

        $chat->type = Chat::normalizeType($chat->type ?? null);

        $supportsLastSeen = $this->supportsLastSeenMessageId();
        $supportsChatTitle = $this->supportsChatTitle();

        $isGroupChat = Chat::isGroupType($chat->type ?? null);
        $groupVisibility = $isGroupChat && $this->supportsGroupVisibility()
            ? $this->getGroupVisibility($chat)
            : self::GROUP_VISIBILITY_NONE;

        $userId = $currentUserId;
        $member = $this->getGroupMember((int)$chat->id, $userId);
        $isMember = (bool)$member;
        $isReadOnlyPublicViewer = false;
        $isRequestablePreview = false;

        if (!$isMember) {
            if (!$isGroupChat) {
                $this->flash('error', 'invalid_chat');
                $this->redirect('/');
            }

            if (ConversationRead::allows($chat, false)) {
                $isReadOnlyPublicViewer = true;
            } elseif ($groupVisibility === self::GROUP_VISIBILITY_REQUESTABLE) {
                $isRequestablePreview = true;
            } else {
                $this->flash('error', 'invalid_chat');
                $this->redirect('/');
            }
        }

        $lastSeenMessageId = ($isMember && $supportsLastSeen) ? (int)($member->last_seen_message_id ?? 0) : 0;
        $firstUnseenMessageId = ($isMember && $supportsLastSeen) ? $this->getFirstUnseenMessageId((int)$chat->id, $lastSeenMessageId) : 0;

        $members = [];
        if ($isMember || $isReadOnlyPublicViewer) {
            $members = Database::query(
                "SELECT u.id, u.username, u.user_number, u.avatar_filename, u.presence_status, u.last_active_at,
                        cm.role AS group_role,
                        cm.is_muted AS is_group_muted,
                        CASE
                            WHEN u.id = ? THEN 0
                            WHEN af.id IS NULL THEN 0
                            ELSE 1
                        END AS is_friend
                 FROM chat_members cm
                 JOIN users u ON u.id = cm.user_id
                 LEFT JOIN friends af
                   ON (
                        ((af.user_id = ? AND af.friend_id = u.id)
                         OR (af.friend_id = ? AND af.user_id = u.id))
                        AND af.status = 'accepted'
                      )
                 WHERE cm.chat_id = ?
                             ORDER BY CASE WHEN u.id = ? THEN 0 ELSE 1 END ASC, u.username ASC",
                            [$currentUserId, $currentUserId, $currentUserId, $chat->id, (int)$chat->created_by]
            )->fetchAll();

            $activeCallParticipantMap = [];
            if ($isGroupChat && !empty($members)) {
                $activeParticipantIds = Database::query(
                    "SELECT DISTINCT cp.user_id
                     FROM calls c
                     JOIN call_participants cp ON cp.call_id = c.id
                     WHERE c.chat_id = ?
                       AND c.status = 'active'
                       AND cp.left_at IS NULL",
                    [(int)$chat->id]
                )->fetchAll(PDO::FETCH_COLUMN);

                foreach ($activeParticipantIds as $activeParticipantId) {
                    $activeCallParticipantMap[(int)$activeParticipantId] = true;
                }
            }

            foreach ($members as $m) {
                $m->formatted_user_number = User::formatUserNumber($m->user_number);
                $m->avatar_url = User::avatarUrl($m);
                User::attachEffectiveStatus($m);
                $m->is_in_group_call = isset($activeCallParticipantMap[(int)($m->id ?? 0)]) ? 1 : 0;
            }
        }

        $chatTitle = Locale::current()->text('browser.sidebar_chat_number', ['number' => User::formatUserNumber($chat->chat_number)]);
        if ($chat->type === 'personal') {
            $customTitle = $supportsChatTitle ? trim((string)($chat->title ?? '')) : '';
            if ($customTitle !== '') {
                $chatTitle = $customTitle;
            }

            $other = Database::query(
                "SELECT u.username
                 FROM chat_members cm
                 JOIN users u ON u.id = cm.user_id
                 WHERE cm.chat_id = ? AND cm.user_id != ?
                 LIMIT 1",
                [$chat->id, $currentUserId]
            )->fetch();
            if ($customTitle === '' && $other && isset($other->username)) {
                $chatTitle = $other->username;
            }
        } elseif ($chat->type === 'group') {
            $customTitle = $supportsChatTitle ? trim((string)($chat->title ?? '')) : '';
            if ($customTitle !== '') {
                $chatTitle = $customTitle;
            } else {
                $chatTitle = User::formatUserNumber($chat->chat_number);
            }
        }

        $messages = [];
        if ($isMember || $isReadOnlyPublicViewer) {
            Attachment::cleanupExpiredForChat((int)$chat->id);
            try {
                $messages = Database::query(
                    "SELECT m.*, u.username, u.email AS user_email, u.user_number, u.avatar_filename, u.presence_status, u.last_active_at,
                            qu.username AS quoted_username, qu.user_number AS quoted_user_number, qm.bot_name AS quoted_bot_name
                     FROM messages m
                     JOIN users u ON m.user_id = u.id
                     LEFT JOIN users qu ON qu.id = m.quoted_user_id
                     LEFT JOIN messages qm ON qm.id = m.quoted_message_id AND qm.chat_id = m.chat_id
                     WHERE m.chat_id = ?
                     ORDER BY m.created_at DESC, m.id DESC
                     LIMIT 100",
                    [$chat->id]
                )->fetchAll();
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'avatar_filename') === false) {
                    throw $e;
                }

                $messages = Database::query(
                    "SELECT m.*, u.username, u.email AS user_email, u.user_number, u.presence_status, u.last_active_at,
                            qu.username AS quoted_username, qu.user_number AS quoted_user_number, qm.bot_name AS quoted_bot_name
                     FROM messages m
                     JOIN users u ON m.user_id = u.id
                     LEFT JOIN users qu ON qu.id = m.quoted_user_id
                     LEFT JOIN messages qm ON qm.id = m.quoted_message_id AND qm.chat_id = m.chat_id
                     WHERE m.chat_id = ?
                     ORDER BY m.created_at DESC, m.id DESC
                     LIMIT 100",
                    [$chat->id]
                )->fetchAll();
            }
        }
        foreach ($messages as $message) {
            if (!empty($message->bot_name)) {
                $message->username = $message->bot_name;
            } else {
                $message->username = User::decorateDeletedRetainedUsername($message->username ?? '', $message->user_email ?? null);
            }
            $message->avatar_url = User::avatarUrl($message);
            User::attachEffectiveStatus($message);
        }
        Message::attachMentionMaps($messages);
        Message::attachQuoteMentionMaps($messages);
        Message::attachReactions($messages, (int)$currentUserId);
        Attachment::attachSubmittedToMessages($messages);

        if ($this->supportsSystemEvents()) {
            $systemEvents = Database::query(
                "SELECT id, chat_id, event_type, content, created_at, 1 AS is_system_event
                 FROM chat_system_events WHERE chat_id = ? ORDER BY created_at DESC, id DESC LIMIT 100",
                [(int)$chat->id]
            )->fetchAll();

            $combined = array_merge($messages, $systemEvents);
            usort($combined, function ($a, $b) {
                $timeCmp = strcmp((string)($b->created_at ?? ''), (string)($a->created_at ?? ''));
                if ($timeCmp !== 0) {
                    return $timeCmp;
                }

                $aIsSystem = !empty($a->is_system_event) ? 1 : 0;
                $bIsSystem = !empty($b->is_system_event) ? 1 : 0;
                if ($aIsSystem !== $bIsSystem) {
                    return $bIsSystem <=> $aIsSystem;
                }

                return ((int)($b->id ?? 0)) <=> ((int)($a->id ?? 0));
            });
            $messages = array_slice($combined, 0, 100);
        }

        $messageRestrictionReason = $this->getPersonalChatMessageRestrictionReason((int)$chat->id, (int)$currentUserId);
        $canSendMessages = $messageRestrictionReason === null;
        $canStartCalls = $messageRestrictionReason !== 'banned_user';
        if ($isGroupChat) {
            $messageRestrictionReason = '';
            $canStartCalls = $isMember;

            if (!$isMember) {
                $canSendMessages = false;
                $messageRestrictionReason = $isReadOnlyPublicViewer
                    ? 'group_read_only_public'
                    : 'group_members_only';
            } else {
                $isMuted = $this->isGroupMemberMuted((int)$chat->id, (int)$currentUserId);
                $canSendMessages = !$isMuted;
                if ($isMuted) {
                    $messageRestrictionReason = 'group_muted';
                }
            }
        }
        $isUserInActiveCall = $this->isUserInJoinedActiveCall((int)$currentUserId);

        if ($supportsLastSeen && $isMember) {
            $latestMessageId = $this->getLatestMessageId((int)$chat->id);
            $this->markChatSeenUpToMessageId((int)$chat->id, (int)$currentUserId, $latestMessageId);
        }

        $pendingAttachments = $isMember
            ? Attachment::listPendingForChatUser((int)$chat->id, (int)$currentUserId)
            : [];
        $pinnedMessage = ($isMember || $isReadOnlyPublicViewer)
            ? $this->getPinnedMessageForChat((int)$chat->id)
            : null;
        $activePoll = null;
        if ($isGroupChat && ($isMember || $isReadOnlyPublicViewer) && $this->supportsPolls()) {
            $activePoll = Chat::getActivePollForChat((int)$chat->id, (int)$currentUserId);
            $activePoll = $this->enrichActivePollPermissions($activePoll, $chat, (int)$currentUserId, $isCurrentUserAdmin);
        }

        $groupEditWindow = 'never';
        $groupDeleteWindow = 'never';
        if (Chat::isGroupType($chat->type ?? null)) {
            $groupEditWindow = $this->getGroupMessageWindow('edit', (int)$chat->id);
            $groupDeleteWindow = $this->getGroupMessageWindow('delete', (int)$chat->id);
        }

        $currentUserJoinRequestStatus = '';
        $pendingGroupJoinRequests = [];
        $canApproveJoinRequests = false;
        $groupStats = $this->getGroupStats((int)$chat->id);

        if ($isGroupChat) {
            $currentUserJoinRequestStatus = $this->getGroupJoinRequestStatus((int)$chat->id, (int)$currentUserId);
            $canApproveJoinRequests = $isMember && $this->canApproveGroupJoinRequests($chat, (int)$currentUserId, $isCurrentUserAdmin);
            if ($canApproveJoinRequests) {
                $pendingGroupJoinRequests = $this->getPendingGroupJoinRequests((int)$chat->id);
            }
        }

        $quotedIds = Database::query(
            "SELECT DISTINCT quoted_message_id FROM messages WHERE chat_id = ? AND quoted_message_id IS NOT NULL",
            [(int)$chat->id]
        )->fetchAll(PDO::FETCH_COLUMN);
        $quotedIdSet = array_flip($quotedIds ?: []);
        foreach ($messages as $message) {
            if (!($message->is_system_event ?? false)) {
                $message->is_quoted = isset($quotedIdSet[(int)$message->id]);
                $message->has_attachments = !empty($message->attachments) && is_array($message->attachments) && count($message->attachments) > 0;
            }
        }

        $this->view('chat', [
            'chat' => $chat,
            'chatTitle' => $chatTitle,
            'messages' => ChatResponse::messages(array_reverse($messages)),
            'members' => $members,
            'pendingAttachments' => $pendingAttachments,
            'currentUserId' => $currentUserId,
            'isCurrentUserAdmin' => $isCurrentUserAdmin,
            'isGroupMember' => $isMember,
            'groupVisibility' => $groupVisibility,
            'isReadOnlyPublicViewer' => $isReadOnlyPublicViewer,
            'isRequestablePreview' => $isRequestablePreview,
            'currentUserJoinRequestStatus' => $currentUserJoinRequestStatus,
            'canApproveJoinRequests' => $canApproveJoinRequests,
            'pendingGroupJoinRequests' => $pendingGroupJoinRequests,
            'groupMemberCount' => (int)($groupStats['member_count'] ?? 0),
            'groupMessageCount' => (int)($groupStats['message_count'] ?? 0),
            'firstUnseenMessageId' => $firstUnseenMessageId,
            'canSendMessages' => $canSendMessages,
            'messageRestrictionReason' => $messageRestrictionReason,
            'canStartCalls' => $canStartCalls,
            'isUserInActiveCall' => $isUserInActiveCall,
            'pinnedMessage' => $pinnedMessage,
            'activePoll' => $activePoll,
            'canCreatePoll' => $isGroupChat && $isMember && $this->supportsPolls(),
            'groupEditWindow' => $groupEditWindow,
            'groupDeleteWindow' => $groupDeleteWindow,
            'csrf' => $this->csrfToken()
        ]);
    }

    public function createGroupPoll() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!$this->supportsPolls()) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.polls_are_not_available_until_the_database_is_updated')], 503);
        }

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $question = $this->normalizePollText($_POST['question'] ?? '');
        $expirySeconds = PollDraft::expirySeconds($_POST['expiry'] ?? null);
        $options = $this->normalizePollOptions($_POST['options'] ?? ($_POST['options_json'] ?? []));
        $user = Auth::user();
        $userId = (int)$user->id;
        $isCurrentUserAdmin = strtolower(trim((string)($user->role ?? 'user'))) === 'admin';

        if ($chatId <= 0 || $question === '') {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        if ($this->pollTextLength($question) > PollDraft::MAX_TEXT_LENGTH) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.poll_question_must_be_40_characters_or_fewer')], 400);
        }

        if (count($options) < PollDraft::MIN_OPTIONS) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.poll_must_have_between_2_and_10_options')], 400);
        }

        $seenOptions = [];
        foreach ($options as $option) {
            if ($this->pollTextLength($option) > PollDraft::MAX_TEXT_LENGTH) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.each_poll_option_must_be_40_characters_or_fewer')], 400);
            }

            $dedupeKey = function_exists('mb_strtolower') ? mb_strtolower($option, 'UTF-8') : strtolower($option);
            if (isset($seenOptions[$dedupeKey])) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.poll_options_must_be_unique')], 400);
            }
            $seenOptions[$dedupeKey] = true;
        }

        if ($expirySeconds === null) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('chat.poll_duration_invalid', [
                'hours' => PollDraft::MAX_HOURS,
                'days' => PollDraft::MAX_DAYS,
            ])], 400);
        }

        $chat = $this->supportsChatSoftDelete()
            ? Database::query("SELECT id, type, created_by, deleted_at FROM chats WHERE id = ?", [$chatId])->fetch()
            : Database::query("SELECT id, type, created_by FROM chats WHERE id = ?", [$chatId])->fetch();
        if (!$chat || $this->chatIsSoftDeleted($chat) || !Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_chat_not_found')], 404);
        }

        $member = $this->getGroupMember($chatId, $userId);
        if (!$member) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_group_members_can_create_polls')], 403);
        }

        $pdo = Database::getInstance();
        try {
            $pdo->beginTransaction();

            Chat::cleanupExpiredPollsForChat($chatId);

            $activePollId = (int)Database::query(
                "SELECT id FROM polls WHERE chat_id = ? AND status = 'active' AND expires_at > NOW() LIMIT 1 FOR UPDATE",
                [$chatId]
            )->fetchColumn();
            if ($activePollId > 0) {
                $pdo->rollBack();
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_one_active_poll_is_allowed_per_group')], 409);
            }

            Database::query(
                "INSERT INTO polls (chat_id, creator_user_id, question, status, expires_at)
                 VALUES (?, ?, ?, 'active', TIMESTAMPADD(SECOND, ?, CURRENT_TIMESTAMP))",
                [$chatId, $userId, $question, $expirySeconds]
            );

            $pollId = (int)$pdo->lastInsertId();
            foreach ($options as $index => $optionText) {
                Database::query(
                    "INSERT INTO poll_options (poll_id, option_text, sort_order) VALUES (?, ?, ?)",
                    [$pollId, $optionText, $index + 1]
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.unable_to_create_poll')], 500);
        }

        $activePoll = Chat::getActivePollForChat($chatId, $userId);
        $activePoll = $this->enrichActivePollPermissions($activePoll, $chat, $userId, $isCurrentUserAdmin);

        if ($activePoll) {
            $optionLines = [];
            foreach ((array)($activePoll->options ?? []) as $option) {
                $optionText = trim((string)($option->option_text ?? ''));
                if ($optionText === '') {
                    continue;
                }
                $optionLines[] = '• ' . $optionText;
            }

            $creatorName = trim((string)($activePoll->creator_username ?? ($user->username ?? 'A member')));
            $content = 'Poll created by ' . $creatorName . ': ' . (string)$activePoll->question;
            if (!empty($optionLines)) {
                $content .= "\n" . implode("\n", $optionLines);
            }

            $this->appendSystemEvent($chatId, 'poll_created', $content);
        }

        $this->json([
            'success' => true,
            'active_poll' => $activePoll,
        ]);
    }

    public function voteGroupPoll() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!$this->supportsPolls()) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.polls_are_not_available_until_the_database_is_updated')], 503);
        }

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $pollId = (int)($_POST['poll_id'] ?? 0);
        $optionId = (int)($_POST['option_id'] ?? 0);
        $user = Auth::user();
        $userId = (int)$user->id;
        $isCurrentUserAdmin = strtolower(trim((string)($user->role ?? 'user'))) === 'admin';

        if ($chatId <= 0 || $pollId <= 0 || $optionId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chat = $this->supportsChatSoftDelete()
            ? Database::query("SELECT id, type, created_by, deleted_at FROM chats WHERE id = ?", [$chatId])->fetch()
            : Database::query("SELECT id, type, created_by FROM chats WHERE id = ?", [$chatId])->fetch();
        if (!$chat || $this->chatIsSoftDeleted($chat) || !Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_chat_not_found')], 404);
        }

        $member = $this->getGroupMember($chatId, $userId);
        if (!$member) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_group_members_can_vote')], 403);
        }

        Chat::cleanupExpiredPollsForChat($chatId);

        $poll = Database::query(
            "SELECT id, status, expires_at FROM polls WHERE id = ? AND chat_id = ? LIMIT 1",
            [$pollId, $chatId]
        )->fetch();
        if (!$poll) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.poll_not_found')], 404);
        }

        if (strtolower((string)$poll->status) !== 'active') {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.this_poll_is_closed')], 409);
        }

        $pollOption = Database::query(
            "SELECT id FROM poll_options WHERE id = ? AND poll_id = ? LIMIT 1",
            [$optionId, $pollId]
        )->fetch();
        if (!$pollOption) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.poll_option_not_found')], 404);
        }

        try {
            Database::query(
                "INSERT INTO poll_votes (poll_id, poll_option_id, user_id) VALUES (?, ?, ?)",
                [$pollId, $optionId, $userId]
            );
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'uniq_poll_votes_poll_user') !== false) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.you_have_already_voted_in_this_poll')], 409);
            }

            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.unable_to_submit_vote')], 500);
        }

        $activePoll = Chat::getActivePollForChat($chatId, $userId);
        $activePoll = $this->enrichActivePollPermissions($activePoll, $chat, $userId, $isCurrentUserAdmin);

        $this->json([
            'success' => true,
            'active_poll' => $activePoll,
        ]);
    }

    public function expireGroupPoll() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!$this->supportsPolls()) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.polls_are_not_available_until_the_database_is_updated')], 503);
        }

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $pollId = (int)($_POST['poll_id'] ?? 0);
        $user = Auth::user();
        $userId = (int)$user->id;
        $isCurrentUserAdmin = strtolower(trim((string)($user->role ?? 'user'))) === 'admin';

        if ($chatId <= 0 || $pollId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chat = $this->supportsChatSoftDelete()
            ? Database::query("SELECT id, type, created_by, deleted_at FROM chats WHERE id = ?", [$chatId])->fetch()
            : Database::query("SELECT id, type, created_by FROM chats WHERE id = ?", [$chatId])->fetch();
        if (!$chat || $this->chatIsSoftDeleted($chat) || !Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_chat_not_found')], 404);
        }

        $member = $this->getGroupMember($chatId, $userId);
        if (!$member && !$isCurrentUserAdmin) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        $poll = Database::query(
            "SELECT id, creator_user_id, status FROM polls WHERE id = ? AND chat_id = ? LIMIT 1",
            [$pollId, $chatId]
        )->fetch();
        if (!$poll) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.poll_not_found')], 404);
        }

        $canExpire = (int)($poll->creator_user_id ?? 0) === $userId
            || $this->canModerateGroupMembers($chat, $userId, $isCurrentUserAdmin);
        if (!$canExpire) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_the_creator_owner_moderator_or_admin_can_expire_this_poll')], 403);
        }

        if (strtolower((string)($poll->status ?? '')) === 'active') {
            $pollResults = $this->buildPollResultsSummary((int)$poll->id);

            Database::query(
                "UPDATE polls
                 SET status = 'expired',
                     expired_at = COALESCE(expired_at, NOW()),
                     expires_at = LEAST(expires_at, NOW())
                 WHERE id = ?",
                [$pollId]
            );

            $question = trim((string)Database::query(
                "SELECT question FROM polls WHERE id = ? LIMIT 1",
                [$pollId]
            )->fetchColumn());

            $summaryLines = [];
            foreach ($pollResults as $row) {
                $optionText = trim((string)($row->option_text ?? ''));
                $voteCount = (int)($row->vote_count ?? 0);
                if ($optionText === '') {
                    continue;
                }
                $summaryLines[] = '• ' . $optionText . ' — ' . $voteCount . ' vote' . ($voteCount === 1 ? '' : 's');
            }

            $expiredBy = trim((string)($user->username ?? 'A moderator'));
            $content = 'Poll expired by ' . $expiredBy;
            if ($question !== '') {
                $content .= ': ' . $question;
            }
            if (!empty($summaryLines)) {
                $content .= "\n" . implode("\n", $summaryLines);
            }

            $this->appendSystemEvent($chatId, 'poll_expired', $content);
        }

        $activePoll = Chat::getActivePollForChat($chatId, $userId);
        $activePoll = $this->enrichActivePollPermissions($activePoll, $chat, $userId, $isCurrentUserAdmin);

        $this->json([
            'success' => true,
            'active_poll' => $activePoll,
        ]);
    }

    public function sendMessage() {
        Auth::requireAuth();
        Auth::csrfValidate();
        $chatId = (int)($_POST['chat_id'] ?? 0);
        $incomingContent = $_POST['content'] ?? '';
        $rawContent = is_string($incomingContent) ? trim($incomingContent) : '';
        $attachmentIds = $this->parseAttachmentIds($_POST['attachment_ids'] ?? '');
        $quotedMessageId = (int)($_POST['quoted_message_id'] ?? 0);
        $userId = Auth::user()->id;

        if ($chatId <= 0 || !is_string($incomingContent) || ($rawContent === '' && $attachmentIds === [])) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }
        if ($attachmentIds === null) {
            $this->json(['error' => 'attachment_batch_too_large'], 400);
        }

        if (mb_strlen($rawContent) > 16384) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.message_exceeds_the_maximum_length_of_16_384_characters')], 400);
        }

        $member = Database::query("SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ?", [$chatId, $userId])->fetch();
        if (!$member) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT chat_number, type, deleted_at FROM chats WHERE id = ?"
            : "SELECT chat_number, type FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if ($this->chatIsSoftDeleted($chat)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if (Chat::isGroupType($chat->type ?? null) && $this->isGroupMemberMuted($chatId, (int)$userId)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.you_are_muted_in_this_group')], 403);
        }

        $messageRestrictionReason = $this->getPersonalChatMessageRestrictionReason($chatId, (int)$userId);
        if ($messageRestrictionReason === 'banned_user') {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.messaging_target_banned')], 403);
        }
        if ($messageRestrictionReason === 'not_friends') {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.you_can_only_message_friends_in_personal_chats')], 403);
        }

        $content = Message::encodeMentionsForChat($chatId, $rawContent);
        $quotedContent = null;
        $quotedUserId = null;
        $quotedUsername = '';

        if ($quotedMessageId > 0) {
            $quotedMessage = Database::query(
                "SELECT m.id, m.content, m.user_id, u.username
                 FROM messages m
                 JOIN users u ON u.id = m.user_id
                 WHERE m.id = ? AND m.chat_id = ?
                 LIMIT 1",
                [$quotedMessageId, $chatId]
            )->fetch();

            if (!$quotedMessage) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.quoted_message_not_found')], 404);
            }

            $quotedContent = (string)($quotedMessage->content ?? '');
            $quotedUserId = (int)($quotedMessage->user_id ?? 0);
            $quotedUsername = (string)($quotedMessage->username ?? '');
        }

        $insert = static function () use ($chatId, $userId, $content, $quotedMessageId, $quotedUserId, $quotedContent): int {
            Database::query(
                "INSERT INTO messages (chat_id, user_id, content, quoted_message_id, quoted_user_id, quoted_content)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$chatId, $userId, $content, $quotedMessageId > 0 ? $quotedMessageId : null,
                    $quotedUserId > 0 ? $quotedUserId : null, $quotedContent]
            );
            return (int)Database::getInstance()->lastInsertId();
        };
        if ($attachmentIds === []) {
            $insert();
        } else {
            $failure = AttachmentBudget::locked(function () use ($chatId, $userId, $attachmentIds, $insert): ?array {
                if (($denial = $this->attachmentUploadDenial($chatId, (int)$userId)) !== null) {
                    return $denial;
                }
                $connection = Database::getInstance();
                $connection->beginTransaction();
                try {
                    if (!Attachment::markPendingSubmitted($chatId, (int)$userId, $insert(), $attachmentIds)) {
                        return ['error' => 'attachment_unavailable', 'status' => 409];
                    }
                    $connection->commit();
                    return null;
                } finally {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                }
            }, ['error' => 'attachment_upload_busy', 'status' => 503]);
            if ($failure !== null) {
                $this->json(['error' => $failure['error']], $failure['status']);
            }
        }

        // Notify other members
        $members = Database::query("SELECT user_id FROM chat_members WHERE chat_id = ? AND user_id != ?", [$chatId, $userId])->fetchAll();
        DisplayPreferences::preloadRecipients(array_column($members, 'user_id'));
        $notificationPreview = mb_substr($rawContent, 0, 20);
        if (mb_strlen($rawContent) > 20) {
            $notificationPreview .= '…';
        }
        $notificationTitleKey = Chat::isGroupType($chat->type ?? null)
            ? 'notifications.group_message_title'
            : 'notifications.direct_message_title';
        $titleParameters = ['username' => Auth::user()->username];
        $chatPath = '/c/' . User::formatUserNumber($chat->chat_number);
        foreach ($members as $m) {
            $locale = DisplayPreferences::localeForRecipient((int) $m->user_id);
            $preview = $rawContent === '' ? $locale->text('browser.attachment_label') : $notificationPreview;
            Notification::create($m->user_id, 'message', $locale->text($notificationTitleKey, $titleParameters), $preview, $chatPath);
        }

        $this->json(['success' => true]);
    }

    public function uploadAttachment() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $expirySeconds = Attachment::normalizeExpirySeconds($_POST['expiry_seconds'] ?? 0);
        $user = Auth::user();
        $userId = (int)$user->id;

        if ($chatId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $authorize = fn() => $this->attachmentUploadDenial($chatId, $userId);
        if (($denial = $authorize()) !== null) {
            $this->json(['error' => $denial['error']], $denial['status']);
        }
        if (!isset($_FILES['attachment']) || !is_array($_FILES['attachment'])) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.no_file_uploaded')], 400);
        }

        $result = Attachment::createPendingFromUpload($chatId, $user, $_FILES['attachment'], $expirySeconds, $authorize);
        if (!empty($result['success'])) {
            $this->json($result);
        }

        $error = $result['error'] ?? 'attachment_upload_failed';
        $status = $result['status'] ?? match ($error) {
            'attachment_quota_exceeded' => 409,
            'attachment_upload_busy' => 503,
            'attachment_image_too_large', 'attachment_too_large' => 413,
            default => 400,
        };
        $this->json(['error' => $error], $status);
    }

    private function attachmentUploadDenial(int $chatId, int $userId): ?array {
        $member = Database::query("SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ?", [$chatId, $userId])->fetch();
        if (!$member) {
            return ['error' => \Causette\Localization\Locale::current()->text('response.access_denied'), 'status' => 403];
        }

        if ($this->supportsChatSoftDelete()) {
            $chat = Database::query("SELECT id, type, deleted_at FROM chats WHERE id = ?", [$chatId])->fetch();
            if (!$chat || $this->chatIsSoftDeleted($chat)) {
                return ['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found'), 'status' => 404];
            }
        } else {
            $chat = Database::query("SELECT id, type FROM chats WHERE id = ?", [$chatId])->fetch();
        }

        if ($chat && Chat::isGroupType($chat->type ?? null) && $this->isGroupMemberMuted($chatId, $userId)) {
            return ['error' => \Causette\Localization\Locale::current()->text('response.you_are_muted_in_this_group'), 'status' => 403];
        }

        $messageRestrictionReason = $this->getPersonalChatMessageRestrictionReason($chatId, $userId);
        if ($messageRestrictionReason === 'banned_user') {
            return ['error' => \Causette\Localization\Locale::current()->text('response.messaging_target_banned'), 'status' => 403];
        }
        if ($messageRestrictionReason === 'not_friends') {
            return ['error' => \Causette\Localization\Locale::current()->text('response.you_can_only_message_friends_in_personal_chats'), 'status' => 403];
        }

        return null;
    }

    public function deleteAttachment() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $attachmentId = $this->requireMutationId('attachment_id');
        $pendingOnly = ($_POST['pending_only'] ?? null) === '1';
        $locale = Locale::current();
        $authUser = Auth::user();
        if (!$authUser) {
            $this->json(['error' => $locale->text('error.session_expired')], 401);
        }
        $userId = (int)$authUser->id;
        $isAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';

        $archiveCondition = $this->supportsChatSoftDelete()
            ? "AND (a.status <> 'submitted' OR c.deleted_at IS NULL)"
            : '';
        $attachment = Database::query(
            "SELECT a.id, a.chat_id, a.user_id, a.message_id, a.status, a.submitted_at, m.created_at AS message_created_at, c.type
             FROM attachments a
             JOIN chat_members cm ON cm.chat_id = a.chat_id AND cm.user_id = ?
             JOIN chats c ON c.id = a.chat_id
             LEFT JOIN messages m ON m.id = a.message_id
             WHERE a.id = ?
             $archiveCondition
             LIMIT 1",
            [$userId, $attachmentId]
        )->fetch();

        if (!$attachment || ($pendingOnly && $attachment->status !== 'pending')) {
            $this->json(['error' => $pendingOnly ? 'attachment_unavailable' : $locale->text('response.attachment_not_found')], 404);
        }

        $isOwner = (int)$attachment->user_id === $userId;
        if ($attachment->status === 'pending') {
            if (!$isOwner) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
            }

            $deleted = Attachment::deletePendingByIdForUser($attachmentId, $userId);
            if ($deleted === null) {
                $this->json(['error' => 'attachment_upload_busy'], 503);
            }
            if (!$deleted) {
                $this->json(['error' => $pendingOnly ? 'attachment_unavailable' : $locale->text('response.attachment_not_found')], 404);
            }

            $this->json(['success' => true]);
        }

        if ($attachment->status !== 'submitted') {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.attachment_not_found')], 404);
        }

        if (!$isOwner && !$isAdmin) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        $isGroupChat = Chat::isGroupType($attachment->type ?? null);
        if ($isGroupChat && !$isAdmin) {
            $deleteWindow = $this->getGroupMessageWindow('delete', (int)$attachment->chat_id);
            $attachmentTimestamp = (string)($attachment->message_created_at ?? $attachment->submitted_at ?? '');
            if (!$this->isWithinWindow($deleteWindow, $attachmentTimestamp)) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.delete_window_has_expired')], 403);
            }
        }

        if (!Attachment::deleteSubmittedById($attachmentId, 'manual')) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.unable_to_delete_attachment')], 500);
        }

        $this->json(['success' => true]);
    }

    public function updateAttachmentExpiry() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $attachmentId = (int)($_POST['attachment_id'] ?? 0);
        $expirySeconds = Attachment::normalizeExpirySeconds($_POST['expiry_seconds'] ?? 0);
        $userId = (int)Auth::user()->id;

        if ($attachmentId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $updated = Attachment::updatePendingExpiryForUser($attachmentId, $userId, $expirySeconds);
        if (!$updated) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.attachment_not_found')], 404);
        }

        $this->json(['success' => true, 'expiry_seconds' => $expirySeconds]);
    }

    public function getMessages($params) {
        Auth::requireAuth();
        $chatId = (int)($params['chat_id'] ?? 0);
        $userId = Auth::user()->id;

        $supportsLastSeen = $this->supportsLastSeenMessageId();

        $member = Database::query("SELECT * FROM chat_members WHERE chat_id = ? AND user_id = ?", [$chatId, $userId])->fetch();
        if (!$member) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        if ($this->supportsChatSoftDelete()) {
            $chat = Database::query("SELECT id, deleted_at FROM chats WHERE id = ?", [$chatId])->fetch();
            if (!$chat || $this->chatIsSoftDeleted($chat)) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
            }
        }

        Attachment::cleanupExpiredForChat($chatId);

        $lastSeenMessageId = $supportsLastSeen ? (int)($member->last_seen_message_id ?? 0) : 0;

        try {
            $messages = Database::query(
                "SELECT m.id, m.chat_id, m.user_id, m.content, m.created_at,
                        m.quoted_message_id, m.quoted_user_id, m.quoted_content, m.bot_name,
                        u.username, u.email AS user_email, u.user_number, u.avatar_filename, u.presence_status, u.last_active_at,
                        qu.username AS quoted_username, qu.user_number AS quoted_user_number, qm.bot_name AS quoted_bot_name
                 FROM messages m
                 JOIN users u ON m.user_id = u.id
                 LEFT JOIN users qu ON qu.id = m.quoted_user_id
                 LEFT JOIN messages qm ON qm.id = m.quoted_message_id AND qm.chat_id = m.chat_id
                 WHERE m.chat_id = ?
                 ORDER BY m.created_at DESC, m.id DESC
                 LIMIT 200",
                [$chatId]
            )->fetchAll();
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'avatar_filename') === false) {
                throw $e;
            }

            $messages = Database::query(
                "SELECT m.id, m.chat_id, m.user_id, m.content, m.created_at,
                        m.quoted_message_id, m.quoted_user_id, m.quoted_content, m.bot_name,
                        u.username, u.email AS user_email, u.user_number, u.presence_status, u.last_active_at,
                        qu.username AS quoted_username, qu.user_number AS quoted_user_number, qm.bot_name AS quoted_bot_name
                 FROM messages m
                 JOIN users u ON m.user_id = u.id
                 LEFT JOIN users qu ON qu.id = m.quoted_user_id
                 LEFT JOIN messages qm ON qm.id = m.quoted_message_id AND qm.chat_id = m.chat_id
                 WHERE m.chat_id = ?
                 ORDER BY m.created_at DESC, m.id DESC
                 LIMIT 200",
                [$chatId]
            )->fetchAll();
        }

        // Keep API responses chronological while querying the most recent window.
        $messages = array_reverse($messages);

        foreach ($messages as $message) {
            if (!empty($message->bot_name)) {
                $message->username = $message->bot_name;
            } else {
                $message->username = User::decorateDeletedRetainedUsername($message->username ?? '', $message->user_email ?? null);
            }
            $message->avatar_url = User::avatarUrl($message);
            User::attachEffectiveStatus($message);
        }
        Message::attachMentionMaps($messages);
        Message::attachQuoteMentionMaps($messages);
        Message::attachReactions($messages, (int)$userId);
        Attachment::attachSubmittedToMessages($messages);

        $firstUnseenMessageId = 0;
        if ($supportsLastSeen) {
            $firstUnseenMessageId = $this->getFirstUnseenMessageId($chatId, $lastSeenMessageId);
            $latestMessageId = !empty($messages) ? (int)($messages[count($messages) - 1]->id ?? 0) : 0;
            $this->markChatSeenUpToMessageId($chatId, (int)$userId, $latestMessageId);
        }

        $messageRestrictionReason = $this->getPersonalChatMessageRestrictionReason($chatId, (int)$userId);
        $canSendMessage = $messageRestrictionReason === null;
        $canStartCall = $messageRestrictionReason !== 'banned_user';
        $isUserInActiveCall = $this->isUserInJoinedActiveCall((int)$userId);

        if ($this->supportsSystemEvents()) {
            $chatRow = Database::query("SELECT type FROM chats WHERE id = ?", [$chatId])->fetch();
            if ($chatRow && Chat::isGroupType($chatRow->type ?? null)) {
                $systemEvents = Database::query(
                    "SELECT id, chat_id, event_type, content, created_at, 1 AS is_system_event
                     FROM chat_system_events WHERE chat_id = ? ORDER BY created_at DESC, id DESC LIMIT 200",
                    [(int)$chatId]
                )->fetchAll();
                $combined = array_merge($messages, $systemEvents);
                usort($combined, function ($a, $b) {
                    $timeCmp = strcmp((string)($a->created_at ?? ''), (string)($b->created_at ?? ''));
                    if ($timeCmp !== 0) {
                        return $timeCmp;
                    }

                    $aIsSystem = !empty($a->is_system_event) ? 1 : 0;
                    $bIsSystem = !empty($b->is_system_event) ? 1 : 0;
                    if ($aIsSystem !== $bIsSystem) {
                        return $aIsSystem <=> $bIsSystem;
                    }

                    return ((int)($a->id ?? 0)) <=> ((int)($b->id ?? 0));
                });
                $messages = array_values(array_slice($combined, -200));
            }
        }

        $this->json([
            'messages' => ChatResponse::messages($messages),
            'first_unseen_message_id' => $firstUnseenMessageId,
            'can_send_message' => $canSendMessage,
            'can_send_message_reason' => $messageRestrictionReason,
            'can_start_call' => $canStartCall,
            'user_in_active_call' => $isUserInActiveCall
        ]);
    }

    public function reactMessage() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $messageId = (int)($_POST['message_id'] ?? 0);
        $reactionCode = Message::normalizeReactionCode($_POST['reaction_code'] ?? '');
        $userId = (int)Auth::user()->id;

        if ($messageId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }
        if ($reactionCode === '') {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_reaction')], 400);
        }

        $message = Database::query(
            "SELECT m.id, m.chat_id
             FROM messages m
             JOIN chat_members cm ON cm.chat_id = m.chat_id AND cm.user_id = ?
             WHERE m.id = ?
             LIMIT 1",
            [$userId, $messageId]
        )->fetch();
        if (!$message) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.message_not_found')], 404);
        }

        if ($this->supportsChatSoftDelete()) {
            $chat = Database::query("SELECT id, deleted_at FROM chats WHERE id = ?", [(int)$message->chat_id])->fetch();
            if (!$chat || $this->chatIsSoftDeleted($chat)) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.message_not_found')], 404);
            }
        }

        $existing = Database::query(
            "SELECT reaction_code FROM message_reactions WHERE message_id = ? AND user_id = ? LIMIT 1",
            [$messageId, $userId]
        )->fetch();

        $action = 'set';
        if ($existing) {
            $existingCode = Message::normalizeReactionCode($existing->reaction_code ?? '');
            if ($existingCode === $reactionCode) {
                Database::query("DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?", [$messageId, $userId]);
                $action = 'removed';
            } else {
                Database::query(
                    "UPDATE message_reactions SET reaction_code = ? WHERE message_id = ? AND user_id = ?",
                    [$reactionCode, $messageId, $userId]
                );
            }
        } else {
            Database::query(
                "INSERT INTO message_reactions (message_id, user_id, reaction_code) VALUES (?, ?, ?)",
                [$messageId, $userId, $reactionCode]
            );
        }

        $this->json(['success' => true, 'action' => $action]);
    }

    public function createGroup() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isCurrentUserAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';
        $actorUsername = User::normalizeUsername($authUser->username ?? '');
        $memberIds = [$currentUserId => true];

        $chatNumber = Chat::generateUniqueChatNumber();
        Database::query("INSERT INTO chats (chat_number, type, created_by) VALUES (?, 'group', ?)", [$chatNumber, $currentUserId]);
        $chatId = (int)Database::getInstance()->lastInsertId();

        $params = [];
        $values = [];
        foreach (array_keys($memberIds) as $memberId) {
            $values[] = '(?, ?)';
            $params[] = $chatId;
            $params[] = (int)$memberId;
        }
        if ($this->supportsChatMemberRole()) {
            Database::query('INSERT INTO chat_members (chat_id, user_id, role) VALUES (?, ?, ?)', [$chatId, $currentUserId, 'moderator']);
        } else {
            Database::query('INSERT INTO chat_members (chat_id, user_id) VALUES ' . implode(', ', $values), $params);
        }

        if ($this->supportsSystemEvents() && $actorUsername !== '') {
            Database::query(
                "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'group_created', ?)",
                [$chatId, $actorUsername . ' created the group']
            );
        }

        $this->json(['success' => true, 'chat_number' => $chatNumber]);
    }

    public function addGroupMember() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $targetUsername = User::normalizeUsername($_POST['username'] ?? '');
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isCurrentUserAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';
        $actorUsername = User::normalizeUsername($authUser->username ?? '');

        if ($chatId <= 0 || $targetUsername === '') {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, type, created_by, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type, created_by FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if ($this->chatIsSoftDeleted($chat)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if (!Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.personal_chats_cannot_add_or_remove_users')], 403);
        }

        $member = Database::query("SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ?", [$chatId, $currentUserId])->fetch();
        if (!$member) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        if (!$this->canAddGroupMembers($chat, $currentUserId, $isCurrentUserAdmin)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_group_owner_or_admin_can_add_users')], 403);
        }

        $target = User::findByUsername($targetUsername);
        if (!$target) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.user_not_found')], 404);
        }

        $targetUserId = (int)$target->id;
        $existingTargetMember = Database::query(
            "SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ? LIMIT 1",
            [$chatId, $targetUserId]
        )->fetch();

        if (!$existingTargetMember) {
            $memberCount = (int)Database::query(
                "SELECT COUNT(*) FROM chat_members WHERE chat_id = ?",
                [$chatId]
            )->fetchColumn();

            if ($memberCount >= self::GROUP_CHAT_USER_LIMIT) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_user_limit')], 429);
            }
        }

        Database::query("INSERT IGNORE INTO chat_members (chat_id, user_id) VALUES (?, ?)", [$chatId, $targetUserId]);
        if (!$existingTargetMember && $this->supportsLastSeenMessageId()) {
            $latestMessageId = $this->getLatestMessageId($chatId);
            if ($latestMessageId > 0) {
                $this->markChatSeenUpToMessageId($chatId, $targetUserId, $latestMessageId);
            }
        }
        if (!$existingTargetMember && $this->supportsSystemEvents()) {
            $targetDisplayUsername = User::normalizeUsername($target->username ?? '');
            if ($actorUsername !== '' && $targetDisplayUsername !== '') {
                Database::query(
                    "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'user_added', ?)",
                    [$chatId, $actorUsername . ' added ' . $targetDisplayUsername . ' to group']
                );
            }
        }
        $this->json(['success' => true]);
    }

    public function removeGroupMember() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isCurrentUserAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';
        $actorUsername = User::normalizeUsername($authUser->username ?? '');

        if ($chatId <= 0 || $targetUserId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, type, chat_number, created_by, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type, chat_number, created_by FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if ($this->chatIsSoftDeleted($chat)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if (!Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.personal_chats_cannot_add_or_remove_users')], 403);
        }

        $member = Database::query("SELECT id, role FROM chat_members WHERE chat_id = ? AND user_id = ?", [$chatId, $currentUserId])->fetch();
        if (!$member) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        if (!$this->canModerateGroupMembers($chat, $currentUserId, $isCurrentUserAdmin)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        $ownerUserId = (int)($chat->created_by ?? 0);
        if ($ownerUserId > 0 && $ownerUserId === $targetUserId) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_owner_cannot_be_removed')], 403);
        }

        $targetUser = Database::query(
            "SELECT id, username FROM users WHERE id = ? LIMIT 1",
            [$targetUserId]
        )->fetch();
        $targetMember = Database::query(
            "SELECT id, role FROM chat_members WHERE chat_id = ? AND user_id = ? LIMIT 1",
            [$chatId, $targetUserId]
        )->fetch();

        if (!$targetMember) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.user_is_not_in_this_group')], 404);
        }

        $actorIsOwner = $this->isGroupOwner($chat, $currentUserId);
        $targetIsModerator = $this->isGroupModeratorByMember($targetMember);
        if ($targetIsModerator && !$actorIsOwner && !$isCurrentUserAdmin) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_group_owner_can_remove_a_moderator')], 403);
        }

        Database::query("DELETE FROM chat_members WHERE chat_id = ? AND user_id = ?", [$chatId, $targetUserId]);
        if ($targetMember && $this->supportsSystemEvents()) {
            $targetDisplayUsername = User::normalizeUsername($targetUser->username ?? '');
            if ($actorUsername !== '' && $targetDisplayUsername !== '') {
                Database::query(
                    "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'user_removed', ?)",
                    [$chatId, $actorUsername . ' removed ' . $targetDisplayUsername . ' from group']
                );
            }
        }
        $remaining = (int)Database::query("SELECT COUNT(*) FROM chat_members WHERE chat_id = ?", [$chatId])->fetchColumn();
        if ($remaining <= 0) {
            $this->deleteEmptyChat($chatId);
        }

        $this->json(['success' => true]);
    }

    public function leaveGroup() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $newOwnerUserId = (int)($_POST['new_owner_user_id'] ?? 0);
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $actorUsername = User::normalizeUsername($authUser->username ?? '');

        if ($chatId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, type, created_by, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type, created_by FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if ($this->chatIsSoftDeleted($chat)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if (!Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_group_chats_can_be_left')], 403);
        }

        $member = Database::query(
            "SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ? LIMIT 1",
            [$chatId, $currentUserId]
        )->fetch();
        if (!$member) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        if ((int)($chat->created_by ?? 0) === $currentUserId) {
            $eligibleNewOwners = Database::query(
                "SELECT cm.user_id, u.username
                 FROM chat_members cm
                 JOIN users u ON u.id = cm.user_id
                 WHERE cm.chat_id = ? AND cm.user_id != ?
                 ORDER BY u.username ASC",
                [$chatId, $currentUserId]
            )->fetchAll();

            if (count($eligibleNewOwners) === 0) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.you_cannot_leave_a_group_you_own_with_no_other_members_delete_the_group_instead')], 403);
            }

            $newOwnerIsEligible = false;
            foreach ($eligibleNewOwners as $eligibleNewOwner) {
                if ((int)($eligibleNewOwner->user_id ?? 0) === $newOwnerUserId) {
                    $newOwnerIsEligible = true;
                    break;
                }
            }

            if (!$newOwnerIsEligible) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.choose_a_current_group_member_to_transfer_ownership_before_leaving')], 400);
            }

            Database::query("UPDATE chats SET created_by = ? WHERE id = ?", [$newOwnerUserId, $chatId]);
            if ($this->supportsSystemEvents() && $actorUsername !== '') {
                $newOwnerUsername = (string)Database::query("SELECT username FROM users WHERE id = ?", [$newOwnerUserId])->fetchColumn();
                if ($newOwnerUsername !== '') {
                    Database::query(
                        "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'ownership_transferred', ?)",
                        [$chatId, $actorUsername . ' transferred group ownership to ' . User::normalizeUsername($newOwnerUsername)]
                    );
                }
            }
        }

        Database::query("DELETE FROM chat_members WHERE chat_id = ? AND user_id = ?", [$chatId, $currentUserId]);

        $remaining = (int)Database::query("SELECT COUNT(*) FROM chat_members WHERE chat_id = ?", [$chatId])->fetchColumn();
        if ($remaining > 0 && $this->supportsSystemEvents() && $actorUsername !== '') {
            Database::query(
                "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'user_left', ?)",
                [$chatId, $actorUsername . ' left the group']
            );
        }

        if ($remaining <= 0) {
            $this->deleteEmptyChat($chatId);
        }

        $this->json(['success' => true, 'redirect' => '/']);
    }

    public function renameChat() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!$this->supportsChatTitle()) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_rename_is_not_available_until_the_database_update_is_applied')], 400);
        }

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';

        if ($chatId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        if (mb_strlen($title) > 80) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_name_must_be_80_characters_or_fewer')], 400);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, type, chat_number, title, created_by, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type, chat_number, title, created_by FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if ($this->chatIsSoftDeleted($chat)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if (!Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_group_chats_can_be_renamed')], 403);
        }

        if (!$this->canManageGroupSettings($chat, $currentUserId, $isAdmin)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_the_group_owner_can_rename_this_chat')], 403);
        }

        $member = Database::query("SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ?", [$chatId, $currentUserId])->fetch();
        if (!$member) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        $oldTitle = trim((string)($chat->title ?? ''));
        if ($oldTitle === '') {
            $oldTitle = User::formatUserNumber($chat->chat_number);
        }

        if ($title === '') {
            Database::query("UPDATE chats SET title = NULL WHERE id = ?", [$chatId]);
            $newTitle = User::formatUserNumber($chat->chat_number);
            if ($this->supportsSystemEvents()) {
                Database::query(
                    "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'chat_renamed', ?)",
                    [$chatId, GroupHistoryText::encodeRename($oldTitle, $newTitle)]
                );
            }
            $this->json(['success' => true, 'title' => $newTitle, 'reset' => true]);
        }

        Database::query("UPDATE chats SET title = ? WHERE id = ?", [$title, $chatId]);
        if ($this->supportsSystemEvents()) {
            Database::query(
                "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'chat_renamed', ?)",
                [$chatId, GroupHistoryText::encodeRename($oldTitle, $title)]
            );
        }
        $this->json(['success' => true, 'title' => $title, 'reset' => false]);
    }

    public function deleteGroup() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!$this->supportsChatSoftDelete()) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_delete_requires_database_update_for_chat_trash_support')], 400);
        }

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';

        if ($chatId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chat = Database::query(
            "SELECT id, type, created_by, deleted_at FROM chats WHERE id = ?",
            [$chatId]
        )->fetch();
        if (!$chat) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if ($this->chatIsSoftDeleted($chat)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_already_deleted')], 400);
        }

        if (!Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_group_chats_can_be_deleted')], 403);
        }

        if (!$this->canManageGroupSettings($chat, $currentUserId, $isAdmin)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_the_group_owner_can_delete_this_group')], 403);
        }

        $member = Database::query(
            "SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ? LIMIT 1",
            [$chatId, $currentUserId]
        )->fetch();
        if (!$member) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        if (Chat::supportsDeletedBy()) {
            Database::query(
                "UPDATE chats SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL",
                [$currentUserId, $chatId]
            );
        } else {
            Database::query(
                "UPDATE chats SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL",
                [$chatId]
            );
        }

        $this->json(['success' => true, 'redirect' => '/']);
    }

    public function deletePersonalByNumber($params) {
        Auth::requireAuth();

        $chat = $this->findAccessiblePersonalChatByNumber((string)($params['chat_number'] ?? ''), (int)Auth::user()->id);
        if (!$chat) {
            $this->redirect('/');
        }

        $chatNumberFormatted = User::formatUserNumber((string)$chat->chat_number);
        $supportsChatTitle = $this->supportsChatTitle();
        $chatTitle = Locale::current()->text('ui.personal_chat_number', ['number' => $chatNumberFormatted]);
        $customTitle = $supportsChatTitle ? trim((string)($chat->title ?? '')) : '';
        if ($customTitle !== '') {
            $chatTitle = $customTitle;
        } else {
            $otherUsername = (string)Database::query(
                "SELECT u.username
                 FROM chat_members cm
                 JOIN users u ON u.id = cm.user_id
                 WHERE cm.chat_id = ? AND cm.user_id != ?
                 ORDER BY cm.joined_at ASC
                 LIMIT 1",
                [(int)$chat->id, (int)Auth::user()->id]
            )->fetchColumn();
            $normalizedOtherUsername = trim($otherUsername);
            if ($normalizedOtherUsername !== '') {
                $chatTitle = $normalizedOtherUsername;
            }
        }

        $this->view('chat_delete_confirm', [
            'chat' => $chat,
            'chatNumberFormatted' => $chatNumberFormatted,
            'chatTitle' => $chatTitle,
            'deleteFailed' => ($_GET['error'] ?? null) === 'delete_failed',
            'csrf' => $this->csrfToken()
        ]);
    }

    public function deletePersonalByNumberConfirm($params) {
        Auth::requireAuth();
        Auth::csrfValidate();

        $chat = $this->findAccessiblePersonalChatByNumber((string)($params['chat_number'] ?? ''), (int)Auth::user()->id);
        if (!$chat) {
            $this->redirect('/');
        }

        if (!Attachment::deleteChatWithFiles((int)$chat->id, static fn() => self::deleteChatRows((int)$chat->id))) {
            $this->redirect('/c/' . User::formatUserNumber((string)$chat->chat_number) . '/delete?error=delete_failed');
        }
        $this->redirect('/');
    }

    private function findAccessiblePersonalChatByNumber(string $chatNumber, int $currentUserId) {
        if (!preg_match('/^\d{4}-\d{4}-\d{4}-\d{4}$/', $chatNumber) || $currentUserId <= 0) {
            return null;
        }

        $rawChatNumber = str_replace('-', '', $chatNumber);

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, chat_number, type, title, deleted_at FROM chats WHERE chat_number = ? LIMIT 1"
            : "SELECT id, chat_number, type, title FROM chats WHERE chat_number = ? LIMIT 1";
        $chat = Database::query($chatSelect, [$rawChatNumber])->fetch();
        if (!$chat || $this->chatIsSoftDeleted($chat) || Chat::isGroupType($chat->type ?? null)) {
            return null;
        }

        $member = Database::query(
            "SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ? LIMIT 1",
            [(int)$chat->id, $currentUserId]
        )->fetch();
        if (!$member) {
            return null;
        }

        return $chat;
    }

    private function deleteEmptyChat(int $chatId): void {
        Attachment::deleteChatWithFiles($chatId, static fn() => self::deleteChatRows($chatId),
            static fn() => (int)Database::query('SELECT COUNT(*) FROM chat_members WHERE chat_id = ?', [$chatId])->fetchColumn() === 0);
    }

    private static function deleteChatRows(int $chatId): bool {
        $pdo = Database::getInstance();
        try {
            $pdo->beginTransaction();

            Database::query(
                "DELETE FROM call_participants
                 WHERE call_id IN (SELECT id FROM calls WHERE chat_id = ?)",
                [$chatId]
            );
            try {
                Database::query(
                    "DELETE FROM call_signals
                     WHERE call_id IN (SELECT id FROM calls WHERE chat_id = ?)",
                    [$chatId]
                );
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'call_signals') === false) {
                    throw $e;
                }
            }
            Database::query("DELETE FROM calls WHERE chat_id = ?", [$chatId]);
            Database::query("DELETE FROM chats WHERE id = ?", [$chatId]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }
    }

    public function takeGroupOwnership() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isCurrentUserAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';
        $actorUsername = User::normalizeUsername($authUser->username ?? '');

        if ($chatId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        if (!$isCurrentUserAdmin) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_admins_can_take_group_ownership')], 403);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, type, created_by, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type, created_by FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if ($this->chatIsSoftDeleted($chat)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if (!Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_group_chats_can_change_ownership')], 403);
        }

        $member = Database::query(
            "SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ? LIMIT 1",
            [$chatId, $currentUserId]
        )->fetch();
        if (!$member) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        $ownerUserId = (int)($chat->created_by ?? 0);
        if ($ownerUserId === $currentUserId) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.you_already_own_this_group')], 400);
        }

        Database::query("UPDATE chats SET created_by = ? WHERE id = ?", [$currentUserId, $chatId]);

        if ($this->supportsSystemEvents() && $actorUsername !== '') {
            $eventContent = $actorUsername . ' took group ownership';
            if ($ownerUserId > 0) {
                $previousOwnerUsername = (string)Database::query(
                    "SELECT username FROM users WHERE id = ? LIMIT 1",
                    [$ownerUserId]
                )->fetchColumn();
                $normalizedPreviousOwner = User::normalizeUsername($previousOwnerUsername);
                if ($normalizedPreviousOwner !== '' && $normalizedPreviousOwner !== $actorUsername) {
                    $eventContent .= ' from ' . $normalizedPreviousOwner;
                }
            }

            Database::query(
                "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'ownership_taken', ?)",
                [$chatId, $eventContent]
            );
        }

        $this->json(['success' => true]);
    }

    public function promoteGroupModerator() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!$this->supportsChatMemberRole()) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_roles_require_a_database_update')], 400);
        }

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';

        if ($chatId <= 0 || $targetUserId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, type, created_by, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type, created_by FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat || $this->chatIsSoftDeleted($chat) || !Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if (!$this->canManageGroupSettings($chat, $currentUserId, $isAdmin)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_group_owner_can_promote_moderators')], 403);
        }

        if (!$isAdmin) {
            $actorMember = $this->getGroupMember($chatId, $currentUserId);
            if (!$actorMember) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
            }
        }

        if ((int)($chat->created_by ?? 0) === $targetUserId) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_owner_already_has_elevated_permissions')], 400);
        }

        $targetMember = $this->getGroupMember($chatId, $targetUserId);
        if (!$targetMember) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.user_is_not_in_this_group')], 404);
        }

        Database::query(
            "UPDATE chat_members SET role = 'moderator' WHERE chat_id = ? AND user_id = ?",
            [$chatId, $targetUserId]
        );

        $targetUsername = (string)Database::query("SELECT username FROM users WHERE id = ? LIMIT 1", [$targetUserId])->fetchColumn();
        if ($this->supportsSystemEvents() && trim($targetUsername) !== '') {
            $actorName = User::normalizeUsername($authUser->username ?? '');
            if ($actorName !== '') {
                Database::query(
                    "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'moderator_promoted', ?)",
                    [$chatId, $actorName . ' promoted ' . User::normalizeUsername($targetUsername) . ' to moderator']
                );
            }
        }

        $this->json(['success' => true]);
    }

    public function demoteGroupModerator() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!$this->supportsChatMemberRole()) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_roles_require_a_database_update')], 400);
        }

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';

        if ($chatId <= 0 || $targetUserId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, type, created_by, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type, created_by FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat || $this->chatIsSoftDeleted($chat) || !Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if (!$this->canManageGroupSettings($chat, $currentUserId, $isAdmin)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_group_owner_can_demote_moderators')], 403);
        }

        if (!$isAdmin) {
            $actorMember = $this->getGroupMember($chatId, $currentUserId);
            if (!$actorMember) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
            }
        }

        if ((int)($chat->created_by ?? 0) === $targetUserId) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_owner_role_cannot_be_changed')], 400);
        }

        $targetMember = $this->getGroupMember($chatId, $targetUserId);
        if (!$targetMember) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.user_is_not_in_this_group')], 404);
        }

        Database::query(
            "UPDATE chat_members SET role = 'member' WHERE chat_id = ? AND user_id = ?",
            [$chatId, $targetUserId]
        );

        $targetUsername = (string)Database::query("SELECT username FROM users WHERE id = ? LIMIT 1", [$targetUserId])->fetchColumn();
        if ($this->supportsSystemEvents() && trim($targetUsername) !== '') {
            $actorName = User::normalizeUsername($authUser->username ?? '');
            if ($actorName !== '') {
                Database::query(
                    "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'moderator_demoted', ?)",
                    [$chatId, $actorName . ' removed moderator role from ' . User::normalizeUsername($targetUsername)]
                );
            }
        }

        $this->json(['success' => true]);
    }

    public function muteGroupMember() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!$this->supportsChatMemberMute()) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_mute_requires_a_database_update')], 400);
        }

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';

        if ($chatId <= 0 || $targetUserId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, type, created_by, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type, created_by FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat || $this->chatIsSoftDeleted($chat) || !Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if (!$this->canModerateGroupMembers($chat, $currentUserId, $isAdmin)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        if (!$isAdmin) {
            $actorMember = $this->getGroupMember($chatId, $currentUserId);
            if (!$actorMember) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
            }
        }

        if ((int)($chat->created_by ?? 0) === $targetUserId) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_owner_cannot_be_muted')], 403);
        }

        $targetMember = $this->getGroupMember($chatId, $targetUserId);
        if (!$targetMember) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.user_is_not_in_this_group')], 404);
        }

        $targetIsModerator = $this->isGroupModeratorByMember($targetMember);
        if ($targetIsModerator) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.moderators_cannot_be_muted')], 403);
        }

        Database::query(
            "UPDATE chat_members SET is_muted = 1, muted_by = ?, muted_at = NOW() WHERE chat_id = ? AND user_id = ?",
            [$currentUserId, $chatId, $targetUserId]
        );

        $targetUsername = (string)Database::query("SELECT username FROM users WHERE id = ? LIMIT 1", [$targetUserId])->fetchColumn();
        if ($this->supportsSystemEvents() && trim($targetUsername) !== '') {
            $actorName = User::normalizeUsername($authUser->username ?? '');
            if ($actorName !== '') {
                Database::query(
                    "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'user_muted', ?)",
                    [$chatId, $actorName . ' muted ' . User::normalizeUsername($targetUsername)]
                );
            }
        }

        $this->json(['success' => true]);
    }

    public function unmuteGroupMember() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!$this->supportsChatMemberMute()) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_mute_requires_a_database_update')], 400);
        }

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';

        if ($chatId <= 0 || $targetUserId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, type, created_by, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type, created_by FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat || $this->chatIsSoftDeleted($chat) || !Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if (!$this->canModerateGroupMembers($chat, $currentUserId, $isAdmin)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        if (!$isAdmin) {
            $actorMember = $this->getGroupMember($chatId, $currentUserId);
            if (!$actorMember) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
            }
        }

        $targetMember = $this->getGroupMember($chatId, $targetUserId);
        if (!$targetMember) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.user_is_not_in_this_group')], 404);
        }

        $targetIsModerator = $this->isGroupModeratorByMember($targetMember);
        if ($targetIsModerator && !$this->isGroupOwner($chat, $currentUserId) && !$isAdmin) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_group_owner_can_unmute_a_moderator')], 403);
        }

        Database::query(
            "UPDATE chat_members SET is_muted = 0, muted_by = NULL, muted_at = NULL WHERE chat_id = ? AND user_id = ?",
            [$chatId, $targetUserId]
        );

        $targetUsername = (string)Database::query("SELECT username FROM users WHERE id = ? LIMIT 1", [$targetUserId])->fetchColumn();
        if ($this->supportsSystemEvents() && trim($targetUsername) !== '') {
            $actorName = User::normalizeUsername($authUser->username ?? '');
            if ($actorName !== '') {
                Database::query(
                    "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'user_unmuted', ?)",
                    [$chatId, $actorName . ' unmuted ' . User::normalizeUsername($targetUsername)]
                );
            }
        }

        $this->json(['success' => true]);
    }

    public function requestGroupJoin() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!$this->supportsGroupJoinRequests()) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_join_requests_require_a_database_update')], 400);
        }

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;

        if ($chatId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, type, created_by, chat_number, non_member_visibility, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type, created_by, chat_number, non_member_visibility FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat || $this->chatIsSoftDeleted($chat) || !Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        $visibility = $this->getGroupVisibility($chat);
        if (!in_array($visibility, [self::GROUP_VISIBILITY_REQUESTABLE, self::GROUP_VISIBILITY_PUBLIC], true)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.this_group_does_not_accept_join_requests')], 403);
        }

        $existingMember = $this->getGroupMember($chatId, $currentUserId);
        if ($existingMember) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.you_are_already_a_group_member')], 400);
        }

        $existing = Database::query(
            "SELECT id, status FROM group_join_requests WHERE chat_id = ? AND requester_user_id = ? LIMIT 1",
            [$chatId, $currentUserId]
        )->fetch();

        if ($existing && strtolower((string)($existing->status ?? '')) === 'pending') {
            $this->json(['success' => true, 'status' => 'pending']);
        }

        if ($existing) {
            Database::query(
                "UPDATE group_join_requests
                 SET status = 'pending', handled_by = NULL, handled_at = NULL, updated_at = NOW()
                 WHERE id = ?",
                [(int)$existing->id]
            );
        } else {
            Database::query(
                "INSERT INTO group_join_requests (chat_id, requester_user_id, status) VALUES (?, ?, 'pending')",
                [$chatId, $currentUserId]
            );
        }

        $requesterUsername = User::normalizeUsername($authUser->username ?? '');
        $notifyRows = Database::query(
            "SELECT DISTINCT cm.user_id
             FROM chat_members cm
             JOIN chats c ON c.id = cm.chat_id
             WHERE cm.chat_id = ?
               AND (cm.user_id = c.created_by OR cm.role = 'moderator')",
            [$chatId]
        )->fetchAll();

        $chatPath = '/c/' . User::formatUserNumber((string)$chat->chat_number);
        DisplayPreferences::preloadRecipients(array_column($notifyRows, 'user_id'));
        foreach ($notifyRows as $row) {
            $recipientUserId = (int)($row->user_id ?? 0);
            if ($recipientUserId <= 0) {
                continue;
            }

            $locale = DisplayPreferences::localeForRecipient($recipientUserId);
            Notification::create(
                $recipientUserId,
                'group_join_request',
                $locale->text('notifications.group_join_request_title'),
                $locale->text('notifications.group_join_request_message', [
                    'username' => $requesterUsername !== '' ? $requesterUsername : $locale->text('notifications.a_user'),
                ]),
                $chatPath
            );
        }

        $this->json(['success' => true, 'status' => 'pending']);
    }

    public function cancelGroupJoinRequest() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!$this->supportsGroupJoinRequests()) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_join_requests_require_a_database_update')], 400);
        }

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $currentUserId = (int)Auth::user()->id;

        if ($chatId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        Database::query(
            "UPDATE group_join_requests
             SET status = 'cancelled', handled_by = NULL, handled_at = NULL, updated_at = NOW()
             WHERE chat_id = ? AND requester_user_id = ? AND status = 'pending'",
            [$chatId, $currentUserId]
        );

        $this->json(['success' => true]);
    }

    public function approveGroupJoinRequest() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!$this->supportsGroupJoinRequests()) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_join_requests_require_a_database_update')], 400);
        }

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $requesterUserId = (int)($_POST['user_id'] ?? 0);
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';

        if ($chatId <= 0 || $requesterUserId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, type, created_by, chat_number, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type, created_by, chat_number FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat || $this->chatIsSoftDeleted($chat) || !Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if (!$this->canApproveGroupJoinRequests($chat, $currentUserId, $isAdmin)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        if (!$isAdmin) {
            $actorMember = $this->getGroupMember($chatId, $currentUserId);
            if (!$actorMember) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
            }
        }

        $request = Database::query(
            "SELECT id, status FROM group_join_requests WHERE chat_id = ? AND requester_user_id = ? LIMIT 1",
            [$chatId, $requesterUserId]
        )->fetch();
        if (!$request || strtolower((string)($request->status ?? '')) !== 'pending') {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.join_request_not_found')], 404);
        }

        $memberCount = (int)Database::query(
            "SELECT COUNT(*) FROM chat_members WHERE chat_id = ?",
            [$chatId]
        )->fetchColumn();
        if ($memberCount >= self::GROUP_CHAT_USER_LIMIT) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_user_limit')], 429);
        }

        Database::query(
            "INSERT IGNORE INTO chat_members (chat_id, user_id, role, is_muted) VALUES (?, ?, 'member', 0)",
            [$chatId, $requesterUserId]
        );

        Database::query(
            "UPDATE group_join_requests
             SET status = 'approved', handled_by = ?, handled_at = NOW(), updated_at = NOW()
             WHERE id = ?",
            [$currentUserId, (int)$request->id]
        );

        if ($this->supportsLastSeenMessageId()) {
            $latestMessageId = $this->getLatestMessageId($chatId);
            if ($latestMessageId > 0) {
                $this->markChatSeenUpToMessageId($chatId, $requesterUserId, $latestMessageId);
            }
        }

        $requesterUsername = (string)Database::query("SELECT username FROM users WHERE id = ? LIMIT 1", [$requesterUserId])->fetchColumn();
        $actorUsername = User::normalizeUsername($authUser->username ?? '');
        if ($this->supportsSystemEvents() && $actorUsername !== '' && trim($requesterUsername) !== '') {
            Database::query(
                "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'join_request_approved', ?)",
                [$chatId, $actorUsername . ' approved join request for ' . User::normalizeUsername($requesterUsername)]
            );
        }

        $locale = DisplayPreferences::localeForRecipient($requesterUserId);
        Notification::create(
            $requesterUserId,
            'group_join_approved',
            $locale->text('notifications.group_join_approved_title'),
            $locale->text('notifications.group_join_approved_message'),
            '/c/' . User::formatUserNumber((string)$chat->chat_number)
        );

        $this->json(['success' => true]);
    }

    public function denyGroupJoinRequest() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!$this->supportsGroupJoinRequests()) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.group_join_requests_require_a_database_update')], 400);
        }

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $requesterUserId = (int)($_POST['user_id'] ?? 0);
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';

        if ($chatId <= 0 || $requesterUserId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, type, created_by, chat_number, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type, created_by, chat_number FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat || $this->chatIsSoftDeleted($chat) || !Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if (!$this->canApproveGroupJoinRequests($chat, $currentUserId, $isAdmin)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        if (!$isAdmin) {
            $actorMember = $this->getGroupMember($chatId, $currentUserId);
            if (!$actorMember) {
                $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
            }
        }

        $request = Database::query(
            "SELECT id, status FROM group_join_requests WHERE chat_id = ? AND requester_user_id = ? LIMIT 1",
            [$chatId, $requesterUserId]
        )->fetch();
        if (!$request || strtolower((string)($request->status ?? '')) !== 'pending') {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.join_request_not_found')], 404);
        }

        Database::query(
            "UPDATE group_join_requests
             SET status = 'denied', handled_by = ?, handled_at = NOW(), updated_at = NOW()
             WHERE id = ?",
            [$currentUserId, (int)$request->id]
        );

        $requesterUsername = (string)Database::query("SELECT username FROM users WHERE id = ? LIMIT 1", [$requesterUserId])->fetchColumn();
        $actorUsername = User::normalizeUsername($authUser->username ?? '');
        if ($this->supportsSystemEvents() && $actorUsername !== '' && trim($requesterUsername) !== '') {
            Database::query(
                "INSERT INTO chat_system_events (chat_id, event_type, content) VALUES (?, 'join_request_denied', ?)",
                [$chatId, $actorUsername . ' denied join request for ' . User::normalizeUsername($requesterUsername)]
            );
        }

        $locale = DisplayPreferences::localeForRecipient($requesterUserId);
        Notification::create(
            $requesterUserId,
            'group_join_denied',
            $locale->text('notifications.group_join_denied_title'),
            $locale->text('notifications.group_join_denied_message'),
            '/'
        );

        $this->json(['success' => true]);
    }

    private function getGroupMessageSettingKey(string $type, int $chatId): string {
        return 'group_' . $type . '_window_' . $chatId;
    }

    private function getGroupMessageWindow(string $type, int $chatId): string {
        return (string)(Setting::get($this->getGroupMessageSettingKey($type, $chatId)) ?? 'never');
    }

    private function isWithinWindow(string $window, string $createdAt): bool {
        if ($window === 'never') return false;
        if ($window === 'forever') return true;
        $seconds = (int)$window;
        if ($seconds <= 0) return false;
        $messageTime = strtotime($createdAt);
        if ($messageTime === false) return false;
        return (time() - $messageTime) <= $seconds;
    }

    private function messageIsQuoted(int $messageId, int $chatId): bool {
        return (bool)Database::query(
            "SELECT 1 FROM messages WHERE chat_id = ? AND quoted_message_id = ? LIMIT 1",
            [$chatId, $messageId]
        )->fetch();
    }

    private function requireMutableMessage(int $messageId, string $operation): object {
        $locale = Locale::current();
        $viewer = Auth::user();
        if (!$viewer) {
            $this->json(['error' => $locale->text('error.session_expired')], 401);
        }
        $currentUserId = (int)$viewer->id;
        $isAdmin = strtolower((string)($viewer->role ?? '')) === 'admin';
        $message = Database::query(
            "SELECT m.id, m.chat_id, m.user_id, m.created_at
             FROM messages m
             JOIN chat_members cm ON cm.chat_id = m.chat_id AND cm.user_id = ?
             WHERE m.id = ?
             LIMIT 1",
            [$currentUserId, $messageId]
        )->fetch();
        if (!$message) {
            $this->json(['error' => $locale->text('response.message_not_found')], 404);
        }

        $chatId = (int)$message->chat_id;
        $chat = Database::query($this->supportsChatSoftDelete()
            ? "SELECT id, type, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type FROM chats WHERE id = ?", [$chatId])->fetch();
        if (!$chat || $this->chatIsSoftDeleted($chat)) {
            $this->json(['error' => $locale->text('response.chat_not_found')], 404);
        }

        if ((int)$message->user_id !== $currentUserId && !$isAdmin) {
            $this->json(['error' => $locale->text('response.access_denied')], 403);
        }

        $isEdit = $operation === 'edit';
        if ($this->messageIsQuoted($messageId, $chatId)) {
            $this->json(['error' => $locale->text($isEdit
                ? 'response.cannot_edit_a_message_that_has_been_quoted'
                : 'response.cannot_delete_a_message_that_has_been_quoted')], 403);
        }

        if (Chat::isGroupType($chat->type ?? null) && !$isAdmin) {
            $window = $this->getGroupMessageWindow($operation, $chatId);
            if (!$this->isWithinWindow($window, (string)$message->created_at)) {
                $this->json(['error' => $locale->text($isEdit
                    ? 'response.edit_window_has_expired'
                    : 'response.delete_window_has_expired')], 403);
            }
        }

        return $message;
    }

    private function requireMutationId(string $field): int {
        $id = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->json(['error' => Locale::current()->text('response.invalid_payload')], 400);
        }
        return $id;
    }

    public function editMessage() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $messageId = $this->requireMutationId('message_id');
        $content = $_POST['content'] ?? null;
        $newContent = is_string($content) ? trim($content) : '';
        if ($newContent === '' || !mb_check_encoding($newContent, 'UTF-8')) {
            $this->json(['error' => Locale::current()->text('response.invalid_payload')], 400);
        }
        if (mb_strlen($newContent, 'UTF-8') > 16384) {
            $this->json(['error' => Locale::current()->text('response.message_exceeds_the_maximum_length_of_16_384_characters')], 400);
        }

        $message = $this->requireMutableMessage($messageId, 'edit');
        $encodedContent = Message::encodeMentionsForChat((int)$message->chat_id, $newContent);
        Database::query(
            "UPDATE messages SET content = ?, edited_at = NOW() WHERE id = ?",
            [$encodedContent, $messageId]
        );

        $this->json(['success' => true]);
    }

    public function deleteMessage() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $messageId = $this->requireMutationId('message_id');
        $this->requireMutableMessage($messageId, 'delete');
        if (!Attachment::deleteSubmittedForMessage($messageId)) {
            $this->json(['error' => Locale::current()->text('response.unable_to_delete_attachments_for_this_message')], 500);
        }

        $this->json(['success' => true]);
    }

    public function updateGroupMessageSettings() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $chatId = (int)($_POST['chat_id'] ?? 0);
        $editWindow = trim($_POST['edit_window'] ?? 'never');
        $deleteWindow = trim($_POST['delete_window'] ?? 'never');
        $nonMemberVisibility = strtolower(trim((string)($_POST['non_member_visibility'] ?? self::GROUP_VISIBILITY_NONE)));
        $authUser = Auth::user();
        $currentUserId = (int)$authUser->id;
        $isAdmin = strtolower((string)($authUser->role ?? '')) === 'admin';

        $allowedValues = ['never', '600', '3600', '86400', 'forever'];
        $allowedVisibilityValues = [self::GROUP_VISIBILITY_NONE, self::GROUP_VISIBILITY_REQUESTABLE, self::GROUP_VISIBILITY_PUBLIC];
        if ($chatId <= 0 || !in_array($editWindow, $allowedValues, true) || !in_array($deleteWindow, $allowedValues, true) || !in_array($nonMemberVisibility, $allowedVisibilityValues, true)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $chatSelect = $this->supportsChatSoftDelete()
            ? "SELECT id, type, created_by, non_member_visibility, deleted_at FROM chats WHERE id = ?"
            : "SELECT id, type, created_by, non_member_visibility FROM chats WHERE id = ?";
        $chat = Database::query($chatSelect, [$chatId])->fetch();
        if (!$chat || $this->chatIsSoftDeleted($chat) || !Chat::isGroupType($chat->type ?? null)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.chat_not_found')], 404);
        }

        if (!$this->canManageGroupSettings($chat, $currentUserId, $isAdmin)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        $member = Database::query("SELECT id FROM chat_members WHERE chat_id = ? AND user_id = ?", [$chatId, $currentUserId])->fetch();
        if (!$member) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.access_denied')], 403);
        }

        Setting::set($this->getGroupMessageSettingKey('edit', $chatId), $editWindow);
        Setting::set($this->getGroupMessageSettingKey('delete', $chatId), $deleteWindow);
        if ($this->supportsGroupVisibility()) {
            Database::query("UPDATE chats SET non_member_visibility = ? WHERE id = ?", [$nonMemberVisibility, $chatId]);
        }

        $this->json([
            'success' => true,
            'edit_window' => $editWindow,
            'delete_window' => $deleteWindow,
            'non_member_visibility' => $nonMemberVisibility,
        ]);
    }

    private function parseAttachmentIds(mixed $raw): ?array {
        if (!is_string($raw) || strlen($raw) > AttachmentBudget::MAX_PENDING_COUNT * 24) {
            return null;
        }
        $value = trim($raw);
        if ($value === '') {
            return [];
        }

        $tokens = preg_split('/[^0-9]+/', $value) ?: [];
        $ids = [];
        foreach ($tokens as $token) {
            $id = (int)$token;
            if ($id > 0) {
                $ids[$id] = true;
                if (count($ids) > AttachmentBudget::MAX_PENDING_COUNT) {
                    return null;
                }
            }
        }

        return array_keys($ids);
    }
}
