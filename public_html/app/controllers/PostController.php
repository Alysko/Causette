<?php
use Causette\Chat\ReactionCatalog;
use Causette\Localization\DisplayPreferences;

class PostController extends Controller {
    public function create() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $userId = (int)Auth::user()->id;
        $content = Post::normalizeContent($_POST['content'] ?? '');

        if (!Post::isContentValid($content)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.posts_must_be_between_1_and_500_characters')], 400);
        }

        $post = Post::create($userId, $content);
        if (!$post) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.unable_to_create_post')], 500);
        }

        $this->json(['success' => true, 'post_id' => (int)$post->id]);
    }

    public function react() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $userId = (int)Auth::user()->id;
        $postId = (int)($_POST['post_id'] ?? 0);
        $reactionCode = Post::normalizeReactionCode($_POST['reaction_code'] ?? '');

        if ($postId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }
        if ($reactionCode === '') {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_reaction')], 400);
        }

        $post = Post::findById($postId);
        if (!$post) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.post_not_found')], 404);
        }

        $postOwnerId = (int)($post->user_id ?? 0);
        if (!Post::canUserReactToPost($userId, $postOwnerId)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.only_friends_can_react_to_posts')], 403);
        }

        $existing = Database::query(
            "SELECT reaction_code FROM post_reactions WHERE post_id = ? AND user_id = ? LIMIT 1",
            [$postId, $userId]
        )->fetch();

        $action = 'set';
        if ($existing) {
            $existingCode = Post::normalizeReactionCode($existing->reaction_code ?? '');
            if ($existingCode === $reactionCode) {
                Database::query("DELETE FROM post_reactions WHERE post_id = ? AND user_id = ?", [$postId, $userId]);
                $action = 'removed';
            } else {
                Database::query(
                    "UPDATE post_reactions SET reaction_code = ? WHERE post_id = ? AND user_id = ?",
                    [$reactionCode, $postId, $userId]
                );
            }
        } else {
            Database::query(
                "INSERT INTO post_reactions (post_id, user_id, reaction_code) VALUES (?, ?, ?)",
                [$postId, $userId, $reactionCode]
            );
        }

        if ($action === 'set' && $postOwnerId > 0 && $postOwnerId !== $userId) {
            $locale = DisplayPreferences::localeForRecipient($postOwnerId);
            $actorUsername = User::normalizeUsername(Auth::user()->username ?? '') ?: $locale->text('notifications.someone');
            $reactionDisplay = mb_chr(hexdec($reactionCode), 'UTF-8') . ' '
                . ReactionCatalog::label($reactionCode, $locale);
            Notification::create(
                $postOwnerId,
                'report',
                $locale->text('notifications.post_reaction_title'),
                $locale->text('notifications.post_reaction_message', ['username' => $actorUsername, 'reaction' => $reactionDisplay]),
                '/posts'
            );
        }

        $this->json(['success' => true, 'action' => $action]);
    }

    public function delete() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $currentUser = Auth::user();
        $postId = (int)($_POST['post_id'] ?? 0);

        if ($postId <= 0) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.invalid_payload')], 400);
        }

        $post = Post::findById($postId);
        if (!$post) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.post_not_found')], 404);
        }

        if (!Post::canUserDeletePost($currentUser, $post)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.you_are_not_allowed_to_delete_this_post')], 403);
        }

        if (!Post::deleteById($postId)) {
            $this->json(['error' => \Causette\Localization\Locale::current()->text('response.unable_to_delete_post')], 500);
        }

        $this->json(['success' => true]);
    }
}
