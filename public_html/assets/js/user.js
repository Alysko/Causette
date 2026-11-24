// Extracted from app.js for feature-focused organization.

function navigateWithFallback(url, options = {}) {
    const targetUrl = appPath(String(url || '').trim());
    if (!targetUrl) return;

    if (typeof window.navigateApp === 'function') {
        window.navigateApp(targetUrl, options).catch(() => {
            window.location.href = targetUrl;
        });
        return;
    }

    window.location.href = targetUrl;
}

async function reloadCurrentView(options = {}) {
    if (typeof window.reloadAppView === 'function') {
        const success = await window.reloadAppView(options).catch(() => false);
        if (success) return;
    }

    window.location.reload();
}

async function sendFriendRequest(event) {
    event.preventDefault();
    const input = document.getElementById('friend-number');
    if (!input) return;
    await sendFriendRequestByValue(input.value);
}

async function sendFriendRequestByValue(userNumber) {
    const result = await postForm('/friends/request', {
        csrf_token: getCsrfToken(),
        user_number: userNumber
    });

    if (result.success) {
        showToast(uiText('friend_request_sent'), 'success');
    } else {
        showToast(uiActionError(result.error, 'unable_to_send_friend_request'), 'error');
    }
}

async function acceptFriendRequest(requesterId) {
    const result = await postForm('/friends/accept', {
        csrf_token: getCsrfToken(),
        requester_id: String(requesterId)
    });
    if (result.success) {
        navigateWithFallback(`/c/${formatNumber(result.chat_number)}`, { source: 'accept-friend', updateHistory: true });
        return;
    }
    showToast(uiActionError(result.error, 'unable_to_accept_request'), 'error');
}

