function bindThreadDialog() {
    const dialog = document.getElementById('message-thread');
    if (!dialog) return;
    const root = dialog.querySelector('[data-thread-root]');
    const history = dialog.querySelector('.message-thread-history');
    const replies = dialog.querySelector('[data-thread-replies]');
    const older = dialog.querySelector('[data-thread-older]');
    const follow = dialog.querySelector('[data-thread-follow]');
    const status = dialog.querySelector('[data-thread-status]');
    const form = dialog.querySelector('[data-thread-form]');
    const input = form.elements.content;
    const submit = form.querySelector('button[type="submit"]');
    let current = null;

    function responseNode(message, tag) {
        const container = document.createElement(tag);
        const header = document.createElement('header');
        const author = document.createElement('strong');
        author.textContent = message.author;
        const date = document.createElement('time');
        date.textContent = formatCompactMessageTimestamp(message.created_at);
        const content = document.createElement('p');
        content.textContent = decodeStoredMentionsToPlainText(message.content);
        header.append(author, date);
        container.append(header, content);
        return container;
    }

    function addReply(reply) {
        const id = Number(reply.id);
        if (!Number.isSafeInteger(id) || id <= 0 || replies.querySelector(`[data-thread-reply="${id}"]`)) return;
        const entry = responseNode(reply, 'li');
        entry.dataset.threadReply = String(id);
        const next = Array.from(replies.children).find(node => Number(node.dataset.threadReply) > id);
        replies.insertBefore(entry, next ?? null);
    }

    function reset() {
        if (!current) return;
        clearInterval(current.timer);
        current.request?.abort();
        current = null;
        delete dialog.dataset.loadedThreadId;
    }

    async function load(state, direction) {
        if (state.request || state.blocked) return;
        const request = new AbortController();
        const followVersion = state.followVersion;
        state.request = request;
        const query = new URLSearchParams();
        if (direction === 'earlier') query.set('before', replies.firstElementChild?.dataset.threadReply || '');
        if (direction === 'later') query.set('after', String(state.latest));
        try {
            const response = await fetch(appPath(`/api/threads/${state.id}?${query}`), {signal: request.signal});
            const data = await response.json();
            if (current !== state) return;
            const belongsToChat = Number(data.chat_id) === state.chatId;
            if (!response.ok || !belongsToChat || !data.root || !Array.isArray(data.replies)) {
                status.textContent = uiActionError(data?.error, 'thread_load_failed');
                if ((response.ok && !belongsToChat) || [401, 403, 404].includes(response.status)) {
                    state.blocked = true;
                    form.hidden = true;
                    older.hidden = true;
                    follow.hidden = true;
                    delete dialog.dataset.loadedThreadId;
                    root.replaceChildren();
                    replies.replaceChildren();
                }
                return;
            }
            dialog.dataset.loadedThreadId = String(state.id);
            follow.hidden = false;
            if (state.followVersion === followVersion && !state.followingPending) {
                follow.setAttribute('aria-pressed', String(data.following === true));
            }
            const rootRevision = JSON.stringify(data.root);
            if (rootRevision !== state.rootRevision) {
                root.replaceChildren(responseNode(data.root, 'article'));
                state.rootRevision = rootRevision;
            }
            form.hidden = !data.can_reply;
            const previousHeight = history.scrollHeight;
            const atBottom = previousHeight - history.scrollTop - history.clientHeight < 24;
            data.replies.forEach(addReply);
            if (direction !== 'earlier') {
                state.latest = data.replies.at(-1)?.id ?? state.latest;
                if (atBottom || direction === 'initial') history.scrollTop = history.scrollHeight;
            } else {
                history.scrollTop += history.scrollHeight - previousHeight;
            }
            if (direction !== 'later') older.hidden = !data.has_older;
            if (!state.sendError) status.textContent = '';
            state.loaded = true;
        } catch {
            if (current === state && !request.signal.aborted && !state.sendError) {
                status.textContent = uiText('thread_load_failed');
            }
        } finally {
            state.request = null;
        }
    }

    function openThread(id) {
        const chatId = Number(currentChat?.id);
        if (!Number.isSafeInteger(id) || id <= 0 || !(chatId > 0) || dialog.open) return;
        reset();
        const state = {id, chatId, latest: 0, request: null, pending: null, followVersion: 0};
        current = state;
        root.replaceChildren();
        replies.replaceChildren();
        older.hidden = true;
        form.hidden = true;
        follow.hidden = true;
        follow.disabled = false;
        submit.disabled = false;
        input.value = '';
        status.textContent = uiText('thread_loading');
        dialog.showModal();
        load(state, 'initial');
        state.timer = setInterval(() => {
            if (document.visibilityState === 'visible') load(state, state.loaded ? 'later' : 'initial');
        }, 8000);
    }

    function openFromLocation() {
        openThread(Number(new URLSearchParams(location.search).get('thread')));
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('button[data-thread-id]');
        if (button) openThread(Number(button.dataset.threadId));
    });

    older.addEventListener('click', () => {
        if (current && replies.firstElementChild) load(current, 'earlier');
    });
    follow.addEventListener('click', async () => {
        const state = current;
        if (!state || state.blocked || state.followingPending) return;
        const following = follow.getAttribute('aria-pressed') !== 'true';
        state.followingPending = true;
        state.followVersion += 1;
        follow.disabled = true;
        try {
            const result = await postForm(`/api/threads/${state.id}/follow`, {
                csrf_token: getCsrfToken(), following: following ? '1' : '0',
            }, {timeoutMs: 15000});
            if (current !== state) return;
            if (typeof result?.following === 'boolean') follow.setAttribute('aria-pressed', String(result.following));
            else status.textContent = uiActionError(result?.error, 'thread_follow_failed');
        } catch {
            if (current === state) status.textContent = uiText('thread_follow_failed');
        } finally {
            state.followingPending = false;
            state.followVersion += 1;
            if (current === state) follow.disabled = false;
        }
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const state = current;
        const content = input.value.trim();
        if (!state || state.blocked || state.sending || !content) return;
        if (state.pending?.content !== content) state.pending = {content, key: crypto.randomUUID()};
        state.sending = true;
        state.sendError = false;
        submit.disabled = true;
        status.textContent = '';
        try {
            const result = await postForm(`/api/threads/${state.id}`, {
                csrf_token: getCsrfToken(), content, client_key: state.pending.key,
            }, {timeoutMs: 15000});
            if (current !== state) return;
            if (!result?.reply) {
                state.sendError = true;
                status.textContent = uiActionError(result?.error, 'thread_send_failed');
                return;
            }
            addReply(result.reply);
            if (input.value.trim() === content) input.value = '';
            state.pending = null;
            input.focus();
            history.scrollTop = history.scrollHeight;
            load(state, 'later');
        } catch {
            if (current === state) {
                state.sendError = true;
                status.textContent = uiText('thread_send_failed');
            }
        } finally {
            state.sending = false;
            if (current === state) submit.disabled = false;
        }
    });
    dialog.querySelector('[data-thread-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        event.stopPropagation();
        dialog.close();
    });
    dialog.addEventListener('close', () => {
        if (!dialog.open) reset();
    });
    registerRouteLifecycleHook('before', () => {
        if (dialog.open) dialog.close();
        reset();
    });
    registerRouteLifecycleHook('after', openFromLocation);
    queueMicrotask(openFromLocation);
}
