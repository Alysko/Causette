<?php $tr = \Causette\Localization\Locale::current()->html(...); ?>
<?php
$mode = $mode ?? 'users';
if (!in_array($mode, ['users', 'messages', 'posts'], true)) {
    $mode = 'users';
}
$usersUrl = base_url('/search?mode=users');
$messagesUrl = base_url('/search?mode=messages');
$postsUrl = base_url('/search?mode=posts');
?>

<div class="p-8 overflow-auto">
    <div class="mb-6 flex items-center gap-4">
        <a href="<?= htmlspecialchars(base_url('/'), ENT_QUOTES, 'UTF-8') ?>" class="text-zinc-400 hover:text-zinc-200 transition">
            <i class="fa fa-arrow-left"></i>
        </a>
        <h1 class="text-3xl font-bold"><?= $tr('ui.search') ?></h1>
        <div class="ml-2 inline-flex items-center rounded-lg border border-zinc-700 p-1 text-xs">
            <a
                href="<?= htmlspecialchars($usersUrl, ENT_QUOTES, 'UTF-8') ?>"
                class="px-2.5 py-1 rounded-md transition <?= $mode === 'users' ? 'bg-zinc-700 text-zinc-100' : 'text-zinc-400 hover:text-zinc-200' ?>"
            >
                <?= $tr('ui.users') ?>
            </a>
            <a
                href="<?= htmlspecialchars($messagesUrl, ENT_QUOTES, 'UTF-8') ?>"
                class="px-2.5 py-1 rounded-md transition <?= $mode === 'messages' ? 'bg-zinc-700 text-zinc-100' : 'text-zinc-400 hover:text-zinc-200' ?>"
            >
                <?= $tr('ui.messages') ?>
            </a>
            <a
                href="<?= htmlspecialchars($postsUrl, ENT_QUOTES, 'UTF-8') ?>"
                class="px-2.5 py-1 rounded-md transition <?= $mode === 'posts' ? 'bg-zinc-700 text-zinc-100' : 'text-zinc-400 hover:text-zinc-200' ?>"
            >
                <?= $tr('ui.posts') ?>
            </a>
        </div>
    </div>

    <?php if ($mode === 'users'): ?>
    <section>
        <form id="user-search-form" class="flex items-center gap-3 w-full max-w-lg mb-4">
            <input type="text" id="user-search-input" placeholder="<?= $tr('ui.search_by_username_or_user_number') ?>" class="bg-zinc-900 border border-zinc-700 rounded-xl px-5 py-2.5 w-full" pattern="[A-Za-z0-9-]+" title="<?= $tr('ui.use_only_letters_numbers_and_dashes') ?>" required>
            <button type="submit" class="bg-zinc-700 hover:bg-zinc-600 border border-zinc-700 px-5 py-2.5 rounded-xl"><?= $tr('ui.search') ?></button>
        </form>

        <p id="user-search-help" class="text-sm text-zinc-400 mb-4"><?= $tr('ui.press_enter_return_or_click_the') ?></p>

        <div id="user-search-results" class="grid grid-cols-1 sm:grid-cols-2 gap-3"></div>
    </section>
    <?php elseif ($mode === 'messages'): ?>
    <section>
        <form id="message-search-form" class="flex items-center gap-3 w-full max-w-lg mb-4">
            <input type="text" id="message-search-input" placeholder="<?= $tr('ui.search_message_content') ?>" class="bg-zinc-900 border border-zinc-700 rounded-xl px-5 py-2.5 w-full" minlength="2" required>
            <button type="submit" class="bg-zinc-700 hover:bg-zinc-600 border border-zinc-700 px-5 py-2.5 rounded-xl"><?= $tr('ui.search') ?></button>
        </form>

        <p id="message-search-help" class="text-sm text-zinc-400 mb-4"><?= $tr('ui.search_across_all_your_personal_and') ?></p>

        <div id="message-search-results" class="space-y-2"></div>
    </section>
    <?php else: ?>
    <section>
        <form id="post-search-form" class="flex items-center gap-3 w-full max-w-lg mb-4">
            <input type="text" id="post-search-input" placeholder="<?= $tr('ui.search_post_content') ?>" class="bg-zinc-900 border border-zinc-700 rounded-xl px-5 py-2.5 w-full" minlength="2" required>
            <button type="submit" class="bg-zinc-700 hover:bg-zinc-600 border border-zinc-700 px-5 py-2.5 rounded-xl"><?= $tr('ui.search') ?></button>
        </form>

        <p id="post-search-help" class="text-sm text-zinc-400 mb-4"><?= $tr('ui.search_posts_from_all_users_on') ?></p>

        <div id="post-search-results" class="space-y-2"></div>
    </section>
    <?php endif; ?>
</div>