async function cancelFriendRequest(targetUserId) {
    const result = await postForm('/friends/cancel', {
        csrf_token: getCsrfToken(),
        target_user_id: String(targetUserId)
    });

    if (result.success) {
        showToast(uiText('friend_request_cancelled'), 'success');
        await reloadCurrentView({ source: 'cancel-friend-request' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_cancel_request'), 'error');
}

function openInviteReferralModal() {
    const modal = document.getElementById('invite-referral-modal');
    if (!modal) return;

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
}

function closeInviteReferralModal() {
    const modal = document.getElementById('invite-referral-modal');
    if (!modal) return;

    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
}

async function sendInviteReferralFriendRequest() {
    const modal = document.getElementById('invite-referral-modal');
    const submitButton = document.getElementById('invite-referral-modal-send');
    if (!modal || !submitButton || submitButton.disabled) return;

    const userNumber = String(modal.getAttribute('data-referrer-user-number') || '').trim();
    const username = String(modal.getAttribute('data-referrer-username') || '').trim();
    if (!userNumber) {
        closeInviteReferralModal();
        return;
    }

    submitButton.disabled = true;
    const previousLabel = submitButton.textContent;
    submitButton.textContent = uiText('sending');

    try {
        const result = await postForm('/friends/request', {
            csrf_token: getCsrfToken(),
            user_number: userNumber
        });

        if (result.success) {
            showToast(username ? uiText('friend_request_sent_to', { name: username }) : uiText('friend_request_sent'), 'success');
        } else {
            showToast(uiActionError(result.error, 'unable_to_send_friend_request'), 'error');
        }

        closeInviteReferralModal();
    } finally {
        submitButton.disabled = false;
        submitButton.textContent = previousLabel;
    }
}

function bindInviteReferralModal() {
    const modal = document.getElementById('invite-referral-modal');
    const skipButton = document.getElementById('invite-referral-modal-skip');
    const sendButton = document.getElementById('invite-referral-modal-send');
    if (!modal || !skipButton || !sendButton) return;

    skipButton.addEventListener('click', (event) => {
        event.preventDefault();
        closeInviteReferralModal();
    });

    sendButton.addEventListener('click', (event) => {
        event.preventDefault();
        sendInviteReferralFriendRequest();
    });

    modal.addEventListener('click', (event) => {
        if (event.target !== modal) return;
        closeInviteReferralModal();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (modal.classList.contains('hidden')) return;
        closeInviteReferralModal();
    });

    if (String(modal.getAttribute('data-auto-open') || '') === '1') {
        openInviteReferralModal();
    }
}

async function unfriendUser(userId) {
    const result = await postForm('/friends/unfriend', {
        csrf_token: getCsrfToken(),
        user_id: String(userId)
    });

    if (result.success) {
        showToast(uiText('friend_removed'), 'success');
        await reloadCurrentView({ source: 'unfriend-user' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_unfriend_user'), 'error');
}

function openUnfriendModal(userId) {
    const safeUserId = Number(userId || 0);
    if (!Number.isFinite(safeUserId) || safeUserId <= 0) {
        showToast(uiText('invalid_user'), 'error');
        return;
    }

    const modal = document.getElementById('unfriend-confirm-modal');
    if (!modal) {
        unfriendUser(safeUserId);
        return;
    }

    pendingUnfriendUserId = safeUserId;
    modal.classList.remove('hidden');
}

function closeUnfriendModal() {
    const modal = document.getElementById('unfriend-confirm-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    pendingUnfriendUserId = 0;
}

function bindUnfriendModal() {
    const modal = document.getElementById('unfriend-confirm-modal');
    const cancel = document.getElementById('unfriend-confirm-cancel');
    const confirm = document.getElementById('unfriend-confirm-submit');
    if (!modal || !cancel || !confirm) return;

    cancel.addEventListener('click', (event) => {
        event.preventDefault();
        closeUnfriendModal();
    });

    modal.addEventListener('click', (event) => {
        if (event.target !== modal) return;
        closeUnfriendModal();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (modal.classList.contains('hidden')) return;
        closeUnfriendModal();
    });

    confirm.addEventListener('click', async (event) => {
        event.preventDefault();
        if (confirm.disabled) return;
        if (pendingUnfriendUserId <= 0) {
            closeUnfriendModal();
            return;
        }

        confirm.disabled = true;
        const previousLabel = confirm.textContent;
        confirm.textContent = uiText('removing');

        try {
            await unfriendUser(pendingUnfriendUserId);
        } finally {
            confirm.disabled = false;
            confirm.textContent = previousLabel;
            closeUnfriendModal();
        }
    });
}

async function toggleFavoriteUser(userId, favorite) {
    const result = await postForm('/friends/favorite', {
        csrf_token: getCsrfToken(),
        user_id: String(userId),
        favorite: favorite ? '1' : '0'
    });

    if (result.success) {
        showToast(result.is_favorite ? uiText('favorite_added') : uiText('favorite_removed'), 'success');
        await reloadCurrentView({ source: 'toggle-favorite-user' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_update_favorite'), 'error');
}

let openProfilePostReactionPickerId = 0;

function closeAllProfilePostReactionPickers(resetState = true) {
    document.querySelectorAll('.js-profile-post-reaction-picker').forEach((picker) => {
        picker.classList.add('hidden');
    });

    if (resetState) {
        openProfilePostReactionPickerId = 0;
    }
}

function toggleProfilePostReactionPicker(postId) {
    const safePostId = Number(postId || 0);
    if (!Number.isFinite(safePostId) || safePostId <= 0) return;

    const picker = document.querySelector(`.js-profile-post-reaction-picker[data-post-reaction-picker-for="${safePostId}"]`);
    if (!picker) return;

    const willOpen = picker.classList.contains('hidden');
    closeAllProfilePostReactionPickers(false);

    if (willOpen) {
        showReactionPalette(picker, safePostId, 'post');
        openProfilePostReactionPickerId = safePostId;
        return;
    }

    openProfilePostReactionPickerId = 0;
}

async function reactToProfilePost(postId, reactionCode) {
    const safePostId = Number(postId || 0);
    const safeReactionCode = normalizeReactionCode(reactionCode);
    if (!Number.isFinite(safePostId) || safePostId <= 0 || !safeReactionCode) {
        return;
    }

    const result = await postForm('/api/posts/react', {
        csrf_token: getCsrfToken(),
        post_id: String(safePostId),
        reaction_code: safeReactionCode
    });

    if (!result.success) {
        showToast(uiActionError(result.error, 'unable_to_react_to_post'), 'error');
        return;
    }

    await reloadCurrentView({ source: 'react-profile-post' });
}

async function createProfilePost(content) {
    const text = String(content || '').trim();
    const length = Array.from(text).length;

    if (!text) {
        showToast(uiText('post_content_is_required'), 'error');
        return false;
    }
    if (length > 500) {
        showToast(uiText('posts_can_be_at_most_500_characters'), 'error');
        return false;
    }

    const result = await postForm('/api/posts', {
        csrf_token: getCsrfToken(),
        content: text
    });

    if (!result.success) {
        showToast(uiActionError(result.error, 'unable_to_create_post'), 'error');
        return false;
    }

    if (typeof queuePendingPageToast === 'function') {
        queuePendingPageToast(uiText('post_published'), 'success');
    } else {
        showToast(uiText('post_published'), 'success');
    }
    await reloadCurrentView({ source: 'create-profile-post' });
    return true;
}

async function deleteProfilePost(postId) {
    const safePostId = Number(postId || 0);
    if (!Number.isFinite(safePostId) || safePostId <= 0) {
        showToast(uiText('invalid_post'), 'error');
        return false;
    }

    const result = await postForm('/api/posts/delete', {
        csrf_token: getCsrfToken(),
        post_id: String(safePostId)
    });

    if (!result.success) {
        showToast(uiActionError(result.error, 'unable_to_delete_post'), 'error');
        return false;
    }

    if (typeof queuePendingPageToast === 'function') {
        queuePendingPageToast(uiText('post_deleted'), 'success');
    } else {
        showToast(uiText('post_deleted'), 'success');
    }

    await reloadCurrentView({ source: 'delete-profile-post' });
    return true;
}

function openProfilePostDeleteModal(postId, username = '') {
    const safePostId = Number(postId || 0);
    if (!Number.isFinite(safePostId) || safePostId <= 0) {
        showToast(uiText('invalid_post'), 'error');
        return;
    }

    const modal = document.getElementById('profile-post-delete-modal');
    const description = document.getElementById('profile-post-delete-description');
    if (!modal) {
        deleteProfilePost(safePostId).catch(() => {
            showToast(uiText('unable_to_delete_post'), 'error');
        });
        return;
    }

    const safeUsername = String(username || '').trim();
    if (description) {
        description.textContent = safeUsername
            ? uiText('delete_post_by_user_confirm', { name: safeUsername })
            : uiText('delete_post_confirm');
    }

    pendingProfilePostDeleteId = safePostId;
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
}

function closeProfilePostDeleteModal() {
    const modal = document.getElementById('profile-post-delete-modal');
    if (!modal) return;

    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    pendingProfilePostDeleteId = 0;
}

function bindProfilePostDeleteModal() {
    const modal = document.getElementById('profile-post-delete-modal');
    const cancel = document.getElementById('profile-post-delete-cancel');
    const submit = document.getElementById('profile-post-delete-submit');
    if (!modal || !cancel || !submit) return;

    cancel.addEventListener('click', (event) => {
        event.preventDefault();
        closeProfilePostDeleteModal();
    });

    modal.addEventListener('click', (event) => {
        if (event.target !== modal) return;
        closeProfilePostDeleteModal();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (modal.classList.contains('hidden')) return;
        closeProfilePostDeleteModal();
    });

    submit.addEventListener('click', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;
        if (!Number.isFinite(pendingProfilePostDeleteId) || pendingProfilePostDeleteId <= 0) {
            closeProfilePostDeleteModal();
            return;
        }

        submit.disabled = true;
        const previousLabel = submit.textContent;
        submit.textContent = uiText('deleting');

        try {
            const success = await deleteProfilePost(pendingProfilePostDeleteId);
            if (success) {
                closeProfilePostDeleteModal();
            }
        } finally {
            submit.disabled = false;
            submit.textContent = previousLabel;
        }
    });
}

function openNewPostModal() {
    const modal = document.getElementById('new-post-modal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    const input = document.getElementById('new-post-modal-input');
    if (input) {
        input.value = '';
        updateNewPostModalCounter();
        setTimeout(() => input.focus(), 0);
    }
}

function closeNewPostModal() {
    const modal = document.getElementById('new-post-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    const submit = document.getElementById('new-post-modal-submit');
    if (submit) {
        submit.disabled = false;
        submit.textContent = uiText('publish');
    }
}

function updateNewPostModalCounter() {
    const input = document.getElementById('new-post-modal-input');
    const counter = document.getElementById('new-post-modal-counter');
    if (!input || !counter) return;
    const count = Array.from(String(input.value || '')).length;
    counter.textContent = `${count}/500`;
    counter.classList.toggle('text-red-300', count > 500);
}

function bindNewPostModal() {
    const modal = document.getElementById('new-post-modal');
    const closeBtn = document.getElementById('new-post-modal-close');
    const cancelBtn = document.getElementById('new-post-modal-cancel');
    const form = document.getElementById('new-post-modal-form');
    const input = document.getElementById('new-post-modal-input');
    const submit = document.getElementById('new-post-modal-submit');
    if (!modal || !form || !input || !submit) return;

    input.addEventListener('input', updateNewPostModalCounter);

    const close = () => closeNewPostModal();

    if (closeBtn) closeBtn.addEventListener('click', (event) => { event.preventDefault(); close(); });
    if (cancelBtn) cancelBtn.addEventListener('click', (event) => { event.preventDefault(); close(); });

    modal.addEventListener('click', (event) => {
        if (event.target !== modal) return;
        close();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (modal.classList.contains('hidden')) return;
        close();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;

        submit.disabled = true;
        const previousLabel = submit.textContent;
        submit.textContent = uiText('publishing');

        try {
            const success = await createProfilePost(input.value);
            if (success) {
                closeNewPostModal();
            }
        } finally {
            submit.disabled = false;
            submit.textContent = previousLabel;
        }
    });
}

function bindProfilePosts() {
    const root = document.getElementById('profile-posts-root');
    if (!root) return;

    const canReactToPosts = String(root.getAttribute('data-can-react-posts') || '0') === '1';

    root.addEventListener('click', (event) => {
        const deleteButton = event.target.closest('.js-profile-post-delete-open');
        if (deleteButton) {
            event.preventDefault();
            const postId = Number(deleteButton.getAttribute('data-post-id') || 0);
            const username = String(deleteButton.getAttribute('data-post-username') || '');
            openProfilePostDeleteModal(postId, username);
            return;
        }

        const reactLink = event.target.closest('.js-profile-post-react-link');
        if (reactLink) {
            if (!canReactToPosts) return;
            event.preventDefault();
            const postId = Number(reactLink.getAttribute('data-post-id') || 0);
            toggleProfilePostReactionPicker(postId);
            return;
        }

        const option = event.target.closest('.js-profile-post-reaction-option');
        if (option) {
            if (!canReactToPosts) return;
            event.preventDefault();
            const postId = Number(option.getAttribute('data-post-id') || 0);
            const reactionCode = String(option.getAttribute('data-reaction-code') || '');
            reactToProfilePost(postId, reactionCode).catch(() => {
                showToast(uiText('unable_to_react_to_post'), 'error');
            });
            return;
        }

        const badge = event.target.closest('.js-profile-post-reaction-badge');
        if (badge) {
            if (!canReactToPosts) return;
            event.preventDefault();
            const postId = Number(badge.getAttribute('data-post-id') || 0);
            const reactionCode = String(badge.getAttribute('data-reaction-code') || '');
            reactToProfilePost(postId, reactionCode).catch(() => {
                showToast(uiText('unable_to_update_reaction'), 'error');
            });
            return;
        }

        if (!event.target.closest('.js-profile-post-reaction-picker')) {
            closeAllProfilePostReactionPickers();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAllProfilePostReactionPickers();
        }
    });
}

async function searchUsers(event) {
    event.preventDefault();
    const input = document.getElementById('user-search-input');
    const results = document.getElementById('user-search-results');
    const help = document.getElementById('user-search-help');
    if (!input || !results) return;

    const query = input.value.trim();
    if (!query) return;
    const isValidSearch = /^[A-Za-z0-9-]+$/.test(query);
    if (!isValidSearch) {
        const label = uiText('use_only_letters_numbers_and_dashes');
        input.setCustomValidity(label);
        input.reportValidity();
        showToast(label, 'error');
        return;
    }
    input.setCustomValidity('');
    if (help) {
        help.classList.add('hidden');
    }

    const res = await fetch(appPath(`/api/users/search?q=${encodeURIComponent(query)}`));
    const data = await res.json();
    const users = data.users || [];

    const usersMarkup = users.map(user => `
        <div class="bg-zinc-800 rounded-xl p-3">
            <div class="flex items-center gap-3 min-w-0">
                ${renderAvatarMarkup(user, 'w-10 h-10', 'text-sm')}
                <div class="min-w-0">
                    <div class="font-medium truncate">${escapeHtml(user.username)}</div>
                    <div class="text-xs text-zinc-400">${escapeHtml(user.formatted_user_number || formatNumber(user.user_number))}</div>
                    <div class="text-xs ${escapeHtml(user.effective_status_text_class || 'text-zinc-500')} mt-0.5">${escapeHtml(presenceLabel(user.effective_status))}</div>
                </div>
            </div>
            ${renderSearchResultAction(user)}
        </div>
    `).join('') || `<p class="text-zinc-400 text-sm sm:col-span-2">${escapeHtml(uiText('search_no_users'))}</p>`;

    replaceElementMarkup(results, usersMarkup);
}

function renderSearchResultAction(user) {
    const formattedUserNumber = String(user.formatted_user_number || formatNumber(user.user_number || ''));
    const friendshipStatus = String(user.friendship_status || '').toLowerCase();
    const personalChatNumber = String(user.personal_chat_number || '').trim();

    if (friendshipStatus === 'accepted' && personalChatNumber !== '') {
        const chatUrl = appPath(`/c/${personalChatNumber}`);
        return `<a href="${escapeHtml(chatUrl)}" class="mt-3 w-full bg-emerald-600 hover:bg-emerald-500 px-4 py-2 rounded-lg text-sm inline-flex items-center justify-center">${escapeHtml(uiText('search_open_direct_message'))}</a>`;
    }

    return `<button class="mt-3 w-full bg-emerald-600 hover:bg-emerald-500 px-4 py-2 rounded-lg text-sm" data-app-action="send-friend-request" data-user-number="${escapeHtml(formattedUserNumber)}">${escapeHtml(uiText('search_add_friend'))}</button>`;
}

function getAdminUserListNodes() {
    return Array.from(document.querySelectorAll('.admin-user-item'));
}

function closeAdminUserMenus() {
    const menus = document.querySelectorAll('[data-admin-user-menu]');
    if (menus.length === 0) return;

    menus.forEach((menu) => menu.classList.add('hidden'));
    openAdminUserMenuId = 0;
}

function toggleAdminUserMenu(userId) {
    const safeUserId = Number(userId || 0);
    if (!Number.isFinite(safeUserId) || safeUserId <= 0) return;

    const menu = document.getElementById(`admin-user-menu-${safeUserId}`);
    if (!menu) return;

    if (openAdminUserMenuId === safeUserId && !menu.classList.contains('hidden')) {
        closeAdminUserMenus();
        return;
    }

    closeAdminUserMenus();
    menu.classList.remove('hidden');
    openAdminUserMenuId = safeUserId;
}

function filterAdminUsersList(query) {
    const normalizedQuery = String(query || '').trim().toLowerCase();
    const nodes = getAdminUserListNodes();

    nodes.forEach((node) => {
        const username = String(node.dataset.adminUsername || '').toLowerCase();
        const userNumber = formatNumber(String(node.dataset.adminUserNumber || ''));
        const userNumberDigits = String(node.dataset.adminUserNumber || '');

        const shouldShow = normalizedQuery === ''
            || username.includes(normalizedQuery)
            || userNumber.includes(normalizedQuery)
            || userNumberDigits.includes(normalizedQuery.replace(/\D/g, ''));

        node.classList.toggle('hidden', !shouldShow);
    });
}

function renderAdminUsersTypeahead(query) {
    const container = document.getElementById('admin-users-typeahead');
    if (!container) return;

    const normalizedQuery = String(query || '').trim().toLowerCase();
    if (normalizedQuery === '') {
        container.innerHTML = '';
        container.classList.add('hidden');
        return;
    }

    const nodes = getAdminUserListNodes();
    const uniqueUsernames = [];
    const seen = new Set();

    for (const node of nodes) {
        const username = String(node.dataset.adminUsername || '').trim();
        if (!username || seen.has(username)) continue;
        if (!username.includes(normalizedQuery)) continue;
        seen.add(username);
        uniqueUsernames.push(username);
        if (uniqueUsernames.length >= 8) break;
    }

    if (uniqueUsernames.length === 0) {
        container.innerHTML = '';
        container.classList.add('hidden');
        return;
    }

    container.innerHTML = uniqueUsernames.map((username) => `
        <button type="button" class="w-full text-left px-4 py-2.5 hover:bg-zinc-800 text-sm" data-admin-typeahead="${escapeHtml(username)}">${escapeHtml(username)}</button>
    `).join('');
    container.classList.remove('hidden');
}

function getAdminUserCardById(userId) {
    const safeUserId = Number(userId || 0);
    if (!Number.isFinite(safeUserId) || safeUserId <= 0) return null;
    return document.getElementById(`admin-user-card-${safeUserId}`);
}

function getAdminUserState(userId) {
    const card = getAdminUserCardById(userId);
    if (!card) return null;

    const role = String(card.dataset.adminRole || 'user').trim().toLowerCase() === 'admin' ? 'admin' : 'user';
    const isBanned = String(card.dataset.adminIsBanned || '0') === '1';
    const username = String(card.dataset.adminUsername || '').trim();

    return {
        role,
        isBanned,
        username
    };
}

function setAdminUserRoleState(userId, role) {
    const safeUserId = Number(userId || 0);
    const nextRole = String(role || '').trim().toLowerCase() === 'admin' ? 'admin' : 'user';
    const isAdmin = nextRole === 'admin';

    const card = getAdminUserCardById(safeUserId);
    if (card) {
        card.dataset.adminRole = nextRole;
    }

    const badge = document.getElementById(`admin-user-role-badge-${safeUserId}`);
    if (badge) {
        badge.textContent = uiText(isAdmin ? 'role_admin' : 'role_user');
        badge.classList.remove('bg-emerald-700', 'text-emerald-100', 'bg-zinc-700', 'text-zinc-200');
        badge.classList.add(isAdmin ? 'bg-emerald-700' : 'bg-zinc-700', isAdmin ? 'text-emerald-100' : 'text-zinc-200');
    }

    const roleAction = document.getElementById(`admin-user-role-action-${safeUserId}`);
    if (roleAction) {
        roleAction.textContent = isAdmin ? uiText('demote') : uiText('promote');
        roleAction.classList.remove('bg-amber-700', 'hover:bg-amber-600', 'text-amber-100', 'bg-emerald-700', 'hover:bg-emerald-600', 'text-emerald-100');
        if (isAdmin) {
            roleAction.classList.add('bg-amber-700', 'hover:bg-amber-600', 'text-amber-100');
        } else {
            roleAction.classList.add('bg-emerald-700', 'hover:bg-emerald-600', 'text-emerald-100');
        }
    }
}

function setAdminUserBanState(userId, isBanned) {
    const safeUserId = Number(userId || 0);
    const nextIsBanned = Boolean(isBanned);

    const card = getAdminUserCardById(safeUserId);
    if (card) {
        card.dataset.adminIsBanned = nextIsBanned ? '1' : '0';
    }

    const bannedBadge = document.getElementById(`admin-user-banned-badge-${safeUserId}`);
    if (bannedBadge) {
        bannedBadge.classList.toggle('hidden', !nextIsBanned);
    }

    const banAction = document.getElementById(`admin-user-ban-action-${safeUserId}`);
    if (banAction) {
        banAction.textContent = nextIsBanned ? uiText('unban') : uiText('ban');
    }
}

function closeAdminUserActionModal() {
    const modal = document.getElementById('admin-user-action-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    pendingAdminUserAction = null;
}

function openAdminUserActionModal(config) {
    const modal = document.getElementById('admin-user-action-modal');
    const title = document.getElementById('admin-user-action-modal-title');
    const description = document.getElementById('admin-user-action-modal-description');
    const submit = document.getElementById('admin-user-action-modal-submit');
    const retainMessagesWrap = document.getElementById('admin-user-retain-messages-wrap');
    const retainMessagesInput = document.getElementById('admin-user-retain-messages');
    if (!modal || !title || !description || !submit) return false;

    const submitLabel = String(config?.submitLabel || uiText('confirm')).trim() || uiText('confirm');
    const submitTone = String(config?.submitTone || 'emerald').trim().toLowerCase();

    title.textContent = String(config?.title || uiText('confirm_action'));
    description.textContent = String(config?.description || uiText('are_you_sure'));
    submit.textContent = submitLabel;
    submit.classList.remove('bg-emerald-700', 'hover:bg-emerald-600', 'bg-amber-700', 'hover:bg-amber-600', 'bg-red-700', 'hover:bg-red-600');

    if (submitTone === 'red') {
        submit.classList.add('bg-red-700', 'hover:bg-red-600');
    } else if (submitTone === 'amber') {
        submit.classList.add('bg-amber-700', 'hover:bg-amber-600');
    } else {
        submit.classList.add('bg-emerald-700', 'hover:bg-emerald-600');
    }

    const showRetainMessages = Boolean(config?.showRetainMessages);
    if (retainMessagesWrap && retainMessagesInput) {
        retainMessagesWrap.classList.toggle('hidden', !showRetainMessages);
        retainMessagesWrap.classList.toggle('flex', showRetainMessages);
        retainMessagesInput.checked = showRetainMessages ? Boolean(config?.retainMessagesDefault) : false;
    }

    pendingAdminUserAction = config;
    modal.classList.remove('hidden');
    return true;
}

async function performAdminUserRoleAction(userId) {
    const safeUserId = Number(userId || 0);
    const currentState = getAdminUserState(safeUserId);
    if (!currentState) {
        showToast(uiText('invalid_user'), 'error');
        return;
    }

    const nextRole = currentState.role === 'admin' ? 'user' : 'admin';

    const result = await postForm('/users/change-group', {
        csrf_token: getCsrfToken(),
        user_id: String(safeUserId),
        role: nextRole
    });

    if (!result.success) {
        showToast(uiActionError(result.error, 'unable_to_change_user_group'), 'error');
        return;
    }

    setAdminUserRoleState(safeUserId, nextRole);

    showToast(nextRole === 'admin' ? uiText('admin_promoted') : uiText('admin_demoted'), 'success');
    closeAdminUserMenus();
}

async function performAdminUserBanAction(userId) {
    const safeUserId = Number(userId || 0);
    const currentState = getAdminUserState(safeUserId);
    if (!currentState) {
        showToast(uiText('invalid_user'), 'error');
        return;
    }

    const nextIsBanned = !currentState.isBanned;

    const result = await postForm('/users/ban', {
        csrf_token: getCsrfToken(),
        user_id: String(safeUserId),
        is_banned: nextIsBanned ? '1' : '0'
    });

    if (!result.success) {
        showToast(result.error || uiText(nextIsBanned ? 'admin_ban_failed' : 'admin_unban_failed'), 'error');
        return;
    }

    const effectiveIsBanned = Number(result.is_banned) === 1;
    setAdminUserBanState(safeUserId, effectiveIsBanned);

    showToast(effectiveIsBanned ? uiText('admin_banned') : uiText('admin_unbanned'), 'success');
    closeAdminUserMenus();
}

async function performDeleteAdminUser(userId, retainMessages = false) {
    const safeUserId = Number(userId || 0);
    if (!Number.isFinite(safeUserId) || safeUserId <= 0) {
        showToast(uiText('invalid_user'), 'error');
        return;
    }

    const result = await postForm('/users/delete', {
        csrf_token: getCsrfToken(),
        user_id: String(safeUserId),
        retain_messages: retainMessages ? '1' : '0'
    });

    if (!result.success) {
        showToast(uiActionError(result.error, 'unable_to_delete_user'), 'error');
        return;
    }

    const card = document.getElementById(`admin-user-card-${safeUserId}`);
    if (card) {
        card.remove();
    }

    showToast(retainMessages ? uiText('admin_deleted_retained') : uiText('admin_deleted'), 'success');
    closeAdminUserMenus();
}

function confirmAdminUserRoleAction(userId) {
    const safeUserId = Number(userId || 0);
    const state = getAdminUserState(safeUserId);
    if (!state) {
        showToast(uiText('invalid_user'), 'error');
        return;
    }

    const makeAdmin = state.role !== 'admin';
    const username = state.username ? `@${state.username}` : uiText('account_this_user');
    const opened = openAdminUserActionModal({
        type: 'role',
        userId: safeUserId,
        title: makeAdmin ? uiText('admin_promote_title') : uiText('admin_demote_title'),
        description: makeAdmin
            ? uiText('admin_promote_confirm', { name: username })
            : uiText('admin_demote_confirm', { name: username }),
        submitLabel: makeAdmin ? uiText('promote') : uiText('demote'),
        submitTone: makeAdmin ? 'emerald' : 'amber'
    });

    if (!opened) {
        performAdminUserRoleAction(safeUserId);
    }
}

function confirmAdminUserBanAction(userId) {
    const safeUserId = Number(userId || 0);
    const state = getAdminUserState(safeUserId);
    if (!state) {
        showToast(uiText('invalid_user'), 'error');
        return;
    }

    const shouldBan = !state.isBanned;
    const username = state.username ? `@${state.username}` : uiText('account_this_user');
    const opened = openAdminUserActionModal({
        type: 'ban',
        userId: safeUserId,
        title: shouldBan ? uiText('admin_ban_title') : uiText('admin_unban_title'),
        description: shouldBan
            ? uiText('admin_ban_confirm', { name: username })
            : uiText('admin_unban_confirm', { name: username }),
        submitLabel: shouldBan ? uiText('ban') : uiText('unban'),
        submitTone: 'amber'
    });

    if (!opened) {
        performAdminUserBanAction(safeUserId);
    }
}

function deleteAdminUser(userId) {
    const safeUserId = Number(userId || 0);
    const state = getAdminUserState(safeUserId);
    if (!state) {
        showToast(uiText('invalid_user'), 'error');
        return;
    }

    const username = state.username ? `@${state.username}` : uiText('account_this_user');
    const opened = openAdminUserActionModal({
        type: 'delete',
        userId: safeUserId,
        title: uiText('admin_delete_title'),
        description: uiText('admin_delete_confirm', { name: username }),
        submitLabel: uiText('delete'),
        submitTone: 'red',
        showRetainMessages: true,
        retainMessagesDefault: false
    });

    if (!opened) {
        performDeleteAdminUser(safeUserId, false);
    }
}

function changeAdminUserGroup(userId) {
    confirmAdminUserRoleAction(userId);
}

async function banAdminUser(userId) {
    confirmAdminUserBanAction(userId);
}

function bindAdminUserActionModal() {
    const modal = document.getElementById('admin-user-action-modal');
    const cancel = document.getElementById('admin-user-action-modal-cancel');
    const submit = document.getElementById('admin-user-action-modal-submit');
    if (!modal || !cancel || !submit) return;

    cancel.addEventListener('click', (event) => {
        event.preventDefault();
        closeAdminUserActionModal();
    });

    modal.addEventListener('click', (event) => {
        if (event.target !== modal) return;
        closeAdminUserActionModal();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (modal.classList.contains('hidden')) return;
        closeAdminUserActionModal();
    });

    submit.addEventListener('click', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;
        const retainMessagesInput = document.getElementById('admin-user-retain-messages');

        const action = pendingAdminUserAction;
        if (!action || !Number.isFinite(Number(action.userId || 0)) || Number(action.userId || 0) <= 0) {
            closeAdminUserActionModal();
            return;
        }

        const safeUserId = Number(action.userId || 0);
        const previousLabel = submit.textContent;
        submit.disabled = true;
        submit.textContent = uiText('working');

        try {
            if (action.type === 'role') {
                await performAdminUserRoleAction(safeUserId);
            } else if (action.type === 'ban') {
                await performAdminUserBanAction(safeUserId);
            } else if (action.type === 'delete') {
                const retainMessages = Boolean(retainMessagesInput?.checked);
                await performDeleteAdminUser(safeUserId, retainMessages);
            }
        } finally {
            submit.disabled = false;
            submit.textContent = previousLabel;
            closeAdminUserActionModal();
        }
    });
}

function bindAdminUsersPage() {
    const input = document.getElementById('admin-users-search-input');
    const typeahead = document.getElementById('admin-users-typeahead');
    if (!input || !typeahead) return;

    bindAdminUserActionModal();

    filterAdminUsersList('');

    input.addEventListener('input', () => {
        const query = input.value || '';
        filterAdminUsersList(query);
        renderAdminUsersTypeahead(query);
    });

    input.addEventListener('focus', () => {
        renderAdminUsersTypeahead(input.value || '');
    });

    document.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof Element)) {
            closeAdminUserMenus();
            typeahead.classList.add('hidden');
            return;
        }

        const typeaheadButton = target.closest('[data-admin-typeahead]');
        if (typeaheadButton instanceof HTMLElement) {
            const username = String(typeaheadButton.dataset.adminTypeahead || '').trim();
            input.value = username;
            filterAdminUsersList(username);
            typeahead.classList.add('hidden');
            return;
        }

        if (!target.closest('[data-admin-user-menu]') && !target.closest('button[onclick^="toggleAdminUserMenu"]')) {
            closeAdminUserMenus();
        }

        if (!target.closest('#admin-users-search-input') && !target.closest('#admin-users-typeahead')) {
            typeahead.classList.add('hidden');
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        closeAdminUserMenus();
        typeahead.classList.add('hidden');
    });
}

async function reportTarget(targetType, targetId) {
    const safeTargetType = String(targetType || '').trim().toLowerCase();
    const safeTargetId = Number(targetId || 0);
    if (!safeTargetType || !Number.isFinite(safeTargetId) || safeTargetId <= 0) {
        showToast(uiText('unable_to_create_report_for_this_item'), 'error');
        return;
    }

    if (typeof window.openReportModal === 'function') {
        window.openReportModal(safeTargetType, safeTargetId);
        return;
    }

    showToast(uiText('report_form_is_unavailable_on_this_page'), 'error');
}

function bindReportModal() {
    const modal = document.getElementById('report-modal');
    const description = document.getElementById('report-modal-description');
    const form = document.getElementById('report-form');
    const input = document.getElementById('report-reason-input');
    const counter = document.getElementById('report-reason-counter');
    const cancel = document.getElementById('report-cancel');
    const submit = document.getElementById('report-submit');
    if (!modal || !description || !form || !input || !cancel || !submit) return;

    const targetLabelByType = {
        user: uiText('report_target_user'),
        chat: uiText('report_target_chat'),
        message: uiText('report_target_message')
    };

    const getTargetLabel = () => targetLabelByType[pendingReportTargetType] || uiText('report_target_content');

    const updateReasonCounter = () => {
        const safeValue = String(input.value || '').slice(0, REPORT_REASON_MAX_LENGTH);
        if (safeValue !== input.value) {
            input.value = safeValue;
        }

        if (counter) {
            counter.textContent = `${safeValue.length}/${REPORT_REASON_MAX_LENGTH}`;
        }
    };

    const setOpenState = (isOpen) => {
        modal.classList.toggle('hidden', !isOpen);

        if (isOpen) {
            description.textContent = uiText('report_request_description', { target: getTargetLabel() });
            input.value = '';
            updateReasonCounter();
            setTimeout(() => {
                input.focus();
            }, 0);
            return;
        }

        submit.disabled = false;
        submit.textContent = uiText('submit_report');
    };

    const closeModal = () => {
        setOpenState(false);
    };

    cancel.addEventListener('click', (event) => {
        event.preventDefault();
        closeModal();
    });

    modal.addEventListener('click', (event) => {
        if (event.target !== modal) return;
        closeModal();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (modal.classList.contains('hidden')) return;
        closeModal();
    });

    input.addEventListener('input', () => {
        updateReasonCounter();
    });

    updateReasonCounter();

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;

        const reason = String(input.value || '').trim().slice(0, REPORT_REASON_MAX_LENGTH);
        if (!reason) {
            showToast(uiText('please_enter_a_reason'), 'error');
            input.focus();
            return;
        }

        if (!pendingReportTargetType || !Number.isFinite(pendingReportTargetId) || pendingReportTargetId <= 0) {
            showToast(uiText('unable_to_submit_report_right_now'), 'error');
            return;
        }

        submit.disabled = true;
        submit.textContent = uiText('submitting');

        try {
            const result = await postForm('/api/report', {
                csrf_token: getCsrfToken(),
                target_type: pendingReportTargetType,
                target_id: String(pendingReportTargetId),
                reason
            });

            if (result.success) {
                showToast(uiText('report_submitted'), 'success');
                closeModal();
                return;
            }

            showToast(uiActionError(result.error, 'failed_to_submit_report'), 'error');
        } finally {
            submit.disabled = false;
            submit.textContent = uiText('submit_report');
        }
    });

    window.openReportModal = (targetType, targetId) => {
        pendingReportTargetType = String(targetType || '').trim().toLowerCase();
        pendingReportTargetId = Number(targetId || 0);
        if (!pendingReportTargetType || !Number.isFinite(pendingReportTargetId) || pendingReportTargetId <= 0) {
            showToast(uiText('unable_to_create_report_for_this_item'), 'error');
            return;
        }

        setOpenState(true);
    };
}


function applySidebarStatus(payload) {
    const statusWrap = document.getElementById('sidebar-user-status');
    const statusDot = document.getElementById('sidebar-user-status-dot');
    const statusLabel = document.getElementById('sidebar-user-status-label');
    if (!statusWrap || !statusDot || !statusLabel) return;

    statusWrap.classList.remove('text-emerald-400', 'text-amber-400', 'text-red-400');
    statusDot.classList.remove('bg-emerald-500', 'bg-amber-500', 'bg-red-500', 'bg-sky-500');

    statusWrap.classList.add(String(payload?.effective_status_text_class || 'text-emerald-400'));
    statusDot.classList.add(String(payload?.effective_status_dot_class || 'bg-emerald-500'));
    statusLabel.textContent = presenceLabel(payload?.effective_status, 'online');
}

function updateStatusMenuChecks(status) {
    document.querySelectorAll('[data-status-check]').forEach((icon) => {
        const iconStatus = String(icon.getAttribute('data-status-check') || '');
        icon.classList.toggle('hidden', iconStatus !== status);
    });
}

async function savePresenceStatus(status, options = {}) {
    const safeStatus = String(status || '').toLowerCase();
    if (safeStatus !== 'online' && safeStatus !== 'busy' && safeStatus !== 'offline') {
        return;
    }

    const result = await postForm('/api/status', {
        csrf_token: getCsrfToken(),
        status: safeStatus
    });

    if (!result.success) {
        if (!options.silent) {
            showToast(uiActionError(result.error, 'unable_to_update_status'), 'error', { excludeFromHistory: true });
        }
        return;
    }

    selectedPresenceStatus = safeStatus;
    applySidebarStatus(result);
    updateStatusMenuChecks(selectedPresenceStatus);

    if (!options.silent) {
        showToast(uiText('presence_status_saved', { status: presenceLabel(result.effective_status, 'online') }), 'success', { excludeFromHistory: true });
    }
}

let presenceActivityBound = false;
let systemIdleDetector = null;

async function startSystemIdleDetection() {
    if (systemIdleDetector || !window.IdleDetector) return;
    const detector = new window.IdleDetector();
    detector.addEventListener('change', () => {
        systemPresenceIdle = detector.userState === 'idle' || detector.screenState === 'locked';
        if (!systemPresenceIdle) lastPresenceInteraction = Date.now();
    });
    await detector.start({ threshold: 60000 });
    systemIdleDetector = detector;
    document.getElementById('enable-system-pause')?.classList.add('hidden');
}

async function enableSystemPause() {
    try {
        if (await window.IdleDetector.requestPermission() !== 'granted') {
            throw new Error('Permission declined');
        }
        await startSystemIdleDetection();
    } catch {
        showToast(uiText('presence_system_unavailable'), 'error');
    }
}

function bindPresenceActivity() {
    if (presenceActivityBound || !window.CURRENT_USER_ID) return;
    presenceActivityBound = true;
    const recordActivity = () => { lastPresenceInteraction = Date.now(); };
    document.addEventListener('pointerdown', recordActivity, { passive: true });
    document.addEventListener('keydown', recordActivity);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) recordActivity();
    });
    if (window.isSecureContext && window.IdleDetector) {
        const control = document.getElementById('enable-system-pause');
        control?.classList.remove('hidden');
        control?.addEventListener('click', enableSystemPause);
        navigator.permissions?.query({ name: 'idle-detection' }).then(permission => {
            if (permission.state === 'granted') return startSystemIdleDetection();
        }).catch(() => {});
    }
}

function bindStatusMenu() {
    const toggle = document.getElementById('status-menu-toggle');
    const menu = document.getElementById('status-menu');
    if (!toggle || !menu) return;
    if (toggle.dataset.bound === '1') return;
    toggle.dataset.bound = '1';

    const storedStatus = String(window.CURRENT_USER_PRESENCE_STATUS || 'online').toLowerCase();
    selectedPresenceStatus = storedStatus === 'busy' ? 'busy' : (storedStatus === 'offline' ? 'offline' : 'online');
    updateStatusMenuChecks(selectedPresenceStatus);

    const closeMenu = () => menu.classList.add('hidden');

    toggle.addEventListener('click', (event) => {
        event.preventDefault();
        menu.classList.toggle('hidden');
    });

    menu.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-status-choice]');
        if (!button) return;

        const nextStatus = String(button.getAttribute('data-status-choice') || '').toLowerCase();
        if (nextStatus === selectedPresenceStatus) {
            closeMenu();
            return;
        }

        await savePresenceStatus(nextStatus);
        closeMenu();
    });

    document.addEventListener('click', (event) => {
        if (menu.contains(event.target) || toggle.contains(event.target)) return;
        closeMenu();
    });
}

