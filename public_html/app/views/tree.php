<?php $tr = \Causette\Localization\Locale::current()->html(...); ?>
<div class="p-8 overflow-auto">
    <div class="mb-6 space-y-2">
        <h1 class="text-3xl font-bold"><?= $tr('ui.invite_tree') ?></h1>
        <?php require __DIR__ . '/partials/back-to-settings.php'; ?>
    </div>

    <section class="bg-zinc-900 border border-zinc-700 rounded-2xl p-5 max-w-5xl">
        <?php if (empty($tree)): ?>
            <p class="text-zinc-400 text-sm"><?= $tr('ui.no_invite_relationships_found_yet') ?></p>
        <?php else: ?>
            <?php
                $renderInviteNode = function ($node, $depth = 0) use (&$renderInviteNode, $tr) {
                    $username = $node['user']['username'] ?? null;
                    $userNumber = User::formatUserNumber((string)($node['user']['user_number'] ?? '0000000000000000'));
                    $createdAtRaw = (string)($node['user']['created_at'] ?? '');
                    $createdAtLabel = \Causette\Localization\DisplayPreferences::formatTimestamp($createdAtRaw, dateOnly: true);
                    $invitees = $node['invitees'] ?? [];
            ?>
                <li class="relative">
                    <div class="rounded-lg border border-zinc-700 bg-zinc-800/60 px-3 py-2 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-zinc-100\"><?= $username === null ? $tr('chat.unknown_user') : htmlspecialchars((string)$username, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="text-zinc-500\">(<?= htmlspecialchars($userNumber, ENT_QUOTES, 'UTF-8') ?>)</span>
                        </div>
                        <div class="mt-1 text-xs text-zinc-400"><?= $tr('ui.joined') ?> <?= $createdAtLabel !== '' ? htmlspecialchars($createdAtLabel, ENT_QUOTES, 'UTF-8') : $tr('ui.unknown_date') ?></div>
                    </div>

                    <?php if (!empty($invitees)): ?>
                        <ul class="mt-3 ml-6 pl-4 border-l border-zinc-700 space-y-3">
                            <?php foreach ($invitees as $inviteeNode): ?>
                                <?php $renderInviteNode($inviteeNode, $depth + 1); ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </li>
            <?php
                };
            ?>

            <ul class="space-y-4">
                <?php foreach ($tree as $rootNode): ?>
                    <?php $renderInviteNode($rootNode, 0); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
