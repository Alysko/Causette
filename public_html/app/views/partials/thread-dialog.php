<dialog id="message-thread" class="message-thread" aria-labelledby="message-thread-title">
    <div class="message-thread-heading">
        <h2 id="message-thread-title"><?= $tr('ui.thread_title') ?></h2>
        <div class="message-thread-header-actions">
            <button type="button" class="message-thread-follow" data-thread-follow aria-pressed="false" hidden><?= $tr('ui.thread_follow') ?></button>
            <button type="button" class="conversation-switcher-close" data-thread-close aria-label="<?= $tr('ui.close') ?>" autofocus>
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
    </div>
    <div class="message-thread-history" role="region" aria-label="<?= $tr('ui.thread_history') ?>" tabindex="0">
        <div data-thread-root></div>
        <button type="button" data-thread-older hidden><?= $tr('ui.thread_older') ?></button>
        <ol data-thread-replies></ol>
    </div>
    <div class="message-thread-status" data-thread-status role="status"></div>
    <form class="message-thread-form" data-thread-form hidden>
        <label for="message-thread-input"><?= $tr('ui.thread_reply') ?></label>
        <textarea id="message-thread-input" name="content" rows="2" maxlength="4096" required></textarea>
        <button type="submit"><?= $tr('ui.send') ?></button>
    </form>
</dialog>