function bindChatHeaderMenu() {
    const toggle = document.getElementById('chat-header-menu-toggle');
    const menu = document.getElementById('chat-header-menu');
    if (!toggle || !menu) return;

    const closeMenu = () => {
        menu.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
    };

    toggle.addEventListener('click', (event) => {
        event.preventDefault();
        const willOpen = menu.classList.contains('hidden');
        menu.classList.toggle('hidden', !willOpen);
        toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });

    menu.addEventListener('click', async (event) => {
        const actionButton = event.target.closest('[data-chat-action]');
        if (!actionButton) return;

        const action = String(actionButton.getAttribute('data-chat-action') || '');
        closeMenu();

        if (action === 'add-user') {
            if (typeof window.openAddUserModal === 'function') {
                window.openAddUserModal();
            }
            return;
        }

        if (action === 'create-poll') {
            if (typeof window.openCreatePollModal === 'function') {
                window.openCreatePollModal();
            }
            return;
        }

        if (action === 'rename-chat') {
            if (typeof window.openRenameChatModal === 'function') {
                window.openRenameChatModal();
            }
            return;
        }

        if (action === 'leave-group') {
            if (typeof window.leaveCurrentGroup === 'function') {
                await window.leaveCurrentGroup();
            }
            return;
        }

        if (action === 'delete-group') {
            if (typeof window.deleteCurrentGroup === 'function') {
                await window.deleteCurrentGroup();
            }
            return;
        }

        if (action === 'group-settings' || action === 'message-settings') {
            if (typeof window.openMessageSettingsModal === 'function') {
                window.openMessageSettingsModal();
            }
            return;
        }

        if (action === 'take-ownership') {
            if (typeof window.takeCurrentGroupOwnership === 'function') {
                await window.takeCurrentGroupOwnership();
            }
            return;
        }

        if (action === 'report-chat' && currentChat) {
            await reportTarget('chat', currentChat.id);
        }
    });

    document.addEventListener('click', (event) => {
        if (menu.contains(event.target) || toggle.contains(event.target)) return;
        closeMenu();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (menu.classList.contains('hidden')) return;
        closeMenu();
    });
}


