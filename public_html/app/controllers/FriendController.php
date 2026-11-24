<?php

use Causette\Localization\DisplayPreferences;

class FriendController extends Controller {
    public function sendRequest() {
        Auth::requireAuth();
        Auth::csrfValidate();
        $user = Auth::user();
        $userId = $user->id;
        $submittedNumber = $_POST['user_number'] ?? null;
        if (!is_string($submittedNumber) || !preg_match('/\A(?:[0-9]{16}|[0-9]{4}(?:-[0-9]{4}){3})\z/', $submittedNumber)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_user')], 400);
        }

        $targetNumber = str_replace('-', '', $submittedNumber);
        $target = User::findByUserNumber($targetNumber);
        if (!$target) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_user')], 400);
        }

        if ((int)$target->id === (int)$userId) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.you_cannot_send_a_friend_request_to_yourself')], 400);
        }

        $exists = Database::query("SELECT id FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)", [$userId, $target->id, $target->id, $userId])->fetch();
        if ($exists) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.friend_relationship_already_exists')], 409);
        }

        $inserted = Database::query(
            "INSERT IGNORE INTO friends (user_id, friend_id, status) VALUES (?, ?, 'pending')",
            [$userId, $target->id]
        );
        if ($inserted->rowCount() === 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.friend_relationship_already_exists')], 409);
        }

        $locale = DisplayPreferences::localeForRecipient((int) $target->id);
        Notification::create(
            $target->id,
            'friend_request',
            $locale->text('notifications.friend_request_title'),
            $locale->text('notifications.friend_request_message', ['username' => $user->username]),
            '/?tab=requests&requests=incoming',
        );
        $this->json(['success' => true]);
    }

    public function acceptRequest() {
        Auth::requireAuth();
        Auth::csrfValidate();
        $user = Auth::user();
        $userId = $user->id;
        $requesterId = (int)($_POST['requester_id'] ?? 0);

        if ($requesterId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_requester')], 400);
        }

        $connection = Database::getInstance();
        $connection->beginTransaction();
        try {
            $updated = Database::query(
                "UPDATE friends SET status = 'accepted' WHERE friend_id = ? AND user_id = ? AND status = 'pending'",
                [$userId, $requesterId]
            )->rowCount() === 1;
            $accepted = $updated || Database::query(
                "SELECT id FROM friends WHERE friend_id = ? AND user_id = ? AND status = 'accepted'",
                [$userId, $requesterId]
            )->fetch();
            $chat = $accepted ? Chat::getOrCreatePersonalChat($userId, $userId, $requesterId) : null;
            $connection->commit();
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }

        if (!$chat) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.request_not_found')], 404);
        }

        if ($updated) {
            $locale = DisplayPreferences::localeForRecipient($requesterId);
            Notification::create(
                $requesterId,
                'friend_request_accepted',
                $locale->text('notifications.friend_request_accepted_title'),
                $locale->text('notifications.friend_request_accepted_message', ['username' => $user->username]),
                '/u/' . User::formatUserNumber($user->user_number),
            );
        }

        $this->json(['success' => true, 'chat_number' => $chat->chat_number]);
    }

    public function cancelRequest() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $userId = (int)Auth::user()->id;
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if ($targetUserId <= 0 || $targetUserId === $userId) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_request_target')], 400);
        }

        $result = Database::query(
            "DELETE FROM friends WHERE user_id = ? AND friend_id = ? AND status = 'pending'",
            [$userId, $targetUserId]
        );

        if ($result->rowCount() <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.request_not_found_or_already_accepted')], 404);
        }

        $this->json(['success' => true]);
    }

    public function unfriend() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $userId = (int)Auth::user()->id;
        $targetUserId = (int)($_POST['user_id'] ?? 0);

        if ($targetUserId <= 0 || $targetUserId === $userId) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_user')], 400);
        }

        $result = Database::query(
            "DELETE FROM friends
             WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))
               AND status = 'accepted'",
            [$userId, $targetUserId, $targetUserId, $userId]
        );

        if ($result->rowCount() <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.friendship_not_found')], 404);
        }

        Database::query(
            "DELETE FROM friend_favorites
             WHERE (user_id = ? AND favorite_user_id = ?)
                OR (user_id = ? AND favorite_user_id = ?)",
            [$userId, $targetUserId, $targetUserId, $userId]
        );

        $this->json(['success' => true]);
    }

    public function toggleFavorite() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $userId = (int)Auth::user()->id;
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $makeFavorite = (int)($_POST['favorite'] ?? 0) === 1;

        if ($targetUserId <= 0 || $targetUserId === $userId) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_user')], 400);
        }

        $friendship = Database::query(
            "SELECT id FROM friends
             WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))
               AND status = 'accepted'
             LIMIT 1",
            [$userId, $targetUserId, $targetUserId, $userId]
        )->fetch();

        if (!$friendship) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_accepted_friends_can_be_favorited')], 403);
        }

        if ($makeFavorite) {
            Database::query(
                "INSERT IGNORE INTO friend_favorites (user_id, favorite_user_id) VALUES (?, ?)",
                [$userId, $targetUserId]
            );
        } else {
            Database::query(
                "DELETE FROM friend_favorites WHERE user_id = ? AND favorite_user_id = ?",
                [$userId, $targetUserId]
            );
        }

        $isFavorite = (int)Database::query(
            "SELECT COUNT(*) FROM friend_favorites WHERE user_id = ? AND favorite_user_id = ?",
            [$userId, $targetUserId]
        )->fetchColumn() > 0;

        $this->json(['success' => true, 'is_favorite' => $isFavorite]);
    }
}
