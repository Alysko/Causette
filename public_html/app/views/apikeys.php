<?php
$locale = \Causette\Localization\Locale::current();
$tr = $locale->html(...);
require_once __DIR__ . '/../core/HtmlJson.php';
?>
<?php
    $keyList = $keys ?? [];
    $chatList = $groupChats ?? [];
    $toastMessage = '';
    $toastKind = 'info';

    $flashSuccess = flash_get('success');
    if ($flashSuccess === 'key_expired') {
        $toastMessage = $locale->text('feedback.api_key_expired');
        $toastKind = 'success';
    }

    $modalOverlayClass = 'fixed inset-0 z-50 hidden';
    $modalBackdropClass = 'absolute inset-0 bg-black/70';
    $modalCenterClass = 'relative z-10 flex min-h-full items-center justify-center p-4 lg:p-8';
    $modalBoxClass = 'w-full max-w-lg rounded-2xl border border-zinc-700 bg-zinc-900 p-6 max-h-[90vh] overflow-y-auto max-lg:!max-w-none max-lg:!rounded-none max-lg:!border-0 max-lg:min-h-[100dvh] max-lg:!max-h-none max-lg:flex max-lg:flex-col';
?>

<div class="p-8 overflow-auto space-y-6">

    <div class="flex items-center justify-between flex-wrap gap-4">
        <div class="space-y-2">
            <h1 class="text-3xl font-bold"><?= $tr('ui.api_keys') ?></h1>
            <?php require __DIR__ . '/partials/back-to-settings.php'; ?>
        </div>
        <div class="flex gap-3">
            <a href="<?= htmlspecialchars(base_url('/apikeys/docs')) ?>"
               class="inline-flex items-center gap-2 rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-medium text-zinc-200 hover:bg-zinc-700 transition">
                <i class="fa-solid fa-book"></i>
                <?= $tr('ui.documentation') ?>
            </a>
            <button type="button" data-modal-open="ak-create-modal"
                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500 transition">
                <i class="fa-solid fa-plus"></i>
                <?= $tr('ui.create_api_key') ?>
            </button>
        </div>
    </div>

    <p class="text-sm text-zinc-400">
        <?= $tr('ui.api_keys_allow_you_to_integrate') ?>
    </p>

    <?php if (empty($keyList)): ?>
        <div class="rounded-2xl border border-zinc-700 bg-zinc-900 p-8 text-center">
            <i class="fa-solid fa-key text-4xl text-zinc-600 mb-3"></i>
            <p class="text-zinc-400"><?= $tr('ui.no_api_keys_yet_create_one') ?></p>
        </div>
    <?php else: ?>
        <div class="space-y-3" id="ak-key-list">
            <?php foreach ($keyList as $key):
                $isActive = $key->status === 'active';
                $isExpired = !$isActive;
                $expiresAtLabel = '';
                if ($key->expires_at === null && $isActive) {
                    $expiresAtLabel = \Causette\Localization\Locale::current()->text('ui.never');
                } elseif ($key->expires_at !== null) {
                    $expiresAtLabel = \Causette\Localization\DisplayPreferences::formatTimestamp($key->expires_at);
                }
                $createdAtLabel = \Causette\Localization\DisplayPreferences::formatTimestamp((string)$key->created_at);
                $ipLabel = ($key->allowed_ips === null || $key->allowed_ips === '') ? $locale->text('ui.ip_unrestricted') : $key->allowed_ips;
            ?>
                <div class="rounded-2xl border <?= $isExpired ? 'border-zinc-800 opacity-60' : 'border-zinc-700' ?> bg-zinc-900 p-5">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div class="min-w-0 space-y-2 flex-1">
                            <div class="flex items-center gap-3 flex-wrap">
                                <h3 class="text-lg font-semibold truncate"><?= htmlspecialchars($key->name) ?></h3>
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?= $key->type === 'bot' ? 'bg-blue-500/20 text-blue-300' : 'bg-purple-500/20 text-purple-300' ?>">
                                    <?= $tr($key->type === 'bot' ? 'ui.bot' : 'ui.user') ?>
                                </span>
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?= $isActive ? 'bg-emerald-500/20 text-emerald-300' : 'bg-red-500/20 text-red-300' ?>">
                                    <?= $tr($isActive ? 'ui.api_key_active' : 'ui.api_key_expired_status') ?>
                                </span>
                            </div>
                            <div class="flex items-center gap-4 text-sm text-zinc-400 flex-wrap">
                                <span class="text-zinc-500"><?= htmlspecialchars($key->key_prefix) ?>...</span>
                                <span><i class="fa-regular fa-calendar mr-1"></i><?= $tr('ui.api_key_created_on', ['date' => $createdAtLabel]) ?></span>
                                <?php if ($expiresAtLabel !== ''): ?>
                                    <span><i class="fa-regular fa-clock mr-1"></i><?= $tr($isExpired ? 'ui.api_key_expired_on' : 'ui.api_key_expires_on', ['date' => $expiresAtLabel]) ?></span>
                                <?php endif; ?>
                                <span><i class="fa-solid fa-globe mr-1"></i><?= $tr('ui.api_key_ips', ['addresses' => mb_strlen($ipLabel) > 40 ? mb_substr($ipLabel, 0, 40) . '...' : $ipLabel]) ?></span>
                            </div>
                        </div>
                        <?php if ($isActive): ?>
                            <div class="flex gap-2 shrink-0">
                                <button type="button"
                                        data-app-action="manage-api-key" data-key-id="<?= (int)$key->id ?>" data-key-name="<?= htmlspecialchars($key->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-key-chats="<?= htmlspecialchars($key->allowed_chats ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-key-ips="<?= htmlspecialchars($key->allowed_ips ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-key-type="<?= htmlspecialchars($key->type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                        class="inline-flex items-center gap-2 rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-medium text-zinc-300 hover:bg-zinc-700 transition">
                                    <i class="fa-solid fa-gear"></i>
                                    <?= $tr('ui.manage') ?>
                                </button>
                                <button type="button"
                                        data-app-action="expire-api-key" data-key-id="<?= (int)$key->id ?>" data-key-name="<?= htmlspecialchars($key->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                        class="inline-flex items-center gap-2 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm font-medium text-red-400 hover:bg-red-500/20 transition">
                                    <i class="fa-solid fa-ban"></i>
                                    <?= $tr('ui.expire') ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Create API Key Modal -->
<div id="ak-create-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="ak-create-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.create_api_key') ?></h3>
                <button type="button" data-modal-close="ak-create-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>

            <form id="ak-create-form" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                <div>
                    <label class="block text-sm text-zinc-300 mb-1"><?= $tr('ui.name') ?></label>
                    <input type="text" name="name" maxlength="100" required data-modal-autofocus
                           placeholder="<?= $tr('ui.e_g_fabulous_bot') ?>"
                           class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3 text-zinc-100 placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                </div>

                <div>
                    <label class="block text-sm text-zinc-300 mb-2"><?= $tr('ui.type') ?></label>
                    <div class="flex gap-3">
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" name="type" value="bot" class="peer hidden" checked>
                            <div class="rounded-xl border border-zinc-700 bg-zinc-800 p-3 text-center peer-checked:border-emerald-500 peer-checked:bg-emerald-500/10 transition">
                                <i class="fa-solid fa-robot text-lg text-blue-400 mb-1"></i>
                                <div class="text-sm font-medium"><?= $tr('ui.bot') ?></div>
                                <div class="text-xs text-zinc-400 mt-0.5"><?= $tr('ui.send_messages_to_specific_chats') ?></div>
                            </div>
                        </label>
                        <label class="flex-1 cursor-not-allowed opacity-50" title="<?= $tr('ui.coming_soon') ?>">
                            <input type="radio" name="type" value="user" class="peer hidden" disabled>
                            <div class="rounded-xl border border-zinc-700 bg-zinc-800 p-3 text-center transition">
                                <i class="fa-solid fa-user text-lg text-purple-400 mb-1"></i>
                                <div class="text-sm font-medium"><?= $tr('ui.user') ?></div>
                                <div class="text-xs text-zinc-500 mt-0.5"><?= $tr('ui.coming_soon') ?></div>
                            </div>
                        </label>
                    </div>
                </div>

                <div id="ak-chat-selector">
                    <label class="block text-sm text-zinc-300 mb-1"><?= $tr('ui.allowed_group_chats') ?></label>
                    <p class="text-xs text-zinc-400 mb-2"><?= $tr('ui.select_which_group_chats_this_bot') ?></p>
                    <?php if (empty($chatList)): ?>
                        <p class="text-sm text-zinc-500 italic"><?= $tr('ui.you_don_t_own_any_group') ?></p>
                    <?php else: ?>
                        <div class="max-h-48 overflow-y-auto space-y-1 rounded-xl border border-zinc-700 bg-zinc-800 p-3">
                            <?php foreach ($chatList as $gc): ?>
                                <label class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-zinc-700/50 cursor-pointer">
                                    <input type="checkbox" name="chat_ids[]" value="<?= (int)$gc->id ?>"
                                           class="rounded border-zinc-600 bg-zinc-700 text-emerald-500 focus:ring-emerald-500">
                                    <span class="text-sm text-zinc-200 truncate"><?= $gc->title ? htmlspecialchars($gc->title, ENT_QUOTES, 'UTF-8') : $tr('ui.group_unnamed') ?></span>
                                    <span class="text-xs text-zinc-500 ml-auto shrink-0"><?= htmlspecialchars(User::formatUserNumber($gc->chat_number)) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm text-zinc-300 mb-1"><?= $tr('ui.expiry') ?></label>
                    <select name="expiry"
                            class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3 text-zinc-100 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        <option value="never"><?= $tr('ui.never') ?></option>
                        <option value="24h"><?= $tr('ui.24_hours') ?></option>
                        <option value="7d"><?= $tr('ui.7_days') ?></option>
                        <option value="30d" selected><?= $tr('ui.30_days') ?></option>
                        <option value="1y"><?= $tr('ui.1_year') ?></option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm text-zinc-300 mb-1"><?= $tr('ui.ip_restrictions') ?> <span class="text-zinc-500"><?= $tr('ui.optional') ?></span></label>
                    <input type="text" name="allowed_ips"
                           placeholder="<?= $tr('ui.e_g_192_168_1_1') ?>"
                           class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3 text-zinc-100 placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <p class="text-xs text-zinc-400 mt-1"><?= $tr('ui.comma_separated_ipv4_or_ipv6_addresses') ?></p>
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="submit"
                            class="flex-1 rounded-xl bg-emerald-600 px-6 py-2.5 text-sm font-medium text-white hover:bg-emerald-500 transition">
                        <?= $tr('ui.create_key') ?>
                    </button>
                    <button type="button" data-modal-close="ak-create-modal"
                            class="rounded-xl bg-zinc-700 px-6 py-2.5 text-sm font-medium text-zinc-200 hover:bg-zinc-600 transition">
                        <?= $tr('ui.cancel') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Key Created Modal -->
<div id="ak-created-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.api_key_created') ?></h3>
                <button type="button" data-modal-close="ak-created-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>

            <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-200 mb-4">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                <?= $tr('ui.this_key_will_only_be_shown') ?>
            </div>

            <div class="mb-4">
                <label class="block text-sm text-zinc-300 mb-1"><?= $tr('ui.your_api_key') ?></label>
                <div class="flex gap-2">
                    <input type="text" id="ak-new-key-display" readonly
                           class="flex-1 rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3 text-sm text-zinc-100 select-all focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <button type="button" id="ak-copy-btn" data-app-action="copy-api-key"
                            class="shrink-0 rounded-xl bg-zinc-700 px-4 py-3 text-sm text-zinc-200 hover:bg-zinc-600 transition">
                        <i class="fa-regular fa-copy"></i>
                    </button>
                </div>
            </div>

            <div class="flex gap-2">
                <button type="button" data-modal-close="ak-created-modal" data-app-action="reload-page"
                        class="flex-1 rounded-xl bg-emerald-600 px-6 py-2.5 text-sm font-medium text-white hover:bg-emerald-500 transition">
                    <?= $tr('ui.done') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Manage API Key Modal -->
<div id="ak-manage-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="ak-manage-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.manage_2') ?> <span id="ak-manage-key-name"></span></h3>
                <button type="button" data-modal-close="ak-manage-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>

            <form id="ak-manage-form" class="space-y-4">
                <input type="hidden" name="key_id" id="ak-manage-key-id" value="">

                <div id="ak-manage-chat-selector">
                    <label class="block text-sm text-zinc-300 mb-1"><?= $tr('ui.allowed_group_chats') ?></label>
                    <p class="text-xs text-zinc-400 mb-2"><?= $tr('ui.select_which_group_chats_this_bot_2') ?></p>
                    <?php if (empty($chatList)): ?>
                        <p class="text-sm text-zinc-500 italic"><?= $tr('ui.you_don_t_own_any_group') ?></p>
                    <?php else: ?>
                        <div class="max-h-48 overflow-y-auto space-y-1 rounded-xl border border-zinc-700 bg-zinc-800 p-3">
                            <?php foreach ($chatList as $gc): ?>
                                <label class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-zinc-700/50 cursor-pointer">
                                    <input type="checkbox" name="chat_ids[]" value="<?= (int)$gc->id ?>"
                                           class="ak-manage-chat-cb rounded border-zinc-600 bg-zinc-700 text-emerald-500 focus:ring-emerald-500">
                                    <span class="text-sm text-zinc-200 truncate"><?= $gc->title ? htmlspecialchars($gc->title, ENT_QUOTES, 'UTF-8') : $tr('ui.group_unnamed') ?></span>
                                    <span class="text-xs text-zinc-500 ml-auto shrink-0"><?= htmlspecialchars(User::formatUserNumber($gc->chat_number)) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm text-zinc-300 mb-1"><?= $tr('ui.ip_restrictions') ?> <span class="text-zinc-500"><?= $tr('ui.optional') ?></span></label>
                    <input type="text" name="allowed_ips" id="ak-manage-ips"
                           placeholder="<?= $tr('ui.e_g_192_168_1_1') ?>"
                           class="w-full rounded-xl border border-zinc-700 bg-zinc-800 px-4 py-3 text-zinc-100 placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <p class="text-xs text-zinc-400 mt-1"><?= $tr('ui.comma_separated_ipv4_or_ipv6_addresses') ?></p>
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="submit"
                            class="flex-1 rounded-xl bg-emerald-600 px-6 py-2.5 text-sm font-medium text-white hover:bg-emerald-500 transition">
                        <?= $tr('ui.save_changes') ?>
                    </button>
                    <button type="button" data-modal-close="ak-manage-modal"
                            class="rounded-xl bg-zinc-700 px-6 py-2.5 text-sm font-medium text-zinc-200 hover:bg-zinc-600 transition">
                        <?= $tr('ui.cancel') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Expire Confirmation Modal -->
<div id="ak-expire-modal" class="<?= $modalOverlayClass ?>" role="dialog" aria-modal="true">
    <div class="<?= $modalBackdropClass ?>" data-modal-close="ak-expire-modal"></div>
    <div class="<?= $modalCenterClass ?>">
        <div class="<?= $modalBoxClass ?>" data-modal-box>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-xl font-semibold"><?= $tr('ui.expire_api_key') ?></h3>
                <button type="button" data-modal-close="ak-expire-modal" class="rounded-lg px-2 py-1 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200">&times;</button>
            </div>

            <p class="text-sm text-zinc-300 mb-4">
                <?= $tr('ui.are_you_sure_you_want_to_7') ?> <strong id="ak-expire-key-name"></strong><?= $tr('ui.this_action_cannot_be_undone_the') ?>
            </p>

            <div class="flex gap-2">
                <button type="button" id="ak-expire-confirm-btn"
                        class="flex-1 rounded-xl bg-red-600 px-6 py-2.5 text-sm font-medium text-white hover:bg-red-500 transition">
                    <?= $tr('ui.expire_key') ?>
                </button>
                <button type="button" data-modal-close="ak-expire-modal"
                        class="rounded-xl bg-zinc-700 px-6 py-2.5 text-sm font-medium text-zinc-200 hover:bg-zinc-600 transition">
                    <?= $tr('ui.cancel') ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($toastMessage !== ''): ?>
    <div id="page-toast"
         data-toast-message="<?= htmlspecialchars($toastMessage) ?>"
         data-toast-kind="<?= htmlspecialchars($toastKind) ?>"
         class="hidden" aria-hidden="true"></div>
<?php endif; ?>

<script data-page-script nonce="<?= \Causette\Security\ContentSecurityPolicy::nonce() ?>">
(function () {
    // ---- Generic modal open/close ----
    var openButtons = document.querySelectorAll('[data-modal-open]');
    var closeButtons = document.querySelectorAll('[data-modal-close]');

    function openModal(modalId) {
        var modal = document.getElementById(modalId);
        if (!modal) return;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        var autofocusInput = modal.querySelector('[data-modal-autofocus]');
        if (autofocusInput && typeof autofocusInput.focus === 'function') {
            setTimeout(function () {
                autofocusInput.focus();
                if (typeof autofocusInput.select === 'function') autofocusInput.select();
            }, 0);
        }
    }

    function closeModal(modalId) {
        var modal = document.getElementById(modalId);
        if (!modal) return;
        modal.classList.add('hidden');
        if (!Array.from(document.querySelectorAll('[role="dialog"]')).some(function (el) {
            return !el.classList.contains('hidden');
        })) {
            document.body.classList.remove('overflow-hidden');
        }
    }

    openButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var modalId = button.getAttribute('data-modal-open');
            if (modalId) openModal(modalId);
        });
    });

    closeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var modalId = button.getAttribute('data-modal-close');
            if (modalId) closeModal(modalId);
        });
    });

    document.querySelectorAll('[role="dialog"]').forEach(function (overlay) {
        overlay.addEventListener('mousedown', function (event) {
            if (!event.target.closest('[data-modal-box]')) {
                closeModal(overlay.id);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('[role="dialog"]').forEach(function (modal) {
            if (!modal.classList.contains('hidden')) {
                modal.classList.add('hidden');
            }
        });
        document.body.classList.remove('overflow-hidden');
    });

    // ---- Type toggle: show/hide chat selector ----
    var typeRadios = document.querySelectorAll('input[name="type"]');
    var chatSelector = document.getElementById('ak-chat-selector');

    function toggleChatSelector() {
        var selected = document.querySelector('input[name="type"]:checked');
        if (chatSelector) {
            chatSelector.style.display = (selected && selected.value === 'bot') ? '' : 'none';
        }
    }

    typeRadios.forEach(function (radio) {
        radio.addEventListener('change', toggleChatSelector);
    });
    toggleChatSelector();

    // ---- Create form submit ----
    var createForm = document.getElementById('ak-create-form');
    if (createForm) {
        createForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var formData = new FormData(createForm);
            var data = {};
            data.csrf_token = getCsrfToken();
            data.name = formData.get('name') || '';
            data.type = formData.get('type') || 'bot';
            data.expiry = formData.get('expiry') || '30d';
            data.allowed_ips = formData.get('allowed_ips') || '';

            var chatIds = formData.getAll('chat_ids[]');

            var body = new URLSearchParams();
            Object.entries(data).forEach(function (entry) {
                body.append(entry[0], entry[1]);
            });
            chatIds.forEach(function (id) {
                body.append('chat_ids[]', id);
            });

            fetch(<?= \Causette\View\HtmlJson::encode(base_url('/apikeys/create')) ?>, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            })
            .then(function (res) { return res.json(); })
            .then(function (result) {
                if (result.success) {
                    closeModal('ak-create-modal');
                    createForm.reset();
                    toggleChatSelector();

                    var keyDisplay = document.getElementById('ak-new-key-display');
                    if (keyDisplay) keyDisplay.value = result.api_key;
                    openModal('ak-created-modal');
                } else {
                    showToast(result.error || uiText('api_key_create_failed'), 'error');
                }
            })
            .catch(function () {
                showToast(uiText('unexpected_error'), 'error');
            });
        });
    }

    // ---- Expire key ----
    var pendingExpireKeyId = 0;

    window.expireKey = function (keyId, keyName) {
        pendingExpireKeyId = keyId;
        var nameEl = document.getElementById('ak-expire-key-name');
        if (nameEl) nameEl.textContent = keyName;
        openModal('ak-expire-modal');
    };

    var expireConfirmBtn = document.getElementById('ak-expire-confirm-btn');
    if (expireConfirmBtn) {
        expireConfirmBtn.addEventListener('click', function () {
            if (pendingExpireKeyId <= 0) return;

            postForm('/apikeys/expire', {
                csrf_token: getCsrfToken(),
                key_id: String(pendingExpireKeyId)
            }).then(function (result) {
                if (result.success) {
                    closeModal('ak-expire-modal');
                    showToast(uiText('api_key_expired'), 'success');
                    setTimeout(function () { location.reload(); }, 800);
                } else {
                    showToast(result.error || uiText('api_key_expire_failed'), 'error');
                }
            }).catch(function () {
                showToast(uiText('unexpected_error'), 'error');
            });
        });
    }

    // ---- Manage key ----
    window.manageKey = function (keyId, keyName, allowedChats, allowedIps, keyType) {
        document.getElementById('ak-manage-key-id').value = keyId;
        document.getElementById('ak-manage-key-name').textContent = keyName;
        document.getElementById('ak-manage-ips').value = allowedIps || '';

        // Show/hide chat selector based on key type
        var chatSelector = document.getElementById('ak-manage-chat-selector');
        if (chatSelector) {
            chatSelector.style.display = (keyType === 'bot') ? '' : 'none';
        }

        // Pre-check the appropriate chat checkboxes
        var chatCbs = document.querySelectorAll('.ak-manage-chat-cb');
        var allowedArr = allowedChats ? allowedChats.split(',').map(function (s) { return s.trim(); }) : [];
        chatCbs.forEach(function (cb) {
            cb.checked = allowedArr.indexOf(cb.value) !== -1;
        });

        openModal('ak-manage-modal');
    };

    var manageForm = document.getElementById('ak-manage-form');
    if (manageForm) {
        manageForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var formData = new FormData(manageForm);
            var body = new URLSearchParams();
            body.append('csrf_token', getCsrfToken());
            body.append('key_id', formData.get('key_id') || '');
            body.append('allowed_ips', formData.get('allowed_ips') || '');

            var chatIds = formData.getAll('chat_ids[]');
            chatIds.forEach(function (id) {
                body.append('chat_ids[]', id);
            });

            fetch(<?= \Causette\View\HtmlJson::encode(base_url('/apikeys/update')) ?>, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            })
            .then(function (res) { return res.json(); })
            .then(function (result) {
                if (result.success) {
                    closeModal('ak-manage-modal');
                    showToast(uiText('api_key_updated'), 'success');
                    setTimeout(function () { location.reload(); }, 800);
                } else {
                    showToast(result.error || uiText('api_key_update_failed'), 'error');
                }
            })
            .catch(function () {
                showToast(uiText('unexpected_error'), 'error');
            });
        });
    }


    // ---- Copy key ----
    window.copyNewKey = function () {
        var keyDisplay = document.getElementById('ak-new-key-display');
        var copyBtn = document.getElementById('ak-copy-btn');
        if (!keyDisplay) return;

        navigator.clipboard.writeText(keyDisplay.value).then(function () {
            if (copyBtn) {
                copyBtn.innerHTML = '<i class="fa-solid fa-check"></i>';
                setTimeout(function () {
                    copyBtn.innerHTML = '<i class="fa-regular fa-copy"></i>';
                }, 2000);
            }
        });
    };
})();
</script>