async function copyTextToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return;
    }

    const tempInput = document.createElement('textarea');
    tempInput.value = text;
    tempInput.setAttribute('readonly', '');
    tempInput.style.position = 'absolute';
    tempInput.style.left = '-9999px';
    document.body.appendChild(tempInput);
    tempInput.select();
    document.execCommand('copy');
    tempInput.remove();
}

function bindInviteCopyButtons() {
    const buttons = document.querySelectorAll('[data-copy-invite]');
    if (!buttons.length) return;

    buttons.forEach((button) => {
        if (button.dataset.bound === '1') return;
        button.dataset.bound = '1';

        button.addEventListener('click', async () => {
            const value = button.dataset.copyValue || '';
            if (!value) return;

            try {
                await copyTextToClipboard(value);
                const icon = button.querySelector('i');
                if (icon) {
                    icon.classList.remove('fa-copy');
                    icon.classList.add('fa-check');
                    button.classList.remove('text-zinc-400', 'hover:text-zinc-200');
                    button.classList.add('text-emerald-400');

                    if (button._copyTimer) {
                        clearTimeout(button._copyTimer);
                    }

                    button._copyTimer = setTimeout(() => {
                        icon.classList.remove('fa-check');
                        icon.classList.add('fa-copy');
                        button.classList.remove('text-emerald-400');
                        button.classList.add('text-zinc-400', 'hover:text-zinc-200');
                        button._copyTimer = null;
                    }, 1200);
                }
                showToast(uiText('invite_code_copied'), 'success');
            } catch {
                showToast(uiText('unable_to_copy_invite_code'), 'error');
            }
        });
    });
}

