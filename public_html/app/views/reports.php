<?php
$locale = \Causette\Localization\Locale::current();
$tr = $locale->html(...);
?>
<div class="p-8 overflow-auto">
    <div class="mb-6 space-y-2">
        <h1 class="text-3xl font-bold"><?= $tr('ui.reports') ?></h1>
        <?php require __DIR__ . '/partials/back-to-settings.php'; ?>
    </div>

    <?php
        $filter = $selectedFilter ?? 'new';
        $reportList = $reports ?? [];
        $tabClass = static function($isActive) {
            return $isActive
                ? 'bg-emerald-600 text-white border-emerald-500'
                : 'bg-zinc-900 text-zinc-300 border-zinc-700 hover:bg-zinc-800';
        };

        $formatTarget = static function($report) use ($locale) {
            $targetType = strtolower((string)($report->target_type ?? ''));
            $targetId = (int)($report->target_id ?? 0);

            if ($targetType === 'user') {
                $targetUserNumber = trim((string)($report->target_user_number ?? ''));
                $targetUsername = trim((string)($report->target_user_username ?? ''));
                if ($targetUserNumber !== '') {
                    $label = $targetUsername !== ''
                        ? ($targetUsername . ' (' . User::formatUserNumber($targetUserNumber) . ')')
                        : User::formatUserNumber($targetUserNumber);

                    return [
                        'label' => $label,
                        'href' => base_url('/u/' . User::formatUserNumber($targetUserNumber))
                    ];
                }

                return [
                    'label' => $locale->text('ui.report_user_number', ['number' => $targetId]),
                    'href' => null
                ];
            }

            if ($targetType === 'chat') {
                $chatNumber = trim((string)($report->target_chat_number ?? ''));
                if ($chatNumber !== '') {
                    $formattedChatNumber = User::formatUserNumber($chatNumber);
                    return [
                        'label' => $locale->text('ui.report_chat_number', ['number' => $formattedChatNumber]),
                        'href' => base_url('/c/' . $formattedChatNumber)
                    ];
                }

                return [
                    'label' => $locale->text('ui.report_chat_number', ['number' => $targetId]),
                    'href' => null
                ];
            }

            if ($targetType === 'message') {
                $messageChatNumber = trim((string)($report->target_message_chat_number ?? ''));
                if ($messageChatNumber !== '') {
                    $formattedChatNumber = User::formatUserNumber($messageChatNumber);
                    return [
                        'label' => $locale->text('ui.report_message_in_chat', ['number' => $targetId, 'chat' => $formattedChatNumber]),
                        'href' => base_url('/c/' . $formattedChatNumber)
                    ];
                }

                return [
                    'label' => $locale->text('ui.report_message_number', ['number' => $targetId]),
                    'href' => null
                ];
            }

            return [
                'label' => $locale->text('ui.report_target_number', ['number' => $targetId]),
                'href' => null
            ];
        };
    ?>

    <?php if (!empty($toastMessage ?? '')): ?>
        <div
            id="page-toast"
            data-toast-message="<?= htmlspecialchars($toastMessage, ENT_QUOTES, 'UTF-8') ?>"
            data-toast-kind="<?= htmlspecialchars($toastKind ?? 'info', ENT_QUOTES, 'UTF-8') ?>"
            class="hidden"
            aria-hidden="true"
        ></div>
    <?php endif; ?>

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <a href="<?= htmlspecialchars(base_url('/reports?filter=new'), ENT_QUOTES, 'UTF-8') ?>" class="px-5 py-2.5 rounded-xl border transition <?= htmlspecialchars($tabClass($filter === 'new'), ENT_QUOTES, 'UTF-8') ?>"><?= $tr('ui.new') ?></a>
        <a href="<?= htmlspecialchars(base_url('/reports?filter=handled'), ENT_QUOTES, 'UTF-8') ?>" class="px-5 py-2.5 rounded-xl border transition <?= htmlspecialchars($tabClass($filter === 'handled'), ENT_QUOTES, 'UTF-8') ?>"><?= $tr('ui.handled') ?></a>
        <a href="<?= htmlspecialchars(base_url('/reports?filter=all'), ENT_QUOTES, 'UTF-8') ?>" class="px-5 py-2.5 rounded-xl border transition <?= htmlspecialchars($tabClass($filter === 'all'), ENT_QUOTES, 'UTF-8') ?>"><?= $tr('ui.all') ?></a>
    </div>

    <section class="bg-zinc-900 border border-zinc-700 rounded-2xl p-5 max-w-5xl">
        <div class="space-y-3">
            <?php if (empty($reportList)): ?>
                <p class="text-zinc-400 text-sm"><?= $tr('ui.no_reports_in_this_list') ?></p>
            <?php else: ?>
                <?php foreach ($reportList as $report): ?>
                    <?php
                        $reporter = (object) [
                            'username' => $report->reporter_username,
                            'user_number' => $report->reporter_user_number,
                            'avatar_filename' => $report->reporter_avatar_filename
                        ];
                        $reporterAvatar = User::avatarUrl($reporter);
                        $target = $formatTarget($report);
                        $isPending = strtolower((string)($report->status ?? '')) === 'pending';
                    ?>
                    <div class="bg-zinc-800 rounded-xl p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-3 mb-3">
                                    <?php if ($reporterAvatar): ?>
                                        <img src="<?= htmlspecialchars($reporterAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $tr('ui.user_avatar', ['name' => $report->reporter_username]) ?>" class="w-9 h-9 rounded-full object-cover border border-zinc-700">
                                    <?php else: ?>
                                        <div class="w-9 h-9 rounded-full border border-zinc-700 flex items-center justify-center font-semibold text-sm <?= htmlspecialchars(User::avatarColorClasses($report->reporter_user_number), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(User::avatarInitial($report->reporter_username), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="min-w-0">
                                        <div class="text-sm text-zinc-400"><?= $tr('ui.reporter') ?></div>
                                        <a href="<?= htmlspecialchars(base_url('/u/' . User::formatUserNumber((string)$report->reporter_user_number)), ENT_QUOTES, 'UTF-8') ?>" class="font-medium causette-accent hover:text-emerald-300 hover:underline underline-offset-2">
                                            <?= htmlspecialchars($report->reporter_username, ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </div>
                                </div>

                                <div class="text-sm text-zinc-300 mb-1">
                                    <span class="text-zinc-500"><?= $tr('ui.target') ?></span>
                                    <?php if (!empty($target['href'])): ?>
                                        <a href="<?= htmlspecialchars($target['href'], ENT_QUOTES, 'UTF-8') ?>" class="causette-accent hover:text-emerald-300 hover:underline underline-offset-2">
                                            <?= htmlspecialchars($target['label'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    <?php else: ?>
                                        <span><?= htmlspecialchars($target['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="text-sm text-zinc-300 mb-1">
                                    <span class="text-zinc-500"><?= $tr('ui.reason') ?></span>
                                    <span><?= nl2br(htmlspecialchars($report->reason, ENT_QUOTES, 'UTF-8')) ?></span>
                                </div>

                                <div class="text-xs text-zinc-500 mt-2">
                                    <?= $tr('ui.report_submitted_at', ['date' => \Causette\Localization\DisplayPreferences::formatTimestamp((string)$report->created_at)]) ?>
                                </div>
                            </div>

                            <div class="shrink-0 flex flex-col items-end gap-2">
                                <span class="text-xs px-2 py-1 rounded-full border <?= $isPending ? 'border-amber-600 text-amber-300 bg-amber-900/30' : 'border-emerald-600 text-emerald-300 bg-emerald-900/30' ?>">
                                    <?= $tr($isPending ? 'ui.report_status_new' : 'ui.report_status_handled') ?>
                                </span>

                                <?php if ($isPending): ?>
                                    <form action="<?= htmlspecialchars(base_path('/reports/mark-handled'), ENT_QUOTES, 'UTF-8') ?>" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="report_id" value="<?= (int)$report->id ?>">
                                        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white text-sm px-3 py-1.5 rounded-lg"><?= $tr('ui.mark_as_handled') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>
