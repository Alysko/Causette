<?php $tr = \Causette\Localization\Locale::current()->html(...); ?>
<div class="p-8 overflow-auto">
    <div class="mb-6 space-y-2">
        <h1 class="text-3xl font-bold"><?= $tr('ui.trash') ?></h1>
        <?php require __DIR__ . '/partials/back-to-settings.php'; ?>
    </div>

    <?php if (!empty($toastMessage ?? '')): ?>
        <div
            id="page-toast"
            data-toast-message="<?= htmlspecialchars($toastMessage, ENT_QUOTES, 'UTF-8') ?>"
            data-toast-kind="<?= htmlspecialchars($toastKind ?? 'info', ENT_QUOTES, 'UTF-8') ?>"
            class="hidden"
            aria-hidden="true"
        ></div>
    <?php endif; ?>

    <section class="bg-zinc-900 border border-zinc-700 rounded-2xl p-5 max-w-5xl">
        <div class="space-y-3">
            <?php if (empty($deletedChats ?? [])): ?>
                <p class="text-zinc-400 text-sm"><?= $tr('ui.no_deleted_chats') ?></p>
            <?php else: ?>
                <?php foreach (($deletedChats ?? []) as $chat): ?>
                    <div class="bg-zinc-800 rounded-xl p-4 flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium text-zinc-100 truncate"><?= htmlspecialchars((string)$chat->chat_title, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-xs text-zinc-400 mt-1">
                                <?= htmlspecialchars((string)$chat->chat_number_formatted, ENT_QUOTES, 'UTF-8') ?>
                                · <?= !empty($chat->deleted_by_username)
                                    ? $tr('ui.chat_deleted_by', ['date' => $chat->deleted_at, 'name' => $chat->deleted_by_username])
                                    : $tr('ui.chat_deleted_on', ['date' => $chat->deleted_at]) ?>
                            </div>
                            <div class="text-xs text-zinc-500 mt-1">
                                <?php
                                    $messageCount = (int)($chat->message_count ?? 0);
                                    $attachmentCount = (int)($chat->attachment_count ?? 0);
                                ?>
                                <?= $tr($messageCount === 1 ? 'ui.message_count_one' : 'ui.message_count_other', ['count' => $messageCount]) ?> · <?= $tr($attachmentCount === 1 ? 'ui.attachment_count_one' : 'ui.attachment_count_other', ['count' => $attachmentCount]) ?>
                            </div>
                        </div>

                        <div class="shrink-0 flex items-center gap-2">
                            <a href="<?= htmlspecialchars(base_url('/trash/' . User::formatUserNumber((string)$chat->chat_number)), ENT_QUOTES, 'UTF-8') ?>" class="bg-zinc-700 hover:bg-zinc-600 text-sm px-3 py-1.5 rounded-lg"><?= $tr('ui.view') ?></a>
                            <button
                                type="button"
                                class="bg-red-700 hover:bg-red-600 text-white text-sm px-3 py-1.5 rounded-lg js-trash-delete-open"
                                data-chat-id="<?= (int)$chat->id ?>"
                                data-chat-title="<?= htmlspecialchars((string)$chat->chat_title, ENT_QUOTES, 'UTF-8') ?>"
                            ><?= $tr('ui.delete_permanently') ?></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<div id="trash-delete-modal" class="hidden fixed inset-0 bg-black/70 z-50 p-4 md:p-6" aria-hidden="true">
    <div class="h-full w-full flex items-center justify-center">
        <div class="w-full max-w-md bg-zinc-900 border border-zinc-700 rounded-2xl shadow-2xl p-6" role="dialog" aria-modal="true" aria-labelledby="trash-delete-modal-title">
            <h2 id="trash-delete-modal-title" class="text-lg font-semibold text-zinc-100"><?= $tr('ui.delete_chat_permanently') ?></h2>
            <p class="mt-2 text-sm text-zinc-400" id="trash-delete-modal-description"><?= $tr('ui.are_you_sure_you_want_to_5') ?></p>
            <form id="trash-delete-form" action="<?= htmlspecialchars(base_path('/trash/delete'), ENT_QUOTES, 'UTF-8') ?>" method="POST" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" id="trash-delete-chat-id" name="chat_id" value="">
                <div class="flex items-center justify-end gap-3">
                    <button type="button" id="trash-delete-cancel" class="px-4 py-2 rounded-xl bg-zinc-800 border border-zinc-700 hover:bg-zinc-700 text-zinc-200"><?= $tr('ui.cancel') ?></button>
                    <button type="submit" id="trash-delete-submit" class="px-4 py-2 rounded-xl bg-red-700 hover:bg-red-600 text-white"><?= $tr('ui.delete_permanently') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