function stripMessageMentions(content) {
    return String(content || '').replace(/@\[\d{16}\|([a-zA-Z][a-zA-Z0-9]{3,31})\]/g, '@$1');
}

function highlightSearchTerm(text, query) {
    if (!query || !text) return escapeHtml(text);
    const escapedText = escapeHtml(text);
    const escapedQuery = escapeHtml(query);
    const regexSafe = escapedQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return escapedText.replace(
        new RegExp(regexSafe, 'gi'),
        '<mark class="search-match">$&</mark>'
    );
}

function replaceElementMarkup(element, markup) {
    if (!element) return;
    const fragment = document.createRange().createContextualFragment(String(markup || ''));
    element.replaceChildren(fragment);
}

async function searchPostsPage(query, page) {
    const results = document.getElementById('post-search-results');
    if (!results) return;

    results.innerHTML = `<p class="text-zinc-400 text-sm">${escapeHtml(uiText('search_pending'))}</p>`;

    try {
        const res = await fetch(appPath(`/api/posts/search?q=${encodeURIComponent(query)}&page=${page}`));
        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            showToast(uiActionError(data.error, 'search_failed'), 'error');
            results.innerHTML = '';
            return;
        }

        const data = await res.json();
        const posts = data.posts || [];
        const totalPages = Number(data.total_pages || 1);
        const currentPage = Number(data.page || 1);
        const total = Number(data.total || 0);

        if (posts.length === 0) {
            results.innerHTML = `<p class="text-zinc-400 text-sm">${escapeHtml(uiText('search_no_posts'))}</p>`;
            return;
        }

        const postItems = posts.map(post => {
            const authorUsername = escapeHtml(String(post.author_username || ''));
            const authorNumFormatted = escapeHtml(String(post.author_user_number_formatted || ''));
            const postId = Number(post.post_id || 0);
            const profileUrl = appPath(`/u/${authorNumFormatted}?post=${postId}`);
            const rawContent = String(post.content || '');
            const highlightedContent = highlightSearchTerm(rawContent, query);
            const createdAtRaw = String(post.created_at || '');
            const timestamp = formatCompactMessageTimestamp(createdAtRaw);
            const authorObj = {
                avatar_url: String(post.author_avatar_url || ''),
                username: String(post.author_username || ''),
                user_number: String(post.author_user_number || '')
            };
            const authorId = Number(post.author_id || 0);
            const currentUserId = Number(window.CURRENT_USER_ID || 0);
            const isOwnPost = authorId > 0 && currentUserId > 0 && authorId === currentUserId;
            const isFriend = post.is_friend === true || post.is_friend === 1;
            const friendLabel = uiText(isOwnPost ? 'friend_identity_self' : (isFriend ? 'friend_identity_friend' : 'friend_identity_other'));

            return `
                <a href="${profileUrl}" class="block bg-zinc-800 hover:bg-zinc-700/80 rounded-xl p-4 transition border border-zinc-700 hover:border-zinc-600">
                    <div class="flex items-center gap-2 mb-2 min-w-0">
                        ${renderAvatarMarkup(authorObj, 'w-7 h-7', 'text-xs')}
                        <span class="font-medium text-zinc-100 truncate">${authorUsername}</span>
                        <span class="shrink-0 text-xs text-zinc-500 bg-zinc-900 border border-zinc-700 px-1.5 py-0.5 rounded">${escapeHtml(friendLabel)}</span>
                        <span class="ml-auto shrink-0 text-xs text-zinc-500" data-utc="${escapeHtml(createdAtRaw)}" title="${escapeHtml(createdAtRaw)}">${escapeHtml(timestamp)}</span>
                    </div>
                    <div class="text-sm text-zinc-300 leading-5 overflow-hidden search-preview">${highlightedContent}</div>
                </a>
            `;
        }).join('');

        let paginationHtml = '';
        if (totalPages > 1) {
            const prevDisabled = currentPage <= 1;
            const nextDisabled = currentPage >= totalPages;
            const activeClass = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-zinc-800 border border-zinc-700 text-zinc-300 hover:bg-zinc-700 text-sm transition js-post-search-page';
            const disabledClass = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-zinc-800/40 border border-zinc-700/40 text-zinc-600 text-sm cursor-default';
            paginationHtml = `
                <div class="flex items-center justify-between mt-4 pt-4 border-t border-zinc-700">
                    <button type="button" class="${prevDisabled ? disabledClass : activeClass}" data-page="${currentPage - 1}" ${prevDisabled ? 'disabled' : ''}>
                        <i class="fa fa-arrow-left text-xs"></i> ${escapeHtml(uiText('search_previous'))}
                    </button>
                    <span class="text-zinc-400 text-sm">${escapeHtml(uiText('search_position', { page: currentPage, total: totalPages }))}</span>
                    <button type="button" class="${nextDisabled ? disabledClass : activeClass}" data-page="${currentPage + 1}" ${nextDisabled ? 'disabled' : ''}>
                        ${escapeHtml(uiText('search_next'))} <i class="fa fa-arrow-right text-xs"></i>
                    </button>
                </div>
            `;
        }

        replaceElementMarkup(results, `<div class="space-y-2">${postItems}</div>${paginationHtml}`);
        if (typeof window.refreshUtcTimestamps === 'function') {
            window.refreshUtcTimestamps(results);
        }

        results.querySelectorAll('.js-post-search-page').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetPage = Number(btn.dataset.page || 1);
                searchPostsPage(query, targetPage);
            });
        });
    } catch {
        showToast(uiText('search_failed'), 'error');
        results.innerHTML = '';
    }
}

async function searchPosts(event) {
    event.preventDefault();
    const input = document.getElementById('post-search-input');
    const help = document.getElementById('post-search-help');
    if (!input) return;

    const query = input.value.trim();
    if (query.length < 2) {
        showToast(uiText('please_enter_at_least_2_characters'), 'error');
        return;
    }

    if (help) {
        help.classList.add('hidden');
    }

    await searchPostsPage(query, 1);
}

async function searchMessagesPage(query, page) {
    const results = document.getElementById('message-search-results');
    if (!results) return;

    results.innerHTML = `<p class="text-zinc-400 text-sm">${escapeHtml(uiText('search_pending'))}</p>`;

    try {
        const res = await fetch(appPath(`/api/messages/search?q=${encodeURIComponent(query)}&page=${page}`));
        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            showToast(uiActionError(data.error, 'search_failed'), 'error');
            results.innerHTML = '';
            return;
        }

        const data = await res.json();
        const messages = data.messages || [];
        const totalPages = Number(data.total_pages || 1);
        const currentPage = Number(data.page || 1);

        if (messages.length === 0) {
            results.innerHTML = `<p class="text-zinc-400 text-sm">${escapeHtml(uiText('search_no_messages'))}</p>`;
            return;
        }

        const messageItems = messages.map(msg => {
            const chatTitle = escapeHtml(String(msg.chat_title || msg.chat_number_formatted || ''));
            const chatType = String(msg.chat_type_normalized || '');
            const chatTypeLabel = uiText(chatType === 'group' ? 'search_group_chat' : 'search_private_chat');
            const chatNumFormatted = escapeHtml(String(msg.chat_number_formatted || ''));
            const msgId = Number(msg.message_id || 0);
            const chatUrl = appPath(`/c/${chatNumFormatted}?msg=${msgId}`);
            const rawContent = stripMessageMentions(String(msg.content || ''));
            const highlightedContent = highlightSearchTerm(rawContent, query);
            const senderUsername = msg.bot_name
                ? uiText('bot_name', { name: msg.bot_name }) : String(msg.sender_username || '');
            const createdAtRaw = String(msg.created_at || '');
            const timestamp = formatCompactMessageTimestamp(createdAtRaw);

            return `
                <a href="${chatUrl}" class="block bg-zinc-800 hover:bg-zinc-700/80 rounded-xl p-4 transition border border-zinc-700 hover:border-zinc-600">
                    <div class="flex items-center gap-2 mb-2 min-w-0">
                        <span class="font-medium text-zinc-100 truncate">${chatTitle}</span>
                        <span class="shrink-0 text-xs text-zinc-500 bg-zinc-900 border border-zinc-700 px-1.5 py-0.5 rounded">${escapeHtml(chatTypeLabel)}</span>
                        <span class="ml-auto shrink-0 text-xs text-zinc-500" data-utc="${escapeHtml(createdAtRaw)}" title="${escapeHtml(createdAtRaw)}">${escapeHtml(timestamp)}</span>
                    </div>
                    <div class="text-sm text-zinc-300 leading-5 overflow-hidden search-preview search-preview-short">${highlightedContent}</div>
                    <div class="mt-2 text-xs text-zinc-400">${escapeHtml(uiText('search_sent_by', { name: senderUsername }))}</div>
                </a>
            `;
        }).join('');

        let paginationHtml = '';
        if (totalPages > 1) {
            const prevDisabled = currentPage <= 1;
            const nextDisabled = currentPage >= totalPages;
            const activeClass = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-zinc-800 border border-zinc-700 text-zinc-300 hover:bg-zinc-700 text-sm transition js-msg-search-page';
            const disabledClass = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-zinc-800/40 border border-zinc-700/40 text-zinc-600 text-sm cursor-default';
            paginationHtml = `
                <div class="flex items-center justify-between mt-4 pt-4 border-t border-zinc-700">
                    <button type="button" class="${prevDisabled ? disabledClass : activeClass}" data-page="${currentPage - 1}" ${prevDisabled ? 'disabled' : ''}>
                        <i class="fa fa-arrow-left text-xs"></i> ${escapeHtml(uiText('search_previous'))}
                    </button>
                    <span class="text-zinc-400 text-sm">${escapeHtml(uiText('search_position', { page: currentPage, total: totalPages }))}</span>
                    <button type="button" class="${nextDisabled ? disabledClass : activeClass}" data-page="${currentPage + 1}" ${nextDisabled ? 'disabled' : ''}>
                        ${escapeHtml(uiText('search_next'))} <i class="fa fa-arrow-right text-xs"></i>
                    </button>
                </div>
            `;
        }

        replaceElementMarkup(results, `<div class="space-y-2">${messageItems}</div>${paginationHtml}`);
        if (typeof window.refreshUtcTimestamps === 'function') {
            window.refreshUtcTimestamps(results);
        }

        results.querySelectorAll('.js-msg-search-page').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetPage = Number(btn.dataset.page || 1);
                searchMessagesPage(query, targetPage);
            });
        });
    } catch {
        showToast(uiText('search_failed'), 'error');
        results.innerHTML = '';
    }
}

async function searchMessages(event) {
    event.preventDefault();
    const input = document.getElementById('message-search-input');
    const help = document.getElementById('message-search-help');
    if (!input) return;

    const query = input.value.trim();
    if (query.length < 2) {
        showToast(uiText('please_enter_at_least_2_characters'), 'error');
        return;
    }

    if (help) {
        help.classList.add('hidden');
    }

    await searchMessagesPage(query, 1);
}
