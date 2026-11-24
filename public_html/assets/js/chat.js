// Extracted from app.js for feature-focused organization.

let messageInteractionHandlersBound = false;
let openReactionPickerMessageId = 0;
const renderedMessageTimelines = new WeakMap();
const outgoingMessagePreviews = new WeakMap();
const submittingMessageForms = new WeakSet();
const expandedSystemEventGroupsByChat = new Map();
let pendingPinReplaceMessageId = 0;
let pinnedBannerLastScrollTop = 0;
let pinnedBannerHiddenByScroll = false;
let messageFormLastScrollTop = 0;
let messageRefreshSequence = 0;
let lastAppliedMessageRefresh = 0;
let chatBannerRevision = 0;
let emojiAnnotations = null;
let emojiAnnotationsRequest = null;

const PINNED_BANNER_MOBILE_BREAKPOINT_QUERY = '(max-width: 767.98px)';
const MESSAGE_FORM_MOBILE_BREAKPOINT_QUERY = '(max-width: 639.98px)';
const PINNED_BANNER_SCROLL_DELTA_PX = 6;
const MESSAGE_FORM_SCROLL_DELTA_PX = 6;

function isPinnedBannerMobileViewport() {
    if (typeof window.matchMedia !== 'function') return false;
    return window.matchMedia(PINNED_BANNER_MOBILE_BREAKPOINT_QUERY).matches;
}

function setPinnedBannerScrollVisibility(isVisible) {
    const banner = document.getElementById('pinned-message-banner');
    if (!banner) return;

    const hasPinnedMessage = Number(banner.dataset.pinnedMessageId || 0) > 0;
    if (!hasPinnedMessage || !isPinnedBannerMobileViewport()) {
        banner.classList.remove('-translate-y-full', 'opacity-0', 'pointer-events-none', 'max-h-0', 'py-0', 'border-b-0', 'overflow-hidden');
        if (!banner.classList.contains('py-3')) {
            banner.classList.add('py-3');
        }
        if (!banner.classList.contains('border-b')) {
            banner.classList.add('border-b');
        }
        if (!hasPinnedMessage) {
            pinnedBannerHiddenByScroll = false;
        }
        return;
    }

    pinnedBannerHiddenByScroll = !isVisible;
    banner.classList.toggle('-translate-y-full', !isVisible);
    banner.classList.toggle('opacity-0', !isVisible);
    banner.classList.toggle('pointer-events-none', !isVisible);
    banner.classList.toggle('max-h-0', !isVisible);
    banner.classList.toggle('py-0', !isVisible);
    banner.classList.toggle('border-b-0', !isVisible);
    banner.classList.toggle('overflow-hidden', !isVisible);

    banner.classList.toggle('py-3', isVisible);
    banner.classList.toggle('border-b', isVisible);
}

function bindPinnedBannerScrollBehavior(messagesBox) {
    if (!messagesBox || messagesBox.dataset.pinnedScrollBound === '1') return;

    messagesBox.dataset.pinnedScrollBound = '1';
    pinnedBannerLastScrollTop = Math.max(0, messagesBox.scrollTop || 0);

    messagesBox.addEventListener('scroll', () => {
        const currentScrollTop = Math.max(0, messagesBox.scrollTop || 0);
        const delta = currentScrollTop - pinnedBannerLastScrollTop;

        if (Math.abs(delta) < PINNED_BANNER_SCROLL_DELTA_PX) {
            return;
        }

        if (delta < 0) {
            setPinnedBannerScrollVisibility(false);
        } else {
            setPinnedBannerScrollVisibility(true);
        }

        pinnedBannerLastScrollTop = currentScrollTop;
    }, { passive: true });

    window.addEventListener('resize', () => {
        setPinnedBannerScrollVisibility(true);
    });
}

function isMessageFormMobileViewport() {
    if (typeof window.matchMedia !== 'function') return false;
    return window.matchMedia(MESSAGE_FORM_MOBILE_BREAKPOINT_QUERY).matches;
}

function setMessageFormScrollVisibility(isVisible) {
    const form = document.getElementById('message-form');
    if (!form) return;

    if (!isMessageFormMobileViewport()) {
        form.style.transform = '';
        form.style.opacity = '';
        form.style.pointerEvents = '';
        form.style.transition = '';
        form.style.maxHeight = '';
        form.style.overflow = '';
        form.style.padding = '';
        form.style.borderTopWidth = '';
        return;
    }

    form.style.transition = 'transform 0.25s ease, opacity 0.25s ease, max-height 0.25s ease, padding 0.25s ease';

    if (isVisible) {
        form.style.transform = '';
        form.style.opacity = '';
        form.style.pointerEvents = '';
        form.style.maxHeight = '';
        form.style.overflow = '';
        form.style.padding = '';
        form.style.borderTopWidth = '';
    } else {
        form.style.transform = 'translateY(100%)';
        form.style.opacity = '0';
        form.style.pointerEvents = 'none';
        form.style.maxHeight = '0';
        form.style.overflow = 'hidden';
        form.style.padding = '0 1.5rem';
        form.style.borderTopWidth = '0';
    }
}

function bindMessageFormScrollBehavior(messagesBox) {
    if (!messagesBox || messagesBox.dataset.formScrollBound === '1') return;

    messagesBox.dataset.formScrollBound = '1';
    messageFormLastScrollTop = Math.max(0, messagesBox.scrollTop || 0);

    messagesBox.addEventListener('scroll', () => {
        if (!isMessageFormMobileViewport()) return;

        const currentScrollTop = Math.max(0, messagesBox.scrollTop || 0);
        const delta = currentScrollTop - messageFormLastScrollTop;
        const isNearBottom = (messagesBox.scrollHeight - messagesBox.clientHeight - currentScrollTop) < 50;

        if (isNearBottom) {
            setMessageFormScrollVisibility(true);
            messageFormLastScrollTop = currentScrollTop;
            return;
        }

        if (Math.abs(delta) < MESSAGE_FORM_SCROLL_DELTA_PX) {
            return;
        }

        if (delta > 0) {
            setMessageFormScrollVisibility(true);
        } else {
            setMessageFormScrollVisibility(false);
        }

        messageFormLastScrollTop = currentScrollTop;
    }, { passive: true });

    window.addEventListener('resize', () => {
        setMessageFormScrollVisibility(true);
    });
}

const MESSAGE_REACTION_LABEL_BY_CODE = new Map(Object.entries(window.CAUSETTE_REACTION_LABELS || {}));
let legacyEmojiFiles;
let emojiSegmenter;

function normalizeEmojiKey(value) {
    return String(value || '').replace(/\.svg$/i, '').trim().toUpperCase();
}

function emojiKeyToChar(key) {
    const tokens = normalizeEmojiKey(key).split('-').filter(Boolean);
    if (tokens.length === 0) return '';

    try {
        return tokens.map((token) => {
            const codePoint = Number.parseInt(token, 16);
            if (!Number.isFinite(codePoint)) {
                throw new Error('Invalid code point');
            }
            return String.fromCodePoint(codePoint);
        }).join('');
    } catch {
        return '';
    }
}

function splitEmojiKeywordList(value) {
    return String(value || '')
        .split(',')
        .map(normalizeEmojiSearchText)
        .filter(Boolean);
}

function normalizeEmojiSearchText(value) {
    return String(value || '').trim().toLowerCase().normalize('NFD')
        .replace(/\p{M}/gu, '').replaceAll('œ', 'oe').replaceAll('æ', 'ae');
}

function tokenizeEmojiText(value) {
    return normalizeEmojiSearchText(value)
        .replace(/[\-_]/g, ' ')
        .split(/[^\p{L}\p{N}]+/u)
        .map((token) => token.trim())
        .filter(Boolean);
}

function loadEmojiAnnotations() {
    const url = window.CAUSETTE_EMOJI_ANNOTATIONS_URL;
    if (!url || emojiAnnotations) return Promise.resolve(false);
    return emojiAnnotationsRequest ??= (async () => {
        try {
            const response = await fetch(url);
            if (!response.ok) return false;
            const { annotations } = await response.json();
            if (!annotations || typeof annotations !== 'object' || Array.isArray(annotations)) return false;
            emojiAnnotations = annotations;
            openMojiCatalog = [];
            return true;
        } catch {
            return false;
        } finally {
            emojiAnnotationsRequest = null;
        }
    })();
}

function normalizeOpenMojiMetadataByKey() {
    if (openMojiMetadataByKey) return openMojiMetadataByKey;

    const source = window.OPENMOJI_METADATA;
    if (!source || typeof source !== 'object') {
        openMojiMetadataByKey = {};
        return openMojiMetadataByKey;
    }

    const normalized = {};
    Object.entries(source).forEach(([key, value]) => {
        normalized[normalizeEmojiKey(key)] = value;
    });
    openMojiMetadataByKey = normalized;
    return openMojiMetadataByKey;
}

function getEmojiKeywordsForKey(key) {
    const normalizedKey = normalizeEmojiKey(key);
    const metadataByKey = normalizeOpenMojiMetadataByKey();
    const metadata = metadataByKey[normalizedKey] || metadataByKey[normalizedKey.replace(/(?:-)?FE0F/gi, '')] || null;

    const keywords = new Set();

    if (metadata) {
        tokenizeEmojiText(metadata.annotation).forEach((token) => keywords.add(token));
        splitEmojiKeywordList(metadata.tags).forEach((token) => keywords.add(token));
        splitEmojiKeywordList(metadata.openmoji_tags).forEach((token) => keywords.add(token));
        tokenizeEmojiText(metadata.group).forEach((token) => keywords.add(token));
        tokenizeEmojiText(metadata.subgroups).forEach((token) => keywords.add(token));
    }

    for (const rule of EMOJI_KEYWORD_RULES) {
        if (rule.pattern.test(normalizedKey)) {
            for (const keyword of rule.keywords) {
                keywords.add(keyword);
            }
        }
    }

    if (normalizedKey.includes('-200D-')) {
        keywords.add('zwj');
        keywords.add('sequence');
    }

    if (normalizedKey.includes('-1F3F')) {
        keywords.add('skin tone');
    }

    return Array.from(keywords).map((keyword) => String(keyword).trim().toLowerCase()).filter(Boolean);
}

function initializeOpenMojiCatalog() {
    if (openMojiCatalog.length > 0) return;

    const metadataByKey = normalizeOpenMojiMetadataByKey();
    const codes = Object.values(window.CAUSETTE_REACTION_GROUPS || {}).flat();

    openMojiByKey.clear();
    openMojiCatalog = codes.flatMap((key) => {
        const url = get3dEmojiUrl(key);
        const character = emojiKeyToChar(key);
        if (!url || !character) return [];
        const metadata = metadataByKey[key] || metadataByKey[key.replace(/(?:-)?FE0F/gi, '')] || {};
        const localized = emojiAnnotations?.[key];
        const originalAnnotation = String(metadata.annotation || '');
        const annotation = (window.CAUSETTE_EMOJI_ANNOTATIONS_URL ? localized?.name : originalAnnotation)
            || MESSAGE_REACTION_LABEL_BY_CODE.get(key) || '';
        const keywords = getEmojiKeywordsForKey(key);
        if (Array.isArray(localized?.keywords)) keywords.push(...localized.keywords.map(normalizeEmojiSearchText));
        const item = {
            key,
            keyLower: key.toLowerCase(),
            character,
            url,
            keywords,
            annotation,
            searchText: [key.toLowerCase(), character, normalizeEmojiSearchText(originalAnnotation),
                normalizeEmojiSearchText(annotation), ...keywords].join(' ')
        };

        openMojiByKey.set(key, item);
        return item;
    });
}

function get3dEmojiUrl(code) {
    const artwork = window.CAUSETTE_EMOJI_ARTWORK?.[code];
    return typeof artwork === 'string' && /^[0-9A-F]{4,6}(?:-[0-9A-F]{4,6})*$/.test(artwork)
        ? appPath(`/assets/img/emoji-3d/${artwork}.webp`) : '';
}

function getLegacyEmojiUrl(code) {
    const file = `${code}.svg`;
    legacyEmojiFiles ??= new Set(window.OPENMOJI_FILES || []);
    return legacyEmojiFiles.has(file) ? appPath(`/emojis/${file}`) : '';
}

function getGraphemeClusters(text) {
    const value = String(text ?? '');

    if (typeof Intl !== 'undefined' && typeof Intl.Segmenter === 'function') {
        emojiSegmenter ??= new Intl.Segmenter(undefined, { granularity: 'grapheme' });
        return Array.from(emojiSegmenter.segment(value), (segment) => segment.segment);
    }

    return Array.from(value);
}

function buildEmojiCandidateKeys(grapheme) {
    if (grapheme.length === 1 && grapheme.charCodeAt(0) < 0x80) return [];
    const codePoints = Array.from(grapheme).map((char) => char.codePointAt(0).toString(16).toUpperCase().padStart(4, '0'));
    if (codePoints.length === 0) return [];

    const exact = codePoints.join('-');
    const withoutVariation = codePoints.filter((token) => token !== 'FE0F');
    const withoutVariationKey = withoutVariation.join('-');

    const candidates = [exact];
    if (withoutVariation.length > 0 && withoutVariationKey !== exact) {
        candidates.push(withoutVariationKey);
    }

    return candidates;
}

function findOpenMojiForGrapheme(grapheme) {
    for (const candidate of buildEmojiCandidateKeys(grapheme)) {
        const url = get3dEmojiUrl(candidate) || getLegacyEmojiUrl(candidate);
        if (url) return { url };
    }

    return null;
}

function appendTextWithEmoji(container, content) {
    if (!content) return;
    if (!/[^\x00-\x7F]|\n/.test(content)) {
        container.append(content);
        return;
    }
    let text = '';
    for (const grapheme of getGraphemeClusters(content)) {
        const emoji = findOpenMojiForGrapheme(grapheme);
        if (grapheme !== '\n' && !emoji) {
            text += grapheme;
            continue;
        }
        if (text) {
            container.append(text);
            text = '';
        }
        if (grapheme === '\n') {
            container.append(document.createElement('br'));
        } else {
            const image = document.createElement('img');
            image.src = emoji.url;
            image.alt = grapheme;
            image.className = 'inline-block w-7 h-7 align-[-0.2em] mx-[1px]';
            image.loading = 'lazy';
            image.decoding = 'async';
            container.append(image);
        }
    }
    if (text) container.append(text);
}

function appendPlainTextWithEmoji(container, source) {
    const linkPattern = /(https?:\/\/[^\s<]+|\/c\/\d{4}-\d{4}-\d{4}-\d{4}\/delete)/gi;
    let cursor = 0;
    for (const match of source.matchAll(linkPattern)) {
        appendTextWithEmoji(container, source.slice(cursor, match.index));
        cursor = match.index + match[0].length;

        const token = match[0];
        const link = document.createElement('a');
        if (/^\/c\//i.test(token)) {
            link.className = 'text-red-400 hover:text-red-300 hover:underline underline-offset-2';
            link.textContent = uiText('delete_chat');
        } else {
            let url;
            try {
                url = new URL(token);
            } catch {
                appendTextWithEmoji(container, token);
                continue;
            }
            if (url.protocol !== 'https:' && url.protocol !== 'http:') {
                appendTextWithEmoji(container, token);
                continue;
            }
            link.className = 'causette-accent hover:text-emerald-300 hover:underline underline-offset-2';
            link.textContent = token;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
        }
        link.setAttribute('href', token);
        container.append(link);
    }
    appendTextWithEmoji(container, source.slice(cursor));
}

function renderPlainTextWithEmoji(content) {
    const container = document.createElement('div');
    appendPlainTextWithEmoji(container, String(content ?? ''));
    return container.innerHTML;
}

function normalizeMentionMap(mentionMap) {
    if (!mentionMap || typeof mentionMap !== 'object' || Array.isArray(mentionMap)) {
        return {};
    }

    const normalized = {};
    Object.entries(mentionMap).forEach(([userNumber, username]) => {
        const safeUserNumber = String(userNumber || '').replace(/\D/g, '').slice(0, 16);
        const safeUsername = bannerText(username).toLowerCase();
        if (!safeUserNumber || !/^[a-z][a-z0-9]{3,31}$/i.test(safeUsername)) {
            return;
        }
        normalized[safeUserNumber] = safeUsername;
    });

    return normalized;
}

function decodeStoredMentionsToPlainText(content) {
    return String(content ?? '').replace(/@\[(\d{16})\|([a-z][a-z0-9]{3,31})\]/gi, '@$2');
}

function createMessageContent(content, mentionMap = null) {
    const container = document.createDocumentFragment();
    const normalizedMentionMap = normalizeMentionMap(mentionMap);
    const source = String(content ?? '');
    const mentionPattern = /@\[(\d{16})\|([a-z][a-z0-9]{3,31})\]/gi;
    let cursor = 0;
    for (const match of source.matchAll(mentionPattern)) {
        appendPlainTextWithEmoji(container, source.slice(cursor, match.index));
        cursor = match.index + match[0].length;

        const userNumber = match[1];
        const username = '@' + (normalizedMentionMap[userNumber] || match[2].toLowerCase());
        const profileUrl = getProfileUrlByUserNumber(userNumber);
        if (profileUrl) {
            const link = document.createElement('a');
            link.setAttribute('href', profileUrl);
            link.className = 'causette-accent hover:text-emerald-300 hover:underline underline-offset-2';
            link.textContent = username;
            container.append(link);
        } else {
            container.append(username);
        }
    }
    appendPlainTextWithEmoji(container, source.slice(cursor));
    return container;
}

function renderMessageContent(content, mentionMap = null) {
    const container = document.createElement('div');
    container.append(createMessageContent(content, mentionMap));
    return container.innerHTML;
}

function normalizeReactionCode(value) {
    const normalized = String(value || '').trim().toUpperCase();
    if (!/^[0-9A-F]{2,6}(?:-[0-9A-F]{2,6})*$/.test(normalized) || normalized.length > 128) return '';
    return MESSAGE_REACTION_LABEL_BY_CODE.has(normalized) || getLegacyEmojiUrl(normalized) ? normalized : '';
}

function getReactionImageUrlByCode(reactionCode) {
    const code = normalizeReactionCode(reactionCode);
    return code ? get3dEmojiUrl(code) || getLegacyEmojiUrl(code) : '';
}

function reactionCodeToChar(reactionCode) {
    const code = normalizeReactionCode(reactionCode);
    if (!code) return '';
    return emojiKeyToChar(code);
}

function renderReactionEmojiMarkup(reactionCode, sizeClass = 'w-4 h-4') {
    const code = normalizeReactionCode(reactionCode);
    if (!code) return '';

    const charValue = reactionCodeToChar(code);
    const imageUrl = getReactionImageUrlByCode(code);
    const fallbackLabel = MESSAGE_REACTION_LABEL_BY_CODE.get(code) || uiText('reaction_label');

    if (imageUrl) {
        return `<img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(charValue || fallbackLabel)}" class="${escapeHtml(sizeClass)}" loading="lazy" decoding="async">`;
    }

    return `<span>${escapeHtml(charValue || fallbackLabel)}</span>`;
}

function decodeStoredMentionsWithMap(content, mentionMap = null) {
    const normalizedMentionMap = normalizeMentionMap(mentionMap);
    return String(content ?? '').replace(/@\[(\d{16})\|([a-z][a-z0-9]{3,31})\]/gi, (_full, userNumber, fallbackUsername) => {
        const normalizedUserNumber = String(userNumber || '').replace(/\D/g, '').slice(0, 16);
        const resolvedUsername = String(normalizedMentionMap[normalizedUserNumber] || fallbackUsername || '').toLowerCase();
        return `@${resolvedUsername}`;
    });
}

function getQuotedPreviewText(content, mentionMap = null) {
    const decoded = decodeStoredMentionsWithMap(content, mentionMap);
    return decoded.replace(/\s+/g, ' ').trim();
}

function renderQuotedMessageBlock(msg) {
    const quotedContentRaw = String(msg?.quoted_content || '');
    if (!quotedContentRaw.trim()) {
        return '';
    }

    const quotedMessageId = Number(msg?.quoted_message_id || 0);
    const hasQuotedMessageTarget = Number.isFinite(quotedMessageId) && quotedMessageId > 0;
    const quotedMentionMap = normalizeMentionMap(msg?.quote_mention_map || {});
    const quotedUserNumber = String(msg?.quoted_user_number || '').replace(/\D/g, '').slice(0, 16);
    const quotedBotName = bannerText(msg?.quoted_bot_name);
    const quotedUsername = quotedBotName ? uiText('bot_name', { name: quotedBotName })
        : bannerText(msg?.quoted_username).trim() || chatLabel('unknown_user');
    const quotedProfileUrl = quotedUserNumber && !quotedBotName ? getProfileUrlByUserNumber(quotedUserNumber) : '';

    const blockAttributes = hasQuotedMessageTarget
        ? ` class="js-quoted-message-link mt-1.5 mb-2 p-2 rounded-lg border border-zinc-700 bg-zinc-900/70 text-sm hover:bg-zinc-800/70 transition-colors cursor-pointer" data-quoted-message-id="${quotedMessageId}" title="${escapeHtml(uiText('goto_quoted_message'))}"`
        : ' class="mt-1.5 mb-2 p-2 rounded-lg border border-zinc-700 bg-zinc-900/70 text-sm"';

    return `
        <div${blockAttributes}>
            <div class="text-zinc-400 text-xs mb-1">${quotedProfileUrl ? `<a href="${escapeHtml(quotedProfileUrl)}" class="hover:underline underline-offset-2">${escapeHtml(quotedUsername)}</a>` : escapeHtml(quotedUsername)}</div>
            <div class="text-zinc-300 leading-5 line-clamp-3">${renderMessageContent(quotedContentRaw, quotedMentionMap)}</div>
        </div>
    `;
}

function renderReactionBadgesMarkup(messageId, reactions) {
    if (!Array.isArray(reactions) || reactions.length === 0) {
        return '';
    }

    const badges = reactions
        .map((reaction) => {
            const reactionCode = normalizeReactionCode(reaction?.reaction_code || '');
            if (!reactionCode) return '';

            const reactionCount = Math.max(0, Number(reaction?.count || 0));
            if (reactionCount <= 0) return '';

            const users = Array.isArray(reaction?.users) ? reaction.users.map((user) => String(user || '').trim()).filter(Boolean) : [];
            const reactionLabel = MESSAGE_REACTION_LABEL_BY_CODE.get(reactionCode) || uiText('reaction_label');
            const tooltip = `${reactionLabel}: ${users.length ? users.join(', ') : uiText('reaction_no_users')}`;
            const reactedByCurrentUser = reaction?.reacted_by_current_user === true || Number(reaction?.reacted_by_current_user || 0) === 1;
            const badgeClass = reactedByCurrentUser
                ? 'bg-zinc-700 border-zinc-500 text-zinc-100'
                : 'bg-zinc-800 border-zinc-700 text-zinc-300 hover:bg-zinc-700';

            return `
                <button type="button" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[12px] js-reaction-badge ${badgeClass}" data-reaction-message-id="${Number(messageId)}" data-reaction-code="${escapeHtml(reactionCode)}" title="${escapeHtml(tooltip)}">
                    ${renderReactionEmojiMarkup(reactionCode, 'w-6 h-6')}
                    <span>${escapeHtml(String(reactionCount))}</span>
                </button>
            `;
        })
        .filter(Boolean)
        .join('');

    if (!badges) return '';
    return `<div class="flex flex-wrap items-center gap-1.5">${badges}</div>`;
}

function renderReactionPickerMarkup(messageId) {
    return `<div class="hidden js-reaction-picker mb-2" data-reaction-picker-for="${Number(messageId)}"></div>`;
}

function showReactionPalette(picker, targetId, kind) {
    if (!picker.firstElementChild) {
        const palette = document.createElement('div');
        palette.className = 'reaction-palette';

        const filters = document.createElement('div');
        filters.className = 'reaction-palette__filters';
        filters.setAttribute('role', 'group');
        filters.setAttribute('aria-label', uiText('reaction_categories'));

        const grid = document.createElement('div');
        grid.className = 'reaction-palette__grid';

        const search = document.createElement('input');
        search.type = 'search';
        search.maxLength = 80;
        search.className = 'w-full bg-zinc-800 border border-zinc-700 rounded px-2 py-1 text-sm';
        search.placeholder = uiText('reaction_search');
        search.setAttribute('aria-label', search.placeholder);
        let selectedCategory = 'all';
        const draw = () => {
            initializeOpenMojiCatalog();
            const query = search.value.trim();
            const category = window.CAUSETTE_REACTION_GROUPS?.[selectedCategory] || [];
            const choices = query || selectedCategory === 'all'
                ? findEmojiTypeaheadMatches(query).map(item => item.key)
                : category.filter(code => openMojiByKey.has(code));
            grid.replaceChildren();
            if (choices.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'col-span-full py-4 text-center text-xs text-zinc-400';
                empty.textContent = uiText('no_emoji_found');
                grid.appendChild(empty);
            }
            for (const code of choices) {
                const label = openMojiByKey.get(code)?.annotation || MESSAGE_REACTION_LABEL_BY_CODE.get(code)
                    || uiText('emoji_catalog_fallback', { code });
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'reaction-palette__option';
                if (kind === 'post') {
                    button.classList.add('js-profile-post-reaction-option');
                    button.dataset.postId = String(targetId);
                } else {
                    button.classList.add('js-reaction-option');
                    button.dataset.reactionMessageId = String(targetId);
                }
                button.dataset.reactionCode = code;
                button.title = label;
                button.setAttribute('aria-label', label);

                const imageUrl = getReactionImageUrlByCode(code);
                if (imageUrl) {
                    const image = document.createElement('img');
                    image.src = imageUrl;
                    image.alt = '';
                    image.loading = 'lazy';
                    image.decoding = 'async';
                    button.appendChild(image);
                } else {
                    button.textContent = reactionCodeToChar(code);
                }
                grid.appendChild(button);
            }
            grid.scrollTop = 0;
        };
        const categories = ['all', ...Object.keys(window.CAUSETTE_REACTION_GROUPS || {})];
        for (const category of categories) {
            const filter = document.createElement('button');
            filter.type = 'button';
            filter.className = 'reaction-palette__filter';
            filter.textContent = uiText('reaction_group_' + category);
            filter.setAttribute('aria-pressed', String(category === 'all'));
            filter.addEventListener('click', () => {
                selectedCategory = category;
                search.value = '';
                for (const other of filters.children) {
                    other.setAttribute('aria-pressed', String(filter === other));
                }
                draw();
            });
            filters.appendChild(filter);
        }
        search.addEventListener('input', draw);
        search.addEventListener('keydown', event => {
            if (event.key === 'Enter') event.preventDefault();
        });
        draw();
        void loadEmojiAnnotations().then(loaded => { if (loaded && grid.isConnected) draw(); });
        palette.append(search, filters, grid);
        picker.appendChild(palette);
    }
    picker.classList.remove('hidden');
}

function closeAllReactionPickers(resetState = true) {
    const pickers = document.querySelectorAll('.js-reaction-picker');
    pickers.forEach((picker) => picker.classList.add('hidden'));
    if (resetState) {
        openReactionPickerMessageId = 0;
    }
}

function toggleReactionPicker(messageId) {
    const safeMessageId = Number(messageId || 0);
    if (!Number.isFinite(safeMessageId) || safeMessageId <= 0) return;

    const picker = document.querySelector(`.js-reaction-picker[data-reaction-picker-for="${safeMessageId}"]`);
    if (!picker) return;

    const willOpen = picker.classList.contains('hidden');
    closeAllReactionPickers(false);
    if (willOpen) {
        showReactionPalette(picker, safeMessageId, 'message');
        openReactionPickerMessageId = safeMessageId;
    } else {
        openReactionPickerMessageId = 0;
    }
}

function setQuoteDraft(quoteData) {
    const hiddenInput = document.getElementById('quoted-message-id');
    const preview = document.getElementById('quote-preview');
    const previewUsername = document.getElementById('quote-preview-username');
    const previewContent = document.getElementById('quote-preview-content');
    if (!hiddenInput || !preview || !previewUsername || !previewContent) return;

    const messageId = Number(quoteData?.messageId || 0);
    if (!Number.isFinite(messageId) || messageId <= 0) {
        hiddenInput.value = '';
        preview.classList.add('hidden');
        previewUsername.textContent = '';
        previewContent.replaceChildren();
        return;
    }

    const unknownUser = chatLabel('unknown_user');
    const username = String(quoteData?.username || unknownUser).trim() || unknownUser;
    const content = String(quoteData?.content || '');
    const mentionMap = normalizeMentionMap(quoteData?.mentionMap || {});

    hiddenInput.value = String(messageId);
    previewUsername.textContent = username;
    previewContent.replaceChildren(createMessageContent(getQuotedPreviewText(content, mentionMap), mentionMap));
    preview.classList.remove('hidden');
}

function clearQuoteDraft() {
    setQuoteDraft(null);
}

async function reactToMessage(messageId, reactionCode) {
    const safeMessageId = Number(messageId || 0);
    const safeReactionCode = normalizeReactionCode(reactionCode);
    if (!Number.isFinite(safeMessageId) || safeMessageId <= 0 || !safeReactionCode) {
        return;
    }

    const result = await postForm('/api/messages/react', {
        csrf_token: getCsrfToken(),
        message_id: String(safeMessageId),
        reaction_code: safeReactionCode
    });

    if (!result.success) {
        showToast(uiActionError(result.error, 'unable_to_react_to_message'), 'error');
        return;
    }

    closeAllReactionPickers();
    await pollMessages({ scrollMode: 'preserve' });
}

function chatLabel(key, parameters = {}) {
    const label = window.CAUSETTE_CHAT_I18N?.labels?.[key];
    if (typeof label !== 'string') return '';
    return label.replace(/\{([a-z_]+)\}/g, (token, name) => Object.hasOwn(parameters, name) ? String(parameters[name]) : token);
}

function chatActionError(result, fallbackKey) {
    const language = window.CAUSETTE_CHAT_I18N?.language || 'en';
    if (language === 'en' && typeof result.error === 'string' && result.error) {
        return result.error;
    }
    return chatLabel(fallbackKey);
}

function bannerText(value) {
    return typeof value === 'string' ? value : '';
}

function bannerInteger(value) {
    const number = typeof value === 'string' && /^\d+$/.test(value) ? Number(value) : value;
    return Number.isSafeInteger(number) && number >= 0 ? number : 0;
}

function bannerFlag(value) {
    return value === true || value === 1 || value === '1';
}

function normalizePinnedMessage(pinnedMessage) {
    if (!pinnedMessage || typeof pinnedMessage !== 'object') {
        return null;
    }

    const id = bannerInteger(pinnedMessage.id);
    if (!id) {
        return null;
    }

    return {
        id,
        chat_id: bannerInteger(pinnedMessage.chat_id),
        user_id: bannerInteger(pinnedMessage.user_id),
        username: bannerText(pinnedMessage.username).trim() || chatLabel('unknown_user'),
        bot_name: bannerText(pinnedMessage.bot_name),
        user_number: bannerText(pinnedMessage.user_number),
        created_at: bannerText(pinnedMessage.created_at),
        content: bannerText(pinnedMessage.content),
        mention_map: normalizeMentionMap(pinnedMessage.mention_map || {})
    };
}

function renderPinnedMessageBanner(pinnedMessage) {
    const banner = document.getElementById('pinned-message-banner');
    const usernameNode = document.getElementById('pinned-message-username');
    const timeNode = document.getElementById('pinned-message-time');
    const contentNode = document.getElementById('pinned-message-content');
    const goToButton = document.getElementById('pinned-message-goto');
    const unpinButton = document.getElementById('pinned-message-unpin');
    if (!banner || !usernameNode || !timeNode || !contentNode) return;

    const safePinnedMessage = normalizePinnedMessage(pinnedMessage);
    const canPinMessages = canCurrentUserPinMessages();
    if (!safePinnedMessage) {
        banner.classList.add('hidden');
        banner.classList.remove('-translate-y-full', 'opacity-0', 'pointer-events-none');
        banner.dataset.pinnedMessageId = '0';
        pinnedBannerHiddenByScroll = false;
        usernameNode.textContent = '';
        delete timeNode.dataset.utc;
        timeNode.removeAttribute('title');
        timeNode.textContent = '';
        contentNode.replaceChildren();
        contentNode.dataset.rawContent = '';
        contentNode.dataset.mentionMap = '{}';
        if (unpinButton) {
            unpinButton.disabled = true;
            unpinButton.classList.toggle('hidden', !canPinMessages);
            unpinButton.classList.add('opacity-50', 'cursor-not-allowed');
        }
        if (goToButton) {
            goToButton.disabled = true;
            goToButton.classList.add('opacity-50', 'cursor-not-allowed');
        }
        return;
    }

    banner.classList.remove('hidden');
    banner.dataset.pinnedMessageId = String(safePinnedMessage.id);
    setPinnedBannerScrollVisibility(!pinnedBannerHiddenByScroll);
    usernameNode.textContent = safePinnedMessage.bot_name
        ? uiText('bot_name', { name: safePinnedMessage.bot_name }) : safePinnedMessage.username;
    timeNode.dataset.utc = safePinnedMessage.created_at;
    timeNode.title = safePinnedMessage.created_at;
    timeNode.textContent = formatCompactMessageTimestamp(safePinnedMessage.created_at);
    contentNode.dataset.rawContent = safePinnedMessage.content;
    contentNode.dataset.mentionMap = JSON.stringify(safePinnedMessage.mention_map || {});
    contentNode.replaceChildren(createMessageContent(safePinnedMessage.content, safePinnedMessage.mention_map));
    if (typeof window.refreshUtcTimestamps === 'function') {
        window.refreshUtcTimestamps(timeNode);
    }
    if (unpinButton) {
        unpinButton.classList.toggle('hidden', !canPinMessages);
        unpinButton.disabled = !canPinMessages;
        unpinButton.classList.toggle('opacity-50', !canPinMessages);
        unpinButton.classList.toggle('cursor-not-allowed', !canPinMessages);
    }
    if (goToButton) {
        goToButton.disabled = false;
        goToButton.classList.remove('opacity-50', 'cursor-not-allowed');
    }
}

function normalizeActivePoll(activePoll) {
    if (!activePoll || typeof activePoll !== 'object') {
        return null;
    }

    const id = bannerInteger(activePoll.id);
    const status = bannerText(activePoll.status).toLowerCase();
    if (!id || (status !== 'active' && status !== 'expired')) {
        return null;
    }

    const { minOptions, maxOptions } = window.CAUSETTE_POLL_LIMITS;
    if (!Array.isArray(activePoll.options) || activePoll.options.length < minOptions || activePoll.options.length > maxOptions) {
        return null;
    }
    const options = activePoll.options.map((option) => ({
        id: bannerInteger(option?.id),
        option_text: bannerText(option?.option_text),
        sort_order: bannerInteger(option?.sort_order),
        vote_count: bannerInteger(option?.vote_count),
        selected_by_current_user: bannerFlag(option?.selected_by_current_user),
        voters: Array.isArray(option?.voters) ? option.voters.map(bannerText).filter(Boolean) : []
    }));
    if (options.some((option) => !option.id || !option.option_text)
        || new Set(options.map((option) => option.id)).size !== options.length) {
        return null;
    }

    return {
        id,
        chat_id: bannerInteger(activePoll.chat_id),
        creator_user_id: bannerInteger(activePoll.creator_user_id),
        creator_username: bannerText(activePoll.creator_username).trim() || chatLabel('unknown_user'),
        question: bannerText(activePoll.question),
        status,
        expires_at: bannerText(activePoll.expires_at),
        expired_at: bannerText(activePoll.expired_at),
        created_at: bannerText(activePoll.created_at),
        total_votes: bannerInteger(activePoll.total_votes),
        user_has_voted: bannerFlag(activePoll.user_has_voted),
        user_voted_option_id: bannerInteger(activePoll.user_voted_option_id),
        can_expire: bannerFlag(activePoll.can_expire),
        options
    };
}

function canCurrentUserCreatePoll() {
    if (!currentChat) return false;
    return normalizeChatType(currentChat.type) === 'group' && currentChat.is_group_member === true;
}

function canCurrentUserExpirePoll(activePoll) {
    const poll = normalizeActivePoll(activePoll);
    if (!poll || poll.status !== 'active' || !currentChat) return false;
    if (poll.can_expire) return true;

    const myUserId = Number(currentUserId || 0);
    if (myUserId > 0 && Number(poll.creator_user_id || 0) === myUserId) {
        return true;
    }

    if (currentChat.is_admin === true) {
        return true;
    }

    const ownerUserId = Number(currentChat.owner_user_id || 0);
    if (ownerUserId > 0 && ownerUserId === myUserId) {
        return true;
    }

    return currentChat.is_group_moderator === true;
}

function renderActivePollBanner(activePoll) {
    const banner = document.getElementById('active-poll-banner');
    const questionNode = document.getElementById('active-poll-question');
    const metaNode = document.getElementById('active-poll-meta');
    const optionsNode = document.getElementById('active-poll-options');
    const expireButton = document.getElementById('active-poll-expire');
    if (!banner || !questionNode || !metaNode || !optionsNode) return;

    const poll = normalizeActivePoll(activePoll);
    if (!poll) {
        banner.classList.add('hidden');
        banner.dataset.activePollId = '0';
        questionNode.textContent = '';
        metaNode.textContent = '';
        optionsNode.replaceChildren();
        if (expireButton) {
            delete expireButton.dataset.pollId;
            expireButton.classList.add('hidden');
            expireButton.disabled = true;
            expireButton.classList.add('opacity-50', 'cursor-not-allowed');
        }
        return;
    }

    const isActive = poll.status === 'active';
    const canVote = isActive && !poll.user_has_voted && canCurrentUserCreatePoll();
    const canExpire = canCurrentUserExpirePoll(poll);

    banner.classList.remove('hidden');
    banner.dataset.activePollId = String(poll.id);

    questionNode.textContent = poll.question;

    const language = window.CAUSETTE_CHAT_I18N?.language || 'en';
    const votePlural = new Intl.PluralRules(language);
    const voteNumber = new Intl.NumberFormat(language);
    const voteLabel = (count) => {
        const parameters = { count: voteNumber.format(count) };
        return chatLabel('vote_count_' + votePlural.select(count), parameters)
            || chatLabel('vote_count_other', parameters);
    };
    const expiryTime = isActive ? poll.expires_at : poll.expired_at || poll.expires_at;
    const expiryText = chatLabel(isActive ? 'poll_expires_at' : 'poll_expired_at', {
        time: formatCompactMessageTimestamp(expiryTime, { relative: false })
    });
    metaNode.textContent = chatLabel('poll_meta', {
        expiry: expiryText,
        votes: voteLabel(poll.total_votes),
        creator: poll.creator_username
    });

    const optionButtons = poll.options.map((option) => {
        const button = document.createElement('button');
        const isSelected = option.id === poll.user_voted_option_id || option.selected_by_current_user;
        const votersTooltip = option.voters.length > 0 ? option.voters.join(', ') : chatLabel('no_votes');
        const totalVotes = poll.total_votes;
        const votePercent = totalVotes > 0 ? Math.min(100, (option.vote_count / totalVotes) * 100) : 0;

        button.type = 'button';
        button.className = `w-full text-left border rounded-xl px-3 py-2 text-sm transition ${isSelected ? 'border-emerald-500/70 bg-emerald-500/10 text-zinc-100' : 'border-zinc-700 bg-zinc-800/80 text-zinc-200'} ${canVote ? 'hover:bg-zinc-700/80' : 'opacity-90'}`;
        button.setAttribute('title', votersTooltip);
        button.setAttribute('aria-label', chatLabel('option_vote_label', {
            option: option.option_text,
            votes: voteLabel(option.vote_count)
        }));
        button.dataset.pollVoteOptionId = String(option.id);
        button.dataset.pollId = String(poll.id);
        button.disabled = !canVote;

        const left = document.createElement('span');
        left.textContent = option.option_text;
        const right = document.createElement('span');
        right.className = 'text-xs text-zinc-400';
        right.textContent = voteNumber.format(option.vote_count);

        const row = document.createElement('span');
        row.className = 'flex items-center justify-between gap-3';
        row.appendChild(left);
        row.appendChild(right);
        button.appendChild(row);

        if (totalVotes > 0) {
            const track = document.createElement('span');
            track.className = 'mt-2 block w-full h-1.5 rounded-full bg-zinc-700/70 overflow-hidden';

            const fill = document.createElement('span');
            fill.className = isSelected
                ? 'block h-full rounded-full bg-emerald-500/90'
                : 'block h-full rounded-full bg-zinc-400/90';
            fill.style.width = `${votePercent}%`;
            fill.setAttribute('aria-hidden', 'true');

            track.appendChild(fill);
            button.appendChild(track);
        }

        return button;
    });

    optionsNode.replaceChildren(...optionButtons);

    if (expireButton) {
        expireButton.classList.toggle('hidden', !canExpire);
        expireButton.disabled = !canExpire;
        expireButton.classList.toggle('opacity-50', !canExpire);
        expireButton.classList.toggle('cursor-not-allowed', !canExpire);
        expireButton.dataset.pollId = String(poll.id);
    }
}

async function createCurrentGroupPoll(form) {
    if (!currentChat || !canCurrentUserCreatePoll()) {
        return false;
    }

    const chat = currentChat;
    const values = new FormData(form);
    const question = values.get('question').trim();
    const options = values.getAll('option').map(value => value.trim());
    const duration = Number(values.get('duration'));
    const unit = values.get('duration_unit');
    const { minOptions, maxOptions, maxHours, maxDays } = window.CAUSETTE_POLL_LIMITS;
    const maximum = unit === 'd' ? maxDays : maxHours;

    if (!question) {
        showToast(chatLabel('poll_question_empty'), 'error');
        return false;
    }
    if (question.length > form.elements.question.maxLength) {
        showToast(chatLabel('poll_question_length'), 'error');
        return false;
    }
    if (options.length < minOptions || options.length > maxOptions) {
        showToast(chatLabel('poll_options_count', { min: minOptions, max: maxOptions }), 'error');
        return false;
    }
    if (options.some(value => value === '')) {
        showToast(chatLabel('poll_option_empty'), 'error');
        return false;
    }
    if (options.some(value => value.length > form.querySelector('[name="option"]').maxLength)) {
        showToast(chatLabel('poll_option_length'), 'error');
        return false;
    }

    const normalizedOptionSet = new Set(options.map((value) => value.toLowerCase()));
    if (normalizedOptionSet.size !== options.length) {
        showToast(chatLabel('poll_options_unique'), 'error');
        return false;
    }
    if ((unit !== 'h' && unit !== 'd') || !Number.isInteger(duration) || duration < 1 || duration > maximum) {
        showToast(chatLabel('poll_duration_invalid', { hours: maxHours, days: maxDays }), 'error');
        return false;
    }

    const result = await postForm('/api/chats/group/poll/create', {
        csrf_token: getCsrfToken(),
        chat_id: String(chat.id),
        question,
        expiry: `${duration}${unit}`,
        options: JSON.stringify(options)
    });
    if (currentChat !== chat) return false;

    if (!result.success) {
        showToast(chatActionError(result, 'poll_create_error'), 'error');
        return false;
    }

    const poll = normalizeActivePoll(result.active_poll);
    chatBannerRevision++;
    chat.active_poll = poll;
    renderActivePollBanner(poll);
    return true;
}

async function voteInActivePoll(pollId, optionId) {
    const chat = currentChat;
    if (!chat) return;

    const result = await postForm('/api/chats/group/poll/vote', {
        csrf_token: getCsrfToken(),
        chat_id: String(chat.id),
        poll_id: String(pollId),
        option_id: String(optionId)
    });
    if (currentChat !== chat) return;

    if (!result.success) {
        showToast(chatActionError(result, 'poll_vote_error'), 'error');
        return;
    }

    const poll = normalizeActivePoll(result.active_poll);
    chatBannerRevision++;
    chat.active_poll = poll;
    renderActivePollBanner(poll);
}

async function expireActivePoll(pollId) {
    const chat = currentChat;
    if (!chat) return;

    const result = await postForm('/api/chats/group/poll/expire', {
        csrf_token: getCsrfToken(),
        chat_id: String(chat.id),
        poll_id: String(pollId)
    });
    if (currentChat !== chat) return;

    if (!result.success) {
        showToast(chatActionError(result, 'poll_expire_error'), 'error');
        return;
    }

    const poll = normalizeActivePoll(result.active_poll);
    chatBannerRevision++;
    chat.active_poll = poll;
    renderActivePollBanner(poll);
}

function bindActivePollBannerActions() {
    const banner = document.getElementById('active-poll-banner');
    const optionsNode = document.getElementById('active-poll-options');
    const expireButton = document.getElementById('active-poll-expire');
    if (!banner || !optionsNode) return;

    if (optionsNode.dataset.bound !== '1') {
        optionsNode.dataset.bound = '1';
        optionsNode.addEventListener('click', (event) => {
            const button = event.target.closest('[data-poll-vote-option-id]');
            if (!(button instanceof HTMLElement)) return;
            if (button.disabled) return;

            const pollId = Number(button.dataset.pollId || banner.dataset.activePollId || 0);
            const optionId = Number(button.dataset.pollVoteOptionId || 0);
            if (!Number.isFinite(pollId) || pollId <= 0 || !Number.isFinite(optionId) || optionId <= 0) {
                return;
            }

            voteInActivePoll(pollId, optionId).catch(() => {
                showToast(chatLabel('poll_vote_error'), 'error');
            });
        });
    }

    if (expireButton && expireButton.dataset.bound !== '1') {
        expireButton.dataset.bound = '1';
        expireButton.addEventListener('click', (event) => {
            event.preventDefault();
            if (expireButton.disabled) return;

            const pollId = Number(expireButton.dataset.pollId || banner.dataset.activePollId || 0);
            if (!Number.isFinite(pollId) || pollId <= 0) {
                return;
            }

            expireButton.disabled = true;
            expireActivePoll(pollId)
                .catch(() => {
                    showToast(chatLabel('poll_expire_error'), 'error');
                })
                .finally(() => {
                    if (!expireButton.isConnected || currentChat?.active_poll?.id !== pollId) return;
                    const canExpire = canCurrentUserExpirePoll(currentChat.active_poll);
                    expireButton.disabled = !canExpire;
                    expireButton.classList.toggle('opacity-50', !canExpire);
                    expireButton.classList.toggle('cursor-not-allowed', !canExpire);
                });
        });
    }
}

function bindCreatePollModal() {
    const modal = document.getElementById('create-poll-modal');
    const form = document.getElementById('create-poll-form');
    const cancel = document.getElementById('create-poll-cancel');
    const submit = document.getElementById('create-poll-submit');
    if (!modal || !form || !cancel || !submit) return;
    if (modal.dataset.bound === '1') return;
    modal.dataset.bound = '1';

    const options = form.querySelector('#create-poll-options');
    const template = form.querySelector('#create-poll-option-template');
    const add = form.querySelector('#create-poll-add-option');
    const { minOptions, maxOptions, maxHours, maxDays } = window.CAUSETTE_POLL_LIMITS;
    const duration = form.elements.duration;
    const unit = form.elements.duration_unit;
    let previousUnit = unit.value;

    const refreshOptions = () => {
        add.disabled = options.childElementCount >= maxOptions;
        Array.from(options.children).forEach((row, index) => {
            const number = index + 1;
            const input = row.querySelector('input');
            const label = row.querySelector('label');
            const remove = row.querySelector('[data-remove-poll-option]');
            input.id = `create-poll-option-${number}`;
            label.htmlFor = input.id;
            label.textContent = chatLabel('poll_option_label', { number });
            remove.setAttribute('aria-label', chatLabel('remove_numbered_option', { number }));
            remove.disabled = options.childElementCount <= minOptions;
        });
    };

    const refreshDurationLimit = () => {
        duration.max = String(unit.value === 'd' ? maxDays : maxHours);
    };

    add.addEventListener('click', () => {
        if (options.childElementCount >= maxOptions) return;
        options.append(template.content.cloneNode(true));
        refreshOptions();
        options.lastElementChild.querySelector('input').focus();
    });
    options.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-poll-option]');
        if (!remove || options.childElementCount <= minOptions) return;
        const row = remove.closest('[data-poll-option-row]');
        const next = row.nextElementSibling || row.previousElementSibling;
        row.remove();
        refreshOptions();
        next.querySelector('input').focus();
    });
    unit.addEventListener('change', () => {
        refreshDurationLimit();
        const amount = duration.valueAsNumber;
        if (unit.value !== previousUnit && Number.isInteger(amount) && amount > 0) {
            const converted = unit.value === 'd' ? Math.ceil(amount / 24) : amount * 24;
            duration.value = String(Math.min(converted, Number(duration.max)));
        }
        previousUnit = unit.value;
    });

    const setOpenState = (isOpen) => {
        modal.classList.toggle('hidden', !isOpen);
        modal.setAttribute('aria-hidden', String(!isOpen));
        if (isOpen) {
            form.reset();
            previousUnit = unit.value;
            options.replaceChildren();
            for (let index = 0; index < minOptions; index++) {
                options.append(template.content.cloneNode(true));
            }
            refreshOptions();
            refreshDurationLimit();
            form.elements.question.focus();
            return;
        }

        submit.disabled = false;
        submit.textContent = chatLabel('create');
        document.getElementById('chat-header-menu-toggle')?.focus();
    };

    const closeModal = () => setOpenState(false);

    cancel.addEventListener('click', (event) => {
        event.preventDefault();
        closeModal();
    });

    modal.addEventListener('click', (event) => {
        if (event.target !== modal) return;
        closeModal();
    });

    modal.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (modal.classList.contains('hidden')) return;
        closeModal();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;

        submit.disabled = true;
        submit.textContent = chatLabel('creating');

        try {
            const created = await createCurrentGroupPoll(form);
            if (created && !modal.classList.contains('hidden')) {
                closeModal();
            }
        } finally {
            submit.disabled = false;
            submit.textContent = chatLabel('create');
        }
    });

    window.openCreatePollModal = () => {
        if (!canCurrentUserCreatePoll()) {
            showToast(chatLabel('poll_group_only'), 'error');
            return;
        }
        setOpenState(true);
    };
}

async function unpinCurrentChatMessage() {
    const chat = currentChat;
    if (!chat) {
        return;
    }

    const result = await postForm('/api/messages/unpin', {
        csrf_token: getCsrfToken(),
        chat_id: String(chat.id)
    });
    if (currentChat !== chat) return;

    if (!result.success) {
        showToast(chatActionError(result, 'unpin_error'), 'error');
        return;
    }

    chatBannerRevision++;
    chat.pinned_message = null;
    renderPinnedMessageBanner(null);
}

function bindPinnedMessageBannerActions() {
    const goToButton = document.getElementById('pinned-message-goto');
    const unpinButton = document.getElementById('pinned-message-unpin');
    if (!goToButton && !unpinButton) {
        return;
    }

    if (goToButton && goToButton.dataset.bound !== '1') {
        goToButton.dataset.bound = '1';
        goToButton.addEventListener('click', (event) => {
            event.preventDefault();
            if (goToButton.disabled) {
                return;
            }

            const box = document.getElementById('messages');
            const pinnedMessageId = Number(currentChat?.pinned_message?.id || 0);
            if (!box || !Number.isFinite(pinnedMessageId) || pinnedMessageId <= 0) {
                showToast(chatLabel('pin_not_available'), 'error');
                return;
            }

            const focused = focusMessageById(pinnedMessageId);
            if (!focused) {
                showToast(chatLabel('pin_not_loaded'), 'error');
                return;
            }
        });
    }

    if (!unpinButton || unpinButton.dataset.bound === '1') {
        return;
    }
    unpinButton.dataset.bound = '1';

    unpinButton.addEventListener('click', (event) => {
        event.preventDefault();
        if (unpinButton.disabled) {
            return;
        }

        unpinButton.disabled = true;
        unpinCurrentChatMessage()
            .catch(() => {
                showToast(chatLabel('unpin_error'), 'error');
            })
            .finally(() => {
                if (!unpinButton.isConnected) return;
                const hasPinnedMessage = Number(currentChat?.pinned_message?.id || 0) > 0;
                unpinButton.disabled = !hasPinnedMessage;
                unpinButton.classList.toggle('opacity-50', !hasPinnedMessage);
                unpinButton.classList.toggle('cursor-not-allowed', !hasPinnedMessage);
                if (goToButton) {
                    goToButton.disabled = !hasPinnedMessage;
                    goToButton.classList.toggle('opacity-50', !hasPinnedMessage);
                    goToButton.classList.toggle('cursor-not-allowed', !hasPinnedMessage);
                }
            });
    });
}

function bindPinReplaceModal() {
    const modal = document.getElementById('pin-replace-modal');
    const form = document.getElementById('pin-replace-form');
    const cancel = document.getElementById('pin-replace-cancel');
    const submit = document.getElementById('pin-replace-submit');
    if (!modal || !form || !cancel || !submit) return;

    if (modal.dataset.bound === '1') {
        return;
    }
    modal.dataset.bound = '1';

    const setOpenState = (isOpen) => {
        modal.classList.toggle('hidden', !isOpen);
        if (!isOpen) {
            pendingPinReplaceMessageId = 0;
            submit.disabled = false;
            submit.textContent = chatLabel('replace');
        }
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

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;

        const messageId = Number(pendingPinReplaceMessageId || 0);
        if (!Number.isFinite(messageId) || messageId <= 0) {
            closeModal();
            return;
        }

        submit.disabled = true;
        submit.textContent = chatLabel('replacing');

        try {
            await pinMessageInCurrentChat(messageId, true);
        } finally {
            closeModal();
        }
    });

    window.openPinReplaceModal = (messageId) => {
        pendingPinReplaceMessageId = Number(messageId || 0);
        if (!Number.isFinite(pendingPinReplaceMessageId) || pendingPinReplaceMessageId <= 0) {
            pendingPinReplaceMessageId = 0;
            return;
        }

        setOpenState(true);
    };
}

async function pinMessageInCurrentChat(messageId, forceReplace = false) {
    const chat = currentChat;
    const safeMessageId = Number(messageId || 0);
    if (!chat || !Number.isFinite(safeMessageId) || safeMessageId <= 0) {
        return;
    }

    const result = await postForm('/api/messages/pin', {
        csrf_token: getCsrfToken(),
        chat_id: String(chat.id),
        message_id: String(safeMessageId),
        force_replace: forceReplace ? '1' : '0'
    });
    if (currentChat !== chat) return;

    if (result.success) {
        const pinnedMessage = normalizePinnedMessage(result.pinned_message);
        chatBannerRevision++;
        chat.pinned_message = pinnedMessage;
        renderPinnedMessageBanner(pinnedMessage);
        return;
    }

    if (result.requires_confirm) {
        if (typeof window.openPinReplaceModal === 'function') {
            window.openPinReplaceModal(safeMessageId);
        }
        return;
    }

    showToast(chatActionError(result, 'pin_error'), 'error');
}

function bindMessageQuotesAndReactions() {
    if (messageInteractionHandlersBound) return;

    const messagesBox = document.getElementById('messages');
    if (!messagesBox) return;

    messageInteractionHandlersBound = true;
    bindPinReplaceModal();
    bindPinnedMessageBannerActions();
    bindActivePollBannerActions();
    bindCreatePollModal();

    const initialPinnedMessage = normalizePinnedMessage(window.INITIAL_PINNED_MESSAGE || null);
    const initialActivePoll = normalizeActivePoll(window.INITIAL_ACTIVE_POLL || null);
    if (currentChat) {
        currentChat.pinned_message = initialPinnedMessage;
        currentChat.active_poll = initialActivePoll;
    }
    renderPinnedMessageBanner(initialPinnedMessage);
    renderActivePollBanner(initialActivePoll);
    bindPinnedBannerScrollBehavior(messagesBox);
    bindMessageFormScrollBehavior(messagesBox);

    messagesBox.addEventListener('click', (event) => {
        const systemEventsToggleButton = event.target.closest('.js-system-events-toggle');
        if (systemEventsToggleButton) {
            event.preventDefault();

            const groupKey = String(systemEventsToggleButton.getAttribute('data-system-event-group-key') || '');
            if (!groupKey) {
                return;
            }

            const hiddenCount = Math.max(0, Number(systemEventsToggleButton.getAttribute('data-system-event-hidden-count') || 0));
            const currentlyExpanded = systemEventsToggleButton.getAttribute('aria-expanded') === 'true';
            const nextExpanded = !currentlyExpanded;

            setSystemEventGroupExpanded(Number(currentChat?.id || 0), groupKey, nextExpanded);

            const groupedRows = messagesBox.querySelectorAll(`[data-system-event-group-key="${groupKey}"][data-system-event-hidden="1"]`);
            groupedRows.forEach((row) => {
                if (!(row instanceof HTMLElement)) return;
                row.classList.toggle('hidden', !nextExpanded);
            });

            systemEventsToggleButton.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
            const toggleLabel = systemEventsToggleButton.querySelector('.js-system-events-toggle-label');
            if (toggleLabel) {
                toggleLabel.textContent = systemEventToggleLabel(hiddenCount, nextExpanded);
            }

            return;
        }

        const quotedMessageLink = event.target.closest('.js-quoted-message-link');
        if (quotedMessageLink) {
            if (event.target.closest('a')) {
                return;
            }
            event.preventDefault();
            const quotedMessageId = Number(quotedMessageLink.getAttribute('data-quoted-message-id') || 0);
            const focused = focusMessageById(quotedMessageId);
            if (!focused) {
                showToast(uiText('quoted_message_is_not_in_the_loaded_history'), 'error');
            }
            closeAllReactionPickers();
            return;
        }

        const quoteButton = event.target.closest('.js-quote-link');
        if (quoteButton) {
            event.preventDefault();
            const messageId = Number(quoteButton.getAttribute('data-quote-message-id') || 0);
            const username = String(quoteButton.getAttribute('data-quote-username') || '');
            const content = String(quoteButton.getAttribute('data-quote-content') || '');

            let mentionMap = {};
            try {
                mentionMap = JSON.parse(quoteButton.getAttribute('data-quote-mention-map') || '{}');
            } catch {
                mentionMap = {};
            }

            setQuoteDraft({ messageId, username, content, mentionMap });

            const input = document.getElementById('message-input');
            if (input) {
                input.focus();
            }
            closeAllReactionPickers();
            return;
        }

        const reactLink = event.target.closest('.js-react-link');
        if (reactLink) {
            event.preventDefault();
            const messageId = Number(reactLink.getAttribute('data-react-message-id') || 0);
            toggleReactionPicker(messageId);
            return;
        }

        const pinLink = event.target.closest('.js-pin-link');
        if (pinLink) {
            event.preventDefault();
            const messageId = Number(pinLink.getAttribute('data-pin-message-id') || 0);
            const activePinnedMessageId = Number(currentChat?.pinned_message?.id || 0);

            if (activePinnedMessageId > 0 && activePinnedMessageId !== messageId) {
                if (typeof window.openPinReplaceModal === 'function') {
                    window.openPinReplaceModal(messageId);
                }
                return;
            }

            if (activePinnedMessageId === messageId) {
                showToast(chatLabel('pin_already'), 'success');
                return;
            }

            pinMessageInCurrentChat(messageId).catch(() => {
                showToast(chatLabel('pin_error'), 'error');
            });
            return;
        }

        const reactionOption = event.target.closest('.js-reaction-option');
        if (reactionOption) {
            event.preventDefault();
            const messageId = Number(reactionOption.getAttribute('data-reaction-message-id') || 0);
            const reactionCode = String(reactionOption.getAttribute('data-reaction-code') || '');
            reactToMessage(messageId, reactionCode).catch(() => {
                showToast(uiText('unable_to_react_to_message'), 'error');
            });
            return;
        }

        const reactionBadge = event.target.closest('.js-reaction-badge');
        if (reactionBadge) {
            event.preventDefault();
            const messageId = Number(reactionBadge.getAttribute('data-reaction-message-id') || 0);
            const reactionCode = String(reactionBadge.getAttribute('data-reaction-code') || '');
            reactToMessage(messageId, reactionCode).catch(() => {
                showToast(uiText('unable_to_remove_reaction'), 'error');
            });
            return;
        }

        if (!event.target.closest('.js-reaction-picker')) {
            closeAllReactionPickers();
        }
    });

    const cancelQuoteButton = document.getElementById('quote-preview-cancel');
    if (cancelQuoteButton) {
        cancelQuoteButton.addEventListener('click', (event) => {
            event.preventDefault();
            clearQuoteDraft();
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAllReactionPickers();
        }
    });
}

function applyEmojiRenderingToExistingMessages() {
    const messageNodes = document.querySelectorAll('#messages .js-message-content');
    if (!messageNodes.length) return;

    messageNodes.forEach((messageNode) => {
        const rawContent = messageNode.dataset.rawContent ?? messageNode.textContent ?? '';
        let mentionMap = {};
        try {
            mentionMap = JSON.parse(messageNode.dataset.mentionMap || '{}');
        } catch {
            mentionMap = {};
        }
        messageNode.replaceChildren(createMessageContent(rawContent, mentionMap));
    });
}

function getEmojiSearchScore(item, normalizedQuery, queryTokens, rawQuery) {
    let score = 0;
    const annotation = normalizeEmojiSearchText(item.annotation);
    const keywords = Array.isArray(item.keywords) ? item.keywords : [];

    if (item.character && item.character === rawQuery) {
        score += 2000;
    }

    if (annotation === normalizedQuery) {
        score += 1000;
    } else if (annotation.startsWith(normalizedQuery)) {
        score += 700;
    } else if (annotation.includes(normalizedQuery)) {
        score += 450;
    }

    if (item.keyLower === normalizedQuery) {
        score += 900;
    } else if (item.keyLower.startsWith(normalizedQuery)) {
        score += 550;
    } else if (item.keyLower.includes(normalizedQuery)) {
        score += 250;
    }

    for (const keyword of keywords) {
        if (keyword === normalizedQuery) {
            score += 850;
            continue;
        }
        if (keyword.startsWith(normalizedQuery)) {
            score += 500;
            continue;
        }
        if (keyword.includes(normalizedQuery)) {
            score += 180;
        }
    }

    for (const token of queryTokens) {
        if (!token) continue;

        if (annotation === token) {
            score += 120;
        } else if (annotation.startsWith(token)) {
            score += 80;
        } else if (annotation.includes(token)) {
            score += 40;
        }

        if (item.keyLower === token) {
            score += 100;
        } else if (item.keyLower.startsWith(token)) {
            score += 70;
        } else if (item.keyLower.includes(token)) {
            score += 30;
        }

        for (const keyword of keywords) {
            if (keyword === token) {
                score += 90;
            } else if (keyword.startsWith(token)) {
                score += 60;
            } else if (keyword.includes(token)) {
                score += 25;
            }
        }
    }

    if (queryTokens.length > 1) {
        const joined = queryTokens.join(' ');
        if (annotation.includes(joined)) {
            score += 150;
        }
        if (keywords.some((keyword) => keyword.includes(joined))) {
            score += 130;
        }
    }

    return score;
}

function findEmojiTypeaheadMatches(query) {
    initializeOpenMojiCatalog();
    const normalizedQuery = normalizeEmojiSearchText(query);
    if (!normalizedQuery) return openMojiCatalog;
    const queryTokens = normalizedQuery.split(/\s+/).filter(Boolean);
    const rawQuery = String(query || '').trim();

    const rankedMatches = [];

    for (const item of openMojiCatalog) {
        const matchesAllTokens = queryTokens.every((token) => item.searchText.includes(token));
        const matchesChar = item.character.includes(rawQuery);
        if (!matchesAllTokens && !matchesChar) continue;

        const score = getEmojiSearchScore(item, normalizedQuery, queryTokens, rawQuery);
        rankedMatches.push({ item, score });

    }

    rankedMatches.sort((a, b) => {
        if (b.score !== a.score) return b.score - a.score;
        return a.item.key.localeCompare(b.item.key);
    });

    return rankedMatches.map((entry) => entry.item);
}

function insertTextAtCursor(input, text) {
    if (!input) return;

    const start = Number(input.selectionStart ?? input.value.length);
    const end = Number(input.selectionEnd ?? input.value.length);

    if (typeof input.setRangeText === 'function') {
        input.setRangeText(text, start, end, 'end');
    } else {
        input.value = `${input.value.slice(0, start)}${text}${input.value.slice(end)}`;
        input.selectionStart = input.selectionEnd = start + text.length;
    }

    input.focus();
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

function bindEmojiDrawer() {
    const toggle = document.getElementById('emoji-toggle');
    const drawer = document.getElementById('emoji-drawer');
    const search = document.getElementById('emoji-search');
    const grid = document.getElementById('emoji-grid');
    const input = document.getElementById('message-input');
    const attachmentsDrawer = document.getElementById('attachments-drawer');
    const attachmentsToggle = document.getElementById('attachments-toggle');

    if (!toggle || !drawer || !search || !grid || !input) return;

    const previewImg = document.getElementById('emoji-preview-img');
    const previewLabel = document.getElementById('emoji-preview-label');

    const renderGrid = (items) => {
        const safeItems = items.filter((item) => item.character);
        grid.innerHTML = safeItems.map((item) => {
            const label = escapeHtml(item.annotation || uiText('emoji_catalog_fallback', { code: item.key }));
            return `
                <button type="button" class="w-12 h-12 rounded-lg bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 flex items-center justify-center" data-emoji="${escapeHtml(item.character)}" data-url="${escapeHtml(item.url)}" data-annotation="${label}" title="${label}" aria-label="${label}">
                    <img src="${escapeHtml(item.url)}" alt="" class="w-9 h-9" loading="lazy" decoding="async">
                </button>
            `;
        }).join('') || `<div class="col-span-full text-xs text-zinc-400 py-4 text-center">${escapeHtml(uiText('no_emoji_found'))}</div>`;
    };

    const closeBtn = document.getElementById('emoji-drawer-close');

    const setOpenState = (isOpen) => {
        drawer.classList.toggle('hidden', !isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if (isOpen) {
            renderGrid(findEmojiTypeaheadMatches(search.value));
            search.focus();
            void loadEmojiAnnotations().then((loaded) => {
                if (loaded && grid.isConnected && !drawer.classList.contains('hidden')) {
                    renderGrid(findEmojiTypeaheadMatches(search.value));
                }
            });
        }
    };

    toggle.addEventListener('click', (event) => {
        event.preventDefault();
        const willOpen = drawer.classList.contains('hidden');
        setOpenState(willOpen);
        if (willOpen && attachmentsDrawer && attachmentsToggle) {
            attachmentsDrawer.classList.add('hidden');
            attachmentsToggle.setAttribute('aria-expanded', 'false');
        }
        if (!willOpen) {
            search.value = '';
        }
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', (event) => {
            event.preventDefault();
            setOpenState(false);
            search.value = '';
        });
    }

    search.addEventListener('input', () => {
        renderGrid(findEmojiTypeaheadMatches(search.value));
    });

    grid.addEventListener('click', (event) => {
        const button = event.target.closest('[data-emoji]');
        if (!button) return;

        const emoji = button.getAttribute('data-emoji') || '';
        if (!emoji) return;

        insertTextAtCursor(input, emoji);

        if (isMessageFormMobileViewport()) {
            setOpenState(false);
            search.value = '';
        }
    });

    grid.addEventListener('mouseover', (event) => {
        const button = event.target.closest('[data-emoji]');
        if (!button) return;
        const url = button.getAttribute('data-url') || '';
        const annotation = button.getAttribute('data-annotation') || '';
        if (previewImg && url) {
            previewImg.src = url;
            previewImg.alt = annotation;
            previewImg.classList.remove('hidden');
        }
        if (previewLabel) {
            previewLabel.textContent = annotation;
            previewLabel.classList.remove('hidden');
        }
    });

    grid.addEventListener('mouseleave', () => {
        if (previewImg) {
            previewImg.classList.add('hidden');
            previewImg.src = '';
        }
        if (previewLabel) {
            previewLabel.classList.add('hidden');
            previewLabel.textContent = '';
        }
    });

    document.addEventListener('click', (event) => {
        if (drawer.classList.contains('hidden')) return;
        if (drawer.contains(event.target) || toggle.contains(event.target)) return;
        setOpenState(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (drawer.classList.contains('hidden')) return;
        setOpenState(false);
    });
}

function renderPendingAttachmentList() {
    const empty = document.getElementById('attachments-empty');
    const list = document.getElementById('attachments-list');
    if (!empty || !list) return;

    const safeAttachments = pendingAttachments
        .map(normalizeAttachment)
        .filter(Boolean);

    if (safeAttachments.length === 0) {
        empty.classList.remove('hidden');
        list.classList.add('hidden');
        list.innerHTML = '';
        return;
    }

    empty.classList.add('hidden');
    list.classList.remove('hidden');
    list.innerHTML = safeAttachments.map((attachment) => {
        const category = getAttachmentCategory(attachment.file_extension);
        const preview = category === 'image'
            ? `<button type="button" class="js-lightbox-trigger block w-full" data-image-url="${escapeHtml(attachment.url)}" data-image-title="${escapeHtml(attachment.original_name)}">
                   <img src="${escapeHtml(attachment.url)}" alt="${escapeHtml(attachment.original_name)}" class="w-full h-24 object-cover rounded-lg border border-zinc-700" loading="lazy" decoding="async">
               </button>`
            : `<div class="w-full h-24 rounded-lg border border-zinc-700 bg-zinc-800 flex flex-col items-center justify-center gap-1.5">
                   <i class="fa-solid fa-file text-2xl text-zinc-400"></i>
                   <span class="text-xs font-semibold text-zinc-300 uppercase">.${escapeHtml(attachment.file_extension)}</span>
               </div>`;

        const currentExpiry = Number(attachment.expiry_seconds || 0);
        const expiryChoices = window.CAUSETTE_ATTACHMENT_EXPIRY_OPTIONS || [];
        const expiryOptions = [0, ...expiryChoices].map(seconds =>
            `<option value="${seconds}" ${currentExpiry === seconds ? 'selected' : ''}>${escapeHtml(formatAttachmentExpiryLabel(seconds))}</option>`
        ).join('');
        const legacyOption = currentExpiry > 0 && !expiryChoices.includes(currentExpiry)
            ? `<option selected disabled>${escapeHtml(formatAttachmentExpiryLabel(currentExpiry))}</option>` : '';
        return `
            <div class="bg-zinc-800/70 border border-zinc-700 rounded-xl p-2">
                ${preview}
                <div class="mt-2 text-xs text-zinc-400 truncate" title="${escapeHtml(attachment.original_name)}">${escapeHtml(attachment.original_name)}</div>
                <div class="mt-1.5 flex items-center justify-between gap-2 ${expiryChoices.length ? '' : 'hidden'}">
                    <label class="text-[11px] text-zinc-500" for="pending-attachment-expiry-${attachment.id}">${escapeHtml(uiText('attachment_expiry'))}</label>
                    <select id="pending-attachment-expiry-${attachment.id}" class="bg-zinc-900 border border-zinc-700 rounded px-2 py-1 text-[11px] text-zinc-200" data-attachment-expiry="${attachment.id}">
                        ${expiryOptions}${legacyOption}
                    </select>
                </div>
                <div class="mt-1 text-xs text-zinc-500 flex items-center justify-between gap-2">
                    <span>${escapeHtml(formatFileSize(attachment.file_size))} · ${escapeHtml(formatAttachmentExpiryLabel(attachment.expiry_seconds || 0))}</span>
                    <button type="button" class="text-red-300 hover:text-red-200" data-attachment-delete="${attachment.id}" title="${escapeHtml(uiText('delete_pending_attachment'))}" aria-label="${escapeHtml(uiText('delete_pending_attachment'))}">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
    }).join('');
}

function displayAttachmentUploadError(message) {
    const notice = document.getElementById('attachment-upload-error');
    if (notice) {
        notice.textContent = message;
        notice.classList.toggle('hidden', !message);
    }
}

function isCurrentChatView(chat, control) {
    return chat === currentChat && chat != null && control.isConnected
        && control.closest('#chat-view')?.dataset.chatId === String(chat.id);
}

async function uploadAttachmentFile(file, chat, sourceInput) {
    if (!isCurrentChatView(chat, sourceInput)) return;
    if (normalizeChatType(chat.type) === 'personal' && chat.can_send_messages === false) {
        displayAttachmentUploadError(getPersonalChatMessageRestrictionToast(chat.message_restriction_reason));
        return;
    }

    const formData = new FormData();
    formData.append('csrf_token', getCsrfToken());
    formData.append('chat_id', String(chat.id));
    formData.append('attachment', file);
    formData.append('expiry_seconds', '0');

    const response = await fetch(appPath('/api/attachments/upload'), {
        method: 'POST',
        body: formData
    });

    const text = await response.text();
    if (!isCurrentChatView(chat, sourceInput)) return;
    let payload = { success: response.ok };
    try {
        payload = JSON.parse(text);
    } catch {
        payload = { success: response.ok, error: response.status === 413 ? 'attachment_too_large' : 'attachment_upload_failed' };
    }

    if (!payload.success || !payload.attachment) {
        const error = String(payload.error || 'attachment_upload_failed');
        const label = uiText(error);
        displayAttachmentUploadError(label === error ? uiText('attachment_upload_failed') : label);
        return;
    }

    const normalized = normalizeAttachment(payload.attachment);
    if (!normalized) {
        displayAttachmentUploadError(uiText('attachment_upload_failed'));
        return;
    }

    pendingAttachments.push(normalized);
    renderPendingAttachmentList();
}

async function deletePendingAttachment(attachmentId) {
    const id = Number(attachmentId || 0);
    if (!Number.isFinite(id) || id <= 0) return;

    const result = await postForm('/api/attachments/delete', {
        csrf_token: getCsrfToken(),
        attachment_id: String(id),
        pending_only: '1'
    });

    if (!result.success && result.error !== 'attachment_unavailable') {
        if (result.error === 'attachment_upload_busy') {
            displayAttachmentUploadError(uiText('attachment_upload_busy'));
            return;
        }
        showToast(uiActionError(result.error, 'unable_to_delete_attachment'), 'error');
        return;
    }

    pendingAttachments = pendingAttachments.filter((attachment) => Number(attachment.id) !== id);
    renderPendingAttachmentList();
}

async function updatePendingAttachmentExpiry(attachmentId, expirySeconds) {
    const id = Number(attachmentId || 0);
    const seconds = Number(expirySeconds || 0);
    if (!Number.isFinite(id) || id <= 0) return;
    if (![0, ...(window.CAUSETTE_ATTACHMENT_EXPIRY_OPTIONS || [])].includes(seconds)) return;

    const result = await postForm('/api/attachments/expiry', {
        csrf_token: getCsrfToken(),
        attachment_id: String(id),
        expiry_seconds: String(seconds)
    });

    if (!result.success) {
        showToast(uiActionError(result.error, 'unable_to_update_attachment_expiry'), 'error');
        renderPendingAttachmentList();
        return;
    }

    pendingAttachments = pendingAttachments.map((attachment) => {
        if (Number(attachment.id) !== id) return attachment;
        return { ...attachment, expiry_seconds: seconds };
    });
    renderPendingAttachmentList();
}

function bindAttachmentsDrawer() {
    const toggle = document.getElementById('attachments-toggle');
    const drawer = document.getElementById('attachments-drawer');
    const input = document.getElementById('attachments-file-input');
    const selectButton = document.getElementById('attachments-select-button');
    const list = document.getElementById('attachments-list');
    const emojiDrawer = document.getElementById('emoji-drawer');
    const emojiToggle = document.getElementById('emoji-toggle');

    if (!toggle || !drawer || !input || !selectButton || !list) return;

    const setOpenState = (isOpen) => {
        drawer.classList.toggle('hidden', !isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    };

    renderPendingAttachmentList();

    toggle.addEventListener('click', (event) => {
        event.preventDefault();
        const willOpen = drawer.classList.contains('hidden');
        setOpenState(willOpen);
        if (willOpen && emojiDrawer && emojiToggle) {
            emojiDrawer.classList.add('hidden');
            emojiToggle.setAttribute('aria-expanded', 'false');
        }
    });

    selectButton.addEventListener('click', (event) => {
        event.preventDefault();
        input.click();
    });

    input.addEventListener('change', async () => {
        const chat = currentChat;
        const files = Array.from(input.files || []);
        if (files.length === 0) return;
        input.value = '';
        if (!isCurrentChatView(chat, input)) {
            displayAttachmentUploadError(uiText('chat_context_changed'));
            return;
        }
        displayAttachmentUploadError('');

        for (const file of files) {
            if (!isCurrentChatView(chat, input)) break;
            await uploadAttachmentFile(file, chat, input);
        }
    });

    list.addEventListener('click', (event) => {
        const deleteButton = event.target.closest('[data-attachment-delete]');
        if (!deleteButton) return;
        event.preventDefault();
        deletePendingAttachment(deleteButton.getAttribute('data-attachment-delete')).catch(() => {
            showToast(uiText('unable_to_delete_attachment'), 'error');
        });
    });

    list.addEventListener('change', (event) => {
        const select = event.target.closest('[data-attachment-expiry]');
        if (!select) return;
        const attachmentId = Number(select.getAttribute('data-attachment-expiry') || 0);
        const expirySeconds = Number(select.value || 0);
        updatePendingAttachmentExpiry(attachmentId, expirySeconds).catch(() => {
            showToast(uiText('unable_to_update_attachment_expiry'), 'error');
        });
    });

    document.addEventListener('click', (event) => {
        if (drawer.classList.contains('hidden')) return;
        if (drawer.contains(event.target) || toggle.contains(event.target)) return;
        setOpenState(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (drawer.classList.contains('hidden')) return;
        setOpenState(false);
    });
}

function openAttachmentLightbox(url, title = uiText('attachment_label')) {
    const overlay = document.getElementById('attachment-lightbox');
    const image = document.getElementById('attachment-lightbox-image');
    if (!overlay || !image || !url) return;

    image.src = url;
    image.alt = title;
    overlay.classList.remove('hidden');
}

function bindAttachmentLightbox() {
    const overlay = document.getElementById('attachment-lightbox');
    const close = document.getElementById('attachment-lightbox-close');
    if (!overlay || !close) return;

    const closeOverlay = () => {
        overlay.classList.add('hidden');
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('.js-lightbox-trigger');
        if (!trigger) return;

        const imageUrl = String(trigger.getAttribute('data-image-url') || '');
        const imageTitle = String(trigger.getAttribute('data-image-title') || uiText('attachment_label'));
        if (!imageUrl) return;

        event.preventDefault();
        openAttachmentLightbox(imageUrl, imageTitle);
    });

    close.addEventListener('click', closeOverlay);
    overlay.addEventListener('click', (event) => {
        const image = document.getElementById('attachment-lightbox-image');
        if (event.target === image) return;
        closeOverlay();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !overlay.classList.contains('hidden')) {
            closeOverlay();
        }
    });
}


const SIDEBAR_CUSTOM_MAX_ITEMS = 8;
const SIDEBAR_MODE_LOCALSTORAGE_KEY = 'prologue.sidebar.chatMode';
const SIDEBAR_CUSTOM_DEFAULT_LABEL = 'Custom';
const SIDEBAR_CUSTOM_MAX_LABEL_LENGTH = 10;

let sidebarChatsCache = [];
let sidebarRenderSignature = '';
let sidebarCustomChatNumbers = [];
let sidebarCustomLabel = SIDEBAR_CUSTOM_DEFAULT_LABEL;
let sidebarCustomConfigLoaded = false;
let sidebarMode = 'all';
let sidebarModeInitialized = false;
let sidebarIsDraggingCustom = false;
let sidebarCustomContextChatNumber = '';

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

function normalizeSidebarMode(value) {
    const mode = String(value || '').trim().toLowerCase();
    if (mode === 'pm' || mode === 'group' || mode === 'custom' || mode === 'unread' || mode === 'all') {
        return mode;
    }
    return 'all';
}

function getStoredSidebarMode() {
    try {
        return normalizeSidebarMode(window.localStorage?.getItem(SIDEBAR_MODE_LOCALSTORAGE_KEY));
    } catch {
        return 'all';
    }
}

function setStoredSidebarMode(mode) {
    try {
        window.localStorage?.setItem(SIDEBAR_MODE_LOCALSTORAGE_KEY, normalizeSidebarMode(mode));
    } catch {
    }
}

function getSidebarElements() {
    return {
        modeToggle: document.getElementById('chat-sidebar-mode-toggle'),
        list: document.getElementById('chat-sidebar-list'),
        customControls: document.getElementById('chat-sidebar-custom-controls'),
        customCount: document.getElementById('chat-sidebar-custom-count'),
        customHelp: document.getElementById('chat-sidebar-custom-help'),
        customSearch: document.getElementById('chat-sidebar-custom-search'),
        customTypeahead: document.getElementById('chat-sidebar-custom-typeahead'),
        customMenu: document.getElementById('chat-sidebar-custom-menu')
    };
}

function normalizeSidebarCustomLabel(value) {
    const normalized = String(value ?? '').replace(/\s+/g, ' ').trim();
    if (!normalized) return SIDEBAR_CUSTOM_DEFAULT_LABEL;
    return normalized.slice(0, SIDEBAR_CUSTOM_MAX_LABEL_LENGTH);
}

function getSidebarCustomLabel() {
    return normalizeSidebarCustomLabel(sidebarCustomLabel);
}

function getSidebarCustomDisplayLabel() {
    const label = getSidebarCustomLabel();
    return label === SIDEBAR_CUSTOM_DEFAULT_LABEL ? uiText('sidebar_custom') : label;
}

function applySidebarCustomLabel() {
    const label = getSidebarCustomDisplayLabel();

    const modeLabel = document.getElementById('chat-sidebar-custom-mode-label');
    if (modeLabel) {
        modeLabel.textContent = label;
    }

    const { customHelp } = getSidebarElements();
    if (customHelp) {
        customHelp.textContent = uiText('sidebar_add_category_help', { category: label });
    }
}

function updateSidebarCustomCount() {
    const { customCount } = getSidebarElements();
    if (!customCount) return;
    const label = getSidebarCustomDisplayLabel();
    customCount.textContent = `${label} (${sidebarCustomChatNumbers.length}/${SIDEBAR_CUSTOM_MAX_ITEMS})`;
}

function getSidebarUnreadFlags() {
    const hasUnreadIn = (items) => items.some((chat) => Number(chat?.unread_count || 0) > 0);
    const customChatNumbers = new Set(sidebarCustomChatNumbers);

    return {
        pm: hasUnreadIn(sidebarChatsCache.filter((chat) => normalizeChatType(chat?.type) === 'personal')),
        group: hasUnreadIn(sidebarChatsCache.filter((chat) => normalizeChatType(chat?.type) === 'group')),
        custom: hasUnreadIn(sidebarChatsCache.filter((chat) => customChatNumbers.has(String(chat?.chat_number || ''))))
    };
}

function getChatSidebarTitle(chat) {
    const type = normalizeChatType(chat?.type);
    if (type === 'group') {
        return chat?.chat_title || formatNumber(chat?.chat_number || '');
    }
    return chat?.chat_title || uiText('sidebar_chat_number', { number: formatNumber(chat?.chat_number || '') });
}

function hideSidebarCustomTypeahead() {
    const { customTypeahead } = getSidebarElements();
    if (!customTypeahead) return;
    customTypeahead.replaceChildren();
    customTypeahead.classList.add('hidden');
}

function getChatByNumber(chatNumber) {
    const value = String(chatNumber || '');
    return sidebarChatsCache.find((chat) => String(chat.chat_number || '') === value) || null;
}

function sanitizeCustomChatNumbers(chatNumbers, chats) {
    const validChatNumbers = new Set((Array.isArray(chats) ? chats : []).map((chat) => String(chat.chat_number || '')));
    const unique = [];
    const seen = new Set();

    (Array.isArray(chatNumbers) ? chatNumbers : []).forEach((value) => {
        const number = String(value || '').trim();
        if (!number || seen.has(number)) return;
        if (!validChatNumbers.has(number)) return;
        seen.add(number);
        unique.push(number);
    });

    return unique.slice(0, SIDEBAR_CUSTOM_MAX_ITEMS);
}

async function fetchSidebarCustomConfig() {
    if (sidebarCustomConfigLoaded) return;

    try {
        const response = await fetch(appPath('/api/chats/sidebar-custom'));
        if (!response.ok) {
            throw new Error('Unable to load custom sidebar config');
        }
        const payload = await response.json();
        const incoming = Array.isArray(payload?.chat_numbers) ? payload.chat_numbers : [];
        sidebarCustomChatNumbers = incoming.map((value) => String(value || '').trim()).filter(Boolean).slice(0, SIDEBAR_CUSTOM_MAX_ITEMS);
    } catch {
        sidebarCustomChatNumbers = [];
    }

    try {
        const response = await fetch(appPath('/api/chats/sidebar-custom-label'));
        if (!response.ok) {
            throw new Error('Unable to load custom sidebar label');
        }
        const payload = await response.json();
        sidebarCustomLabel = normalizeSidebarCustomLabel(payload?.custom_label);
    } catch {
        sidebarCustomLabel = SIDEBAR_CUSTOM_DEFAULT_LABEL;
    }

    sidebarCustomConfigLoaded = true;
    applySidebarCustomLabel();
}

async function persistSidebarCustomConfig() {
    const payload = JSON.stringify(sidebarCustomChatNumbers.slice(0, SIDEBAR_CUSTOM_MAX_ITEMS));
    const result = await postForm('/api/chats/sidebar-custom', {
        csrf_token: getCsrfToken(),
        chat_numbers: payload
    });
    if (!result || result.success !== true) {
        throw new Error(result?.error || 'Unable to save custom sidebar config');
    }
}

async function persistSidebarCustomLabel(nextLabel) {
    const result = await postForm('/api/chats/sidebar-custom-label', {
        csrf_token: getCsrfToken(),
        label: String(nextLabel ?? '')
    });
    if (!result || result.success !== true) {
        throw new Error(result?.error || 'Unable to save custom sidebar label');
    }
    sidebarCustomLabel = normalizeSidebarCustomLabel(result?.custom_label);
    applySidebarCustomLabel();
}

async function syncSidebarCustomChatNumbers(chats) {
    const sanitized = sanitizeCustomChatNumbers(sidebarCustomChatNumbers, chats);
    const changed = sanitized.length !== sidebarCustomChatNumbers.length
        || sanitized.some((value, index) => value !== sidebarCustomChatNumbers[index]);

    if (!changed) return;
    sidebarCustomChatNumbers = sanitized;

    if (!sidebarCustomConfigLoaded) return;

    try {
        await persistSidebarCustomConfig();
    } catch {
    }
}

function updateSidebarLinkCurrent(item, pathname) {
    const current = pathname === item.getAttribute('href');
    item.classList.toggle('bg-zinc-800/80', current);
    if (current) {
        item.setAttribute('aria-current', 'page');
    } else {
        item.removeAttribute('aria-current');
    }
}

function updateSidebarCurrentPage(pathname) {
    const { list } = getSidebarElements();
    list?.querySelectorAll('a[data-sidebar-chat-number]').forEach((item) => updateSidebarLinkCurrent(item, pathname));
}

function buildSidebarChatNode(chat, options = {}) {
    const { customMode = false, showTypeBadge = true } = options;
    const type = normalizeChatType(chat.type);
    const unreadCount = Math.max(0, Number(chat.unread_count || 0));
    const activeCallParticipantCount = Math.max(0, Number(chat.active_call_participant_count || 0));
    const hasGroupCallParticipants = type === 'group' && activeCallParticipantCount > 0;
    const hasPersonalStatus = type === 'personal' && Boolean(chat.effective_status_label);
    const showFavoriteStar = type === 'personal' && (Number(chat.is_favorite) === 1 || chat.is_favorite === true);
    const sidebarTitle = getChatSidebarTitle(chat);
    const rawMessage = decodeStoredMentionsToPlainText(chat.last_message || '');
    const secondaryLine = (() => {
        if (!rawMessage) return uiText('no_messages_yet');
        const botName = bannerText(chat.last_message_bot_name);
        if (botName) return `${uiText('bot_name', { name: botName })}: ${rawMessage}`;
        const senderId = Number(chat.last_message_user_id || 0);
        const prefix = senderId && senderId === Number(currentUserId || 0)
            ? uiText('you')
            : (chat.last_message_sender_username || '');
        return prefix ? `${prefix}: ${rawMessage}` : rawMessage;
    })();

    const item = document.createElement('a');
    item.href = appPath(`/c/${formatNumber(chat.chat_number)}`);
    item.className = 'block py-2 px-3 rounded-xl hover:bg-zinc-800 mb-1';
    item.dataset.sidebarChatNumber = String(chat.chat_number || '');
    item.dataset.sidebarChatType = type;

    if (hasGroupCallParticipants) {
        item.classList.add('border-b-2', 'border-b-emerald-400');
    }

    updateSidebarLinkCurrent(item, window.location.pathname);

    if (customMode) {
        item.draggable = true;
        item.classList.add('cursor-move');
        item.dataset.sidebarCustomItem = '1';
    }

    const primaryLine = document.createElement('div');
    primaryLine.className = 'font-medium flex items-center gap-2 min-w-0';

    const title = document.createElement('span');
    title.className = 'truncate';
    title.textContent = String(sidebarTitle);
    primaryLine.appendChild(title);

    if (showTypeBadge) {
        const typeBadge = document.createElement('span');
        typeBadge.className = type === 'group'
            ? 'shrink-0 px-1.5 py-0.5 rounded-md text-[10px] uppercase tracking-wide border border-sky-500/30 bg-sky-500/10 text-sky-200'
            : 'shrink-0 px-1.5 py-0.5 rounded-md text-[10px] uppercase tracking-wide border border-violet-500/30 bg-violet-500/10 text-violet-200';
        typeBadge.textContent = uiText(type === 'group' ? 'sidebar_group' : 'sidebar_direct');
        primaryLine.appendChild(typeBadge);
    }

    if (showFavoriteStar) {
        const star = document.createElement('i');
        star.className = 'fa-solid fa-star text-amber-400 text-[11px]';
        star.title = uiText('favorite');
        primaryLine.appendChild(star);
    }

    if (hasPersonalStatus) {
        const statusDot = document.createElement('span');
        statusDot.className = `presence-dot inline-block w-2 h-2 rounded-full ${String(chat.effective_status_dot_class || 'bg-zinc-500')}`;
        statusDot.title = presenceLabel(chat.effective_status);
        primaryLine.appendChild(statusDot);
    }

    if (customMode) {
        const dragIcon = document.createElement('i');
        dragIcon.className = 'fa-solid fa-grip-vertical text-zinc-500 text-[10px]';
        dragIcon.title = uiText('drag_to_reorder');
        primaryLine.appendChild(dragIcon);
    }

    if (unreadCount > 0) {
        const unreadBadge = document.createElement('span');
        unreadBadge.className = 'ml-auto min-w-[1.25rem] h-5 px-1 rounded-full bg-emerald-600 text-white text-xs inline-flex items-center justify-center';
        unreadBadge.textContent = String(formatCountBadgeValue(unreadCount));
        primaryLine.appendChild(unreadBadge);
    }

    const secondary = document.createElement('div');
    secondary.className = 'text-xs text-zinc-400 truncate';
    secondary.textContent = String(secondaryLine);

    item.appendChild(primaryLine);
    item.appendChild(secondary);
    return item;
}

function getVisibleSidebarChats() {
    if (sidebarMode === 'unread') {
        return sidebarChatsCache.filter((chat) => Number(chat.unread_count) > 0);
    }

    if (sidebarMode === 'pm') {
        return sidebarChatsCache.filter((chat) => normalizeChatType(chat.type) === 'personal');
    }

    if (sidebarMode === 'group') {
        return sidebarChatsCache.filter((chat) => normalizeChatType(chat.type) === 'group');
    }

    if (sidebarMode === 'custom') {
        const chatByNumber = new Map(sidebarChatsCache.map((chat) => [String(chat.chat_number || ''), chat]));
        return sidebarCustomChatNumbers
            .map((chatNumber) => chatByNumber.get(chatNumber))
            .filter(Boolean);
    }

    return sidebarChatsCache;
}

function updateSidebarModeButtons() {
    const { modeToggle, customControls } = getSidebarElements();
    if (!modeToggle) return;
    const unreadFlags = getSidebarUnreadFlags();

    const buttons = modeToggle.querySelectorAll('[data-chat-sidebar-mode]');
    buttons.forEach((button) => {
        if (!(button instanceof HTMLElement)) return;
        const mode = normalizeSidebarMode(button.dataset.chatSidebarMode);
        const isActive = mode === sidebarMode;
        const shouldHighlightUnread = unreadFlags[mode] === true;
        button.setAttribute('aria-pressed', String(isActive));

        button.classList.remove('bg-emerald-600', 'bg-emerald-600/25', 'text-emerald-100', 'text-emerald-200');
        button.classList.toggle('bg-zinc-700', isActive);
        button.classList.toggle('text-zinc-100', isActive);
        button.classList.toggle('text-zinc-400', !isActive);
        button.classList.toggle('hover:text-zinc-200', !isActive);

        if (shouldHighlightUnread) {
            if (isActive) {
                button.classList.remove('bg-zinc-700', 'text-zinc-100');
                button.classList.add('bg-emerald-600', 'text-emerald-100');
            } else {
                button.classList.remove('text-zinc-400');
                button.classList.add('bg-emerald-600', 'text-emerald-100');
            }
        }
    });

    if (customControls) {
        customControls.classList.toggle('hidden', sidebarMode !== 'custom');
    }
}

function clearSidebarDragIndicators() {
    const { list } = getSidebarElements();
    if (!list) return;
    list.querySelectorAll('[data-sidebar-custom-item]').forEach((node) => {
        node.classList.remove('ring-1', 'ring-emerald-500/60');
    });
}

function renderSidebarList() {
    const { list, modeToggle } = getSidebarElements();
    if (!list) return;
    updateSidebarCustomCount();
    updateSidebarModeButtons();

    const chats = getVisibleSidebarChats();
    const customLabel = sidebarMode === 'custom' ? getSidebarCustomDisplayLabel() : null;
    const signature = JSON.stringify([sidebarMode, customLabel, chats]);
    if (signature === sidebarRenderSignature) return;

    const focusedChatNumber = list.contains(document.activeElement)
        ? document.activeElement.dataset.sidebarChatNumber
        : '';
    const options = { customMode: sidebarMode === 'custom', showTypeBadge: !['group', 'pm'].includes(sidebarMode) };
    const nodes = chats.map((chat) => buildSidebarChatNode(chat, options));

    if (nodes.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'text-zinc-500 text-sm px-3 py-1';
        if (sidebarMode === 'unread') {
            empty.textContent = uiText('no_unread_chats');
        } else if (customLabel !== null) {
            empty.textContent = uiText('sidebar_custom_empty', { category: customLabel });
        } else {
            empty.textContent = uiText('no_chats_yet');
        }
        nodes.push(empty);
    }
    list.replaceChildren(...nodes);
    sidebarRenderSignature = signature;
    if (focusedChatNumber) {
        const focusedChat = nodes.find(node => node.dataset.sidebarChatNumber === focusedChatNumber);
        const focusTarget = focusedChat ?? modeToggle?.querySelector('[aria-pressed="true"]');
        focusTarget?.focus({ preventScroll: true });
    }
}

function bindSidebarCustomRenameModal() {
    const modal = document.getElementById('sidebar-custom-rename-modal');
    const form = document.getElementById('sidebar-custom-rename-form');
    const input = document.getElementById('sidebar-custom-rename-input');
    const cancel = document.getElementById('sidebar-custom-rename-cancel');
    const submit = document.getElementById('sidebar-custom-rename-submit');
    if (!modal || !form || !input || !cancel || !submit) return;
    if (modal.dataset.bound === '1') return;
    modal.dataset.bound = '1';

    const setOpenState = (isOpen) => {
        modal.classList.toggle('hidden', !isOpen);
        modal.setAttribute('aria-hidden', isOpen ? 'false' : 'true');

        if (isOpen) {
            const current = getSidebarCustomLabel();
            input.value = current === SIDEBAR_CUSTOM_DEFAULT_LABEL ? '' : current;
            setTimeout(() => {
                input.focus();
                input.select();
            }, 0);
            return;
        }

        submit.disabled = false;
        submit.textContent = uiText('save');
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

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;

        const submitted = String(input.value || '').trim();

        submit.disabled = true;
        submit.textContent = uiText('saving');

        try {
            await persistSidebarCustomLabel(submitted);
            updateSidebarCustomCount();
            if (sidebarMode === 'custom') {
                renderSidebarList();
            }
            closeModal();
        } catch {
            showToast(uiText('unable_to_rename_category'), 'error');
        } finally {
            submit.disabled = false;
            submit.textContent = uiText('save');
        }
    });

    window.openSidebarCustomRenameModal = () => setOpenState(true);
}

function renderSidebarCustomTypeahead() {
    const { customSearch, customTypeahead } = getSidebarElements();
    if (!customSearch || !customTypeahead || sidebarMode !== 'custom') {
        hideSidebarCustomTypeahead();
        return;
    }

    const query = String(customSearch.value || '').trim().toLowerCase();
    if (!query) {
        hideSidebarCustomTypeahead();
        return;
    }

    if (sidebarCustomChatNumbers.length >= SIDEBAR_CUSTOM_MAX_ITEMS) {
        hideSidebarCustomTypeahead();
        return;
    }

    const selected = new Set(sidebarCustomChatNumbers);
    const matches = sidebarChatsCache.filter((chat) => {
        const chatNumber = String(chat.chat_number || '');
        if (selected.has(chatNumber)) return false;
        const title = String(getChatSidebarTitle(chat)).toLowerCase();
        const formatted = String(formatNumber(chatNumber)).toLowerCase();
        return title.includes(query) || formatted.includes(query) || chatNumber.includes(query);
    }).slice(0, 6);

    if (matches.length === 0) {
        hideSidebarCustomTypeahead();
        return;
    }

    const buttons = matches.map((chat) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'w-full text-left px-3 py-2 hover:bg-zinc-800 text-sm flex items-center justify-between gap-2';
        button.dataset.sidebarCustomTypeahead = String(chat.chat_number || '');

        const title = document.createElement('span');
        title.className = 'truncate';
        title.textContent = String(getChatSidebarTitle(chat));

        const badge = document.createElement('span');
        badge.className = 'text-[10px] uppercase tracking-wide text-zinc-400 shrink-0';
        badge.textContent = uiText(normalizeChatType(chat.type) === 'group' ? 'sidebar_group' : 'sidebar_direct');

        button.appendChild(title);
        button.appendChild(badge);
        return button;
    });

    customTypeahead.replaceChildren(...buttons);
    customTypeahead.classList.remove('hidden');
}

async function addChatToCustomSidebar(chatNumber) {
    const number = String(chatNumber || '').trim();
    if (!number) return;
    if (sidebarCustomChatNumbers.includes(number)) return;

    if (sidebarCustomChatNumbers.length >= SIDEBAR_CUSTOM_MAX_ITEMS) {
        showToast(uiText('custom_list_can_have_up_to_8_chats'), 'error');
        return;
    }

    if (!getChatByNumber(number)) {
        showToast(uiText('chat_unavailable'), 'error');
        return;
    }

    sidebarCustomChatNumbers.push(number);

    try {
        await persistSidebarCustomConfig();
        renderSidebarList();
    } catch {
        sidebarCustomChatNumbers = sidebarCustomChatNumbers.filter((value) => value !== number);
        showToast(uiText('unable_to_update_custom_list'), 'error');
    }
}

async function removeChatFromCustomSidebar(chatNumber) {
    const number = String(chatNumber || '').trim();
    if (!number) return;

    const next = sidebarCustomChatNumbers.filter((value) => value !== number);
    if (next.length === sidebarCustomChatNumbers.length) return;

    const previous = [...sidebarCustomChatNumbers];
    sidebarCustomChatNumbers = next;

    try {
        await persistSidebarCustomConfig();
        renderSidebarList();
    } catch {
        sidebarCustomChatNumbers = previous;
        showToast(uiText('unable_to_update_custom_list'), 'error');
    }
}

async function reorderCustomSidebarChats(draggedChatNumber, targetChatNumber) {
    const dragged = String(draggedChatNumber || '').trim();
    const target = String(targetChatNumber || '').trim();
    if (!dragged || !target || dragged === target) return;

    const current = [...sidebarCustomChatNumbers];
    const fromIndex = current.indexOf(dragged);
    const toIndex = current.indexOf(target);
    if (fromIndex < 0 || toIndex < 0) return;

    current.splice(fromIndex, 1);
    current.splice(toIndex, 0, dragged);

    if (current.join(',') === sidebarCustomChatNumbers.join(',')) return;
    const previous = [...sidebarCustomChatNumbers];
    sidebarCustomChatNumbers = current;

    try {
        await persistSidebarCustomConfig();
        renderSidebarList();
    } catch {
        sidebarCustomChatNumbers = previous;
        renderSidebarList();
        showToast(uiText('unable_to_save_custom_order'), 'error');
    }
}

async function copyTextFallback(text) {
    const value = String(text || '');
    if (!value) return;
    if (typeof window.copyTextToClipboard === 'function') {
        await window.copyTextToClipboard(value);
        return;
    }

    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(value);
        return;
    }

    const tempInput = document.createElement('textarea');
    tempInput.value = value;
    tempInput.setAttribute('readonly', '');
    tempInput.style.position = 'absolute';
    tempInput.style.left = '-9999px';
    document.body.appendChild(tempInput);
    tempInput.select();
    document.execCommand('copy');
    tempInput.remove();
}

function hideSidebarCustomContextMenu() {
    const { customMenu } = getSidebarElements();
    if (!customMenu) return;
    customMenu.classList.add('hidden');
    sidebarCustomContextChatNumber = '';
}

function setSidebarContextMenuButtonVisibility(action, visible) {
    const { customMenu } = getSidebarElements();
    if (!customMenu) return;

    const button = customMenu.querySelector(`[data-custom-menu-action="${action}"]`);
    if (!(button instanceof HTMLButtonElement)) return;

    button.classList.toggle('hidden', !visible);
}

function showSidebarCustomContextMenu(event, chatNumber) {
    const { customMenu } = getSidebarElements();
    if (!customMenu) return;

    const chat = getChatByNumber(chatNumber);
    const isGroup = normalizeChatType(chat?.type) === 'group';
    const number = String(chatNumber || '').trim();
    const inCustomList = sidebarCustomChatNumbers.includes(number);
    const canAddToCustom = !inCustomList && sidebarCustomChatNumbers.length < SIDEBAR_CUSTOM_MAX_ITEMS;

    setSidebarContextMenuButtonVisibility('open', Boolean(chat));
    setSidebarContextMenuButtonVisibility('add', canAddToCustom);
    setSidebarContextMenuButtonVisibility('copy-link', isGroup);
    setSidebarContextMenuButtonVisibility('copy-number', isGroup);
    setSidebarContextMenuButtonVisibility('remove', inCustomList);

    customMenu.style.left = `${event.clientX}px`;
    customMenu.style.top = `${event.clientY}px`;
    customMenu.classList.remove('hidden');
    sidebarCustomContextChatNumber = String(chatNumber || '');

    const rect = customMenu.getBoundingClientRect();
    const maxLeft = window.innerWidth - rect.width - 8;
    const maxTop = window.innerHeight - rect.height - 8;
    customMenu.style.left = `${Math.max(8, Math.min(event.clientX, maxLeft))}px`;
    customMenu.style.top = `${Math.max(8, Math.min(event.clientY, maxTop))}px`;
}

function bindSidebarCustomMenu() {
    const { customMenu } = getSidebarElements();
    if (!customMenu || customMenu.dataset.bound === '1') return;
    customMenu.dataset.bound = '1';

    customMenu.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-custom-menu-action]');
        if (!(button instanceof HTMLButtonElement)) return;

        const action = String(button.dataset.customMenuAction || '');
        const chatNumber = String(sidebarCustomContextChatNumber || '');
        const chat = getChatByNumber(chatNumber);
        if (!chatNumber || !chat) {
            hideSidebarCustomContextMenu();
            return;
        }

        if ((action === 'copy-link' || action === 'copy-number') && normalizeChatType(chat.type) !== 'group') {
            hideSidebarCustomContextMenu();
            return;
        }

        try {
            if (action === 'open') {
                window.open(appPath(`/c/${formatNumber(chatNumber)}`), '_blank', 'noopener');
            } else if (action === 'add') {
                await addChatToCustomSidebar(chatNumber);
            } else if (action === 'copy-link') {
                const chatPath = appPath(`/c/${formatNumber(chatNumber)}`);
                await copyTextFallback(new URL(chatPath, window.location.origin).href);
                showToast(uiText('group_link_copied'), 'success');
            } else if (action === 'copy-number') {
                await copyTextFallback(formatNumber(chatNumber));
                showToast(uiText('group_number_copied'), 'success');
            } else if (action === 'remove') {
                await removeChatFromCustomSidebar(chatNumber);
            }
        } catch {
            showToast(uiText('unable_to_complete_action'), 'error');
        }

        hideSidebarCustomContextMenu();
    });

    document.addEventListener('click', (event) => {
        if (customMenu.classList.contains('hidden')) return;
        if (customMenu.contains(event.target)) return;
        hideSidebarCustomContextMenu();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (customMenu.classList.contains('hidden')) return;
        hideSidebarCustomContextMenu();
    });
}

function bindSidebarControls() {
    const { modeToggle, customSearch, customTypeahead, list } = getSidebarElements();
    if (!modeToggle || !list) return;
    if (modeToggle.dataset.bound === '1') return;
    modeToggle.dataset.bound = '1';

    modeToggle.addEventListener('click', (event) => {
        const button = event.target.closest('[data-chat-sidebar-mode]');
        if (!(button instanceof HTMLButtonElement)) return;
        const nextMode = normalizeSidebarMode(button.dataset.chatSidebarMode);
        if (nextMode === sidebarMode) return;

        sidebarMode = nextMode;
        setStoredSidebarMode(nextMode);
        updateSidebarModeButtons();
        hideSidebarCustomTypeahead();
        hideSidebarCustomContextMenu();
        renderSidebarList();

        if (customSearch && sidebarMode === 'custom') {
            customSearch.focus();
        }
    });

    modeToggle.addEventListener('contextmenu', (event) => {
        const target = event.target.closest('[data-chat-sidebar-mode="custom"]');
        if (!(target instanceof HTMLButtonElement)) return;

        event.preventDefault();
        hideSidebarCustomContextMenu();
        hideSidebarCustomTypeahead();
        if (typeof window.openSidebarCustomRenameModal === 'function') {
            window.openSidebarCustomRenameModal();
        }
    });

    if (customSearch) {
        customSearch.addEventListener('input', () => {
            renderSidebarCustomTypeahead();
        });

        customSearch.addEventListener('focus', () => {
            renderSidebarCustomTypeahead();
        });

        customSearch.addEventListener('keydown', async (event) => {
            if (event.key !== 'Enter') return;
            if (!customTypeahead || customTypeahead.classList.contains('hidden')) return;
            const options = customTypeahead.querySelectorAll('[data-sidebar-custom-typeahead]');
            if (options.length !== 1) return;
            event.preventDefault();
            const chatNumber = String(options[0].getAttribute('data-sidebar-custom-typeahead') || '').trim();
            if (!chatNumber) return;
            await addChatToCustomSidebar(chatNumber);
            customSearch.value = '';
            hideSidebarCustomTypeahead();
        });
    }

    if (customTypeahead) {
        customTypeahead.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-sidebar-custom-typeahead]');
            if (!(button instanceof HTMLElement)) return;
            const chatNumber = String(button.dataset.sidebarCustomTypeahead || '').trim();
            if (!chatNumber) return;
            await addChatToCustomSidebar(chatNumber);
            if (customSearch) {
                customSearch.value = '';
            }
            hideSidebarCustomTypeahead();
        });
    }

    list.addEventListener('dragstart', (event) => {
        if (sidebarMode !== 'custom') return;
        const target = event.target.closest('[data-sidebar-custom-item]');
        if (!(target instanceof HTMLElement)) return;
        const chatNumber = String(target.dataset.sidebarChatNumber || '');
        if (!chatNumber) return;

        sidebarIsDraggingCustom = true;
        target.classList.add('opacity-60');
        event.dataTransfer?.setData('text/plain', chatNumber);
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
        }
    });

    list.addEventListener('dragover', (event) => {
        if (sidebarMode !== 'custom') return;
        event.preventDefault();

        const target = event.target.closest('[data-sidebar-custom-item]');
        if (!(target instanceof HTMLElement)) return;
        clearSidebarDragIndicators();
        target.classList.add('ring-1', 'ring-emerald-500/60');
    });

    list.addEventListener('dragleave', (event) => {
        const target = event.target.closest?.('[data-sidebar-custom-item]');
        if (!(target instanceof HTMLElement)) return;
        target.classList.remove('ring-1', 'ring-emerald-500/60');
    });

    list.addEventListener('drop', async (event) => {
        if (sidebarMode !== 'custom') return;
        event.preventDefault();

        const droppedOn = event.target.closest('[data-sidebar-custom-item]');
        const draggedChatNumber = String(event.dataTransfer?.getData('text/plain') || '').trim();
        const targetChatNumber = droppedOn instanceof HTMLElement
            ? String(droppedOn.dataset.sidebarChatNumber || '').trim()
            : '';

        clearSidebarDragIndicators();
        if (draggedChatNumber && targetChatNumber && draggedChatNumber !== targetChatNumber) {
            await reorderCustomSidebarChats(draggedChatNumber, targetChatNumber);
        }
    });

    list.addEventListener('dragend', (event) => {
        sidebarIsDraggingCustom = false;
        clearSidebarDragIndicators();
        const target = event.target.closest?.('[data-sidebar-custom-item]');
        if (target instanceof HTMLElement) {
            target.classList.remove('opacity-60');
        }
    });

    list.addEventListener('contextmenu', (event) => {
        const target = event.target.closest('[data-sidebar-chat-number]');
        if (!(target instanceof HTMLElement)) return;
        const chatNumber = String(target.dataset.sidebarChatNumber || '').trim();
        if (!chatNumber) return;

        event.preventDefault();
        showSidebarCustomContextMenu(event, chatNumber);
    });

    document.addEventListener('click', (event) => {
        if (!customSearch || !customTypeahead) return;
        if (customTypeahead.classList.contains('hidden')) return;
        if (!customSearch.contains(event.target) && !customTypeahead.contains(event.target)) {
            hideSidebarCustomTypeahead();
        }
    });

    bindSidebarCustomMenu();
    bindSidebarCustomRenameModal();
}


async function loadSidebarChats() {
    const { list } = getSidebarElements();
    if (!list) return;

    if (!sidebarCustomConfigLoaded) {
        await fetchSidebarCustomConfig();
    }

    if (!sidebarModeInitialized) {
        sidebarMode = getStoredSidebarMode();
        sidebarModeInitialized = true;
    }

    const res = await fetch(appPath('/api/chats'));
    const data = await res.json();
    const chats = Array.isArray(data.chats) ? data.chats : [];

    sidebarChatsCache = chats;
    await syncSidebarCustomChatNumbers(chats);

    bindSidebarControls();
    updateSidebarModeButtons();

    if (!sidebarIsDraggingCustom) {
        renderSidebarList();
    }

    renderSidebarCustomTypeahead();
}

let groupCreationInFlight = false;

async function createGroupChat() {
    if (groupCreationInFlight) return;
    groupCreationInFlight = true;

    try {
        const result = await postForm('/api/chats/group/create', {
            csrf_token: getCsrfToken()
        });
        if (result.success && result.chat_number) {
            navigateWithFallback(`/c/${formatNumber(result.chat_number)}?prompt_group_name=1`, { source: 'create-group', updateHistory: true });
            return;
        }
        showToast(uiActionError(result.error, 'unable_to_create_group_chat'), 'error');
    } catch {
        showToast(uiText('unable_to_create_group_chat'), 'error');
    } finally {
        groupCreationInFlight = false;
    }
}

async function addGroupMemberByUsername(event, usernameOverride = null) {
    if (event && typeof event.preventDefault === 'function') {
        event.preventDefault();
    }

    if (!currentChat) return;
    if (normalizeChatType(currentChat.type) !== 'group') {
        showToast(uiText('only_group_chats_can_add_or_remove_members'), 'error');
        return;
    }

    const usernameInput = document.getElementById('group-add-username');
    const username = String(usernameOverride ?? (usernameInput?.value || '')).trim();
    if (!username) {
        showToast(uiText('enter_a_username'), 'error');
        return;
    }

    const result = await postForm('/api/chats/group/add-member', {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id),
        username
    });

    if (result.success) {
        showToast(uiText('user_added_to_group'), 'success');
        if (usernameInput) {
            usernameInput.value = '';
        }
        await reloadCurrentView({ source: 'add-group-member' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_add_member'), 'error');
}

function bindAddUserModal() {
    const modal = document.getElementById('add-user-modal');
    const form = document.getElementById('add-user-form');
    const input = document.getElementById('add-user-input');
    const typeahead = document.getElementById('add-user-typeahead');
    const cancel = document.getElementById('add-user-cancel');
    const submit = document.getElementById('add-user-submit');
    if (!modal || !form || !input || !cancel || !submit) return;

    let friendsCache = null;

    async function loadFriends() {
        if (friendsCache) return friendsCache;
        try {
            const res = await fetch(appPath('/api/friends'));
            const data = await res.json();
            friendsCache = Array.isArray(data.friends) ? data.friends : [];
        } catch {
            friendsCache = [];
        }
        return friendsCache;
    }

    function getCurrentMemberUsernames() {
        const chips = document.querySelectorAll('[data-group-member-username]');
        const names = new Set();
        chips.forEach((el) => {
            const name = String(el.dataset.groupMemberUsername || '').trim().toLowerCase();
            if (name) names.add(name);
        });
        return names;
    }

    function renderTypeahead(query) {
        if (!typeahead) return;
        const normalizedQuery = String(query || '').trim().toLowerCase();
        if (!normalizedQuery || !friendsCache) {
            typeahead.replaceChildren();
            typeahead.classList.add('hidden');
            return;
        }

        const currentMembers = getCurrentMemberUsernames();
        const matches = friendsCache
            .filter((f) => {
                const username = String(f.username || '').toLowerCase();
                return username.includes(normalizedQuery) && !currentMembers.has(username);
            })
            .slice(0, 4);

        if (matches.length === 0 || matches.length > 3) {
            typeahead.replaceChildren();
            typeahead.classList.add('hidden');
            return;
        }

        const buttons = matches.map((friend) => {
            const username = String(friend?.username || '');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'w-full text-left px-4 py-2.5 hover:bg-zinc-800 text-sm';
            button.dataset.addUserTypeahead = username;
            button.textContent = username;
            return button;
        });

        typeahead.replaceChildren(...buttons);
        typeahead.classList.remove('hidden');
    }

    function hideTypeahead() {
        if (typeahead) {
            typeahead.replaceChildren();
            typeahead.classList.add('hidden');
        }
    }

    const setOpenState = (isOpen) => {
        modal.classList.toggle('hidden', !isOpen);

        if (isOpen) {
            input.value = '';
            hideTypeahead();
            friendsCache = null;
            loadFriends();
            setTimeout(() => {
                input.focus();
            }, 0);
            return;
        }

        submit.disabled = false;
        submit.textContent = uiText('add_user');
        hideTypeahead();
    };

    const closeModal = () => {
        setOpenState(false);
    };

    input.addEventListener('input', () => {
        renderTypeahead(input.value);
    });

    input.addEventListener('focus', () => {
        if (input.value.trim()) {
            renderTypeahead(input.value);
        }
    });

    input.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        if (!typeahead || typeahead.classList.contains('hidden')) return;
        const buttons = typeahead.querySelectorAll('[data-add-user-typeahead]');
        if (buttons.length !== 1) return;
        event.preventDefault();
        const username = String(buttons[0].dataset.addUserTypeahead || '').trim();
        input.value = username;
        hideTypeahead();
    });

    if (typeahead) {
        typeahead.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-add-user-typeahead]');
            if (!(btn instanceof HTMLElement)) return;
            const username = String(btn.dataset.addUserTypeahead || '').trim();
            input.value = username;
            hideTypeahead();
        });
    }

    cancel.addEventListener('click', (event) => {
        event.preventDefault();
        closeModal();
    });

    modal.addEventListener('click', (event) => {
        if (event.target !== modal) return;
        closeModal();
    });

    document.addEventListener('click', (event) => {
        if (!typeahead || typeahead.classList.contains('hidden')) return;
        if (!input.contains(event.target) && !typeahead.contains(event.target)) {
            hideTypeahead();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (modal.classList.contains('hidden')) return;
        if (typeahead && !typeahead.classList.contains('hidden')) {
            hideTypeahead();
            return;
        }
        closeModal();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;

        submit.disabled = true;
        submit.textContent = uiText('adding');
        hideTypeahead();

        try {
            await addGroupMemberByUsername(null, input.value);
        } finally {
            submit.disabled = false;
            submit.textContent = uiText('add_user');
        }
    });

    window.openAddUserModal = () => setOpenState(true);
}

async function removeGroupMember(userId, username) {
    if (!currentChat) return;
    if (normalizeChatType(currentChat.type) !== 'group') {
        showToast(uiText('only_group_chats_can_add_or_remove_members'), 'error');
        return;
    }

    const ownerUserId = Number(currentChat.owner_user_id || 0);
    if (ownerUserId > 0 && Number(userId) === ownerUserId) {
        showToast(uiText('group_owner_cannot_be_removed'), 'error');
        return;
    }

    const result = await postForm('/api/chats/group/remove-member', {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id),
        user_id: String(userId)
    });

    if (result.success) {
        showToast(uiText('user_removed_from_group'), 'success');
        const memberChip = document.querySelector(`[data-group-member-user-id="${Number(userId)}"]`);
        if (memberChip) {
            memberChip.remove();
        }
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_remove_member'), 'error');
}

async function promoteGroupModerator(userId, username) {
    if (!currentChat) return;
    const result = await postForm('/api/chats/group/promote-moderator', {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id),
        user_id: String(userId)
    });

    if (result.success) {
        showToast(uiText('group_moderator_promoted', { name: username || uiText('group_user_default') }), 'success');
        await reloadCurrentView({ source: 'promote-group-moderator' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_promote_moderator'), 'error');
}

async function demoteGroupModerator(userId, username) {
    if (!currentChat) return;
    const result = await postForm('/api/chats/group/demote-moderator', {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id),
        user_id: String(userId)
    });

    if (result.success) {
        showToast(uiText('group_moderator_demoted', { name: username || uiText('group_user_default') }), 'success');
        await reloadCurrentView({ source: 'demote-group-moderator' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_demote_moderator'), 'error');
}

async function muteGroupMember(userId, username) {
    if (!currentChat) return;
    const result = await postForm('/api/chats/group/mute-member', {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id),
        user_id: String(userId)
    });

    if (result.success) {
        showToast(uiText('group_member_muted', { name: username || uiText('group_user_default') }), 'success');
        await reloadCurrentView({ source: 'mute-group-member' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_mute_member'), 'error');
}

async function unmuteGroupMember(userId, username) {
    if (!currentChat) return;
    const result = await postForm('/api/chats/group/unmute-member', {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id),
        user_id: String(userId)
    });

    if (result.success) {
        showToast(uiText('group_member_unmuted', { name: username || uiText('group_user_default') }), 'success');
        await reloadCurrentView({ source: 'unmute-group-member' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_unmute_member'), 'error');
}

async function requestCurrentGroupJoin() {
    if (!currentChat) return;

    const result = await postForm('/api/chats/group/request-join', {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id)
    });

    if (result.success) {
        showToast(uiText('join_request_sent'), 'success');
        await reloadCurrentView({ source: 'group-request-join' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_request_access'), 'error');
}

async function cancelCurrentGroupJoinRequest() {
    if (!currentChat) return;

    const result = await postForm('/api/chats/group/cancel-request', {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id)
    });

    if (result.success) {
        showToast(uiText('join_request_cancelled'), 'success');
        await reloadCurrentView({ source: 'group-cancel-request' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_cancel_request'), 'error');
}

async function approveGroupJoinRequest(userId) {
    if (!currentChat) return;

    const result = await postForm('/api/chats/group/approve-request', {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id),
        user_id: String(userId)
    });

    if (result.success) {
        showToast(uiText('join_request_approved'), 'success');
        await reloadCurrentView({ source: 'group-approve-request' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_approve_request'), 'error');
}

async function denyGroupJoinRequest(userId) {
    if (!currentChat) return;

    const result = await postForm('/api/chats/group/deny-request', {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id),
        user_id: String(userId)
    });

    if (result.success) {
        showToast(uiText('join_request_denied'), 'success');
        await reloadCurrentView({ source: 'group-deny-request' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_deny_request'), 'error');
}

window.promoteGroupModerator = promoteGroupModerator;
window.demoteGroupModerator = demoteGroupModerator;
window.muteGroupMember = muteGroupMember;
window.unmuteGroupMember = unmuteGroupMember;
window.approveGroupJoinRequest = approveGroupJoinRequest;
window.denyGroupJoinRequest = denyGroupJoinRequest;

async function leaveCurrentGroup() {
    if (!currentChat) return;
    if (normalizeChatType(currentChat.type) !== 'group') {
        showToast(uiText('only_group_chats_can_be_left'), 'error');
        return;
    }

    if (typeof window.openLeaveGroupModal === 'function') {
        window.openLeaveGroupModal();
    }
}

function getEligibleNewOwners() {
    const memberNodes = Array.from(document.querySelectorAll('[data-group-member-user-id]'));
    const safeCurrentUserId = Number(currentUserId || 0);
    return memberNodes
        .map((node) => {
            const userId = Number(node.getAttribute('data-group-member-user-id') || 0);
            const username = String(node.getAttribute('data-group-member-username') || '').trim();
            if (!Number.isFinite(userId) || userId <= 0 || !username) {
                return null;
            }
            if (safeCurrentUserId > 0 && userId === safeCurrentUserId) {
                return null;
            }
            return { userId, username };
        })
        .filter(Boolean);
}

async function submitLeaveGroup(newOwnerUserId = 0) {
    if (!currentChat) return;
    if (normalizeChatType(currentChat.type) !== 'group') {
        showToast(uiText('only_group_chats_can_be_left'), 'error');
        return;
    }

    const currentOwnerId = Number(currentChat.owner_user_id || 0);
    const isCurrentUserOwner = currentOwnerId > 0 && currentOwnerId === Number(currentUserId || 0);
    const payload = {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id)
    };

    if (isCurrentUserOwner) {
        const ownerOptions = getEligibleNewOwners();

        if (ownerOptions.length === 0) {
            showToast(uiText('you_cannot_leave_a_group_you_own_with'), 'error');
            return;
        }

        const ownerId = Number(newOwnerUserId || 0);
        if (!ownerOptions.some((option) => option.userId === ownerId)) {
            showToast(uiText('choose_a_current_group_member_to_transfer_ownership'), 'error');
            return;
        }

        payload.new_owner_user_id = String(ownerId);
    }

    const result = await postForm('/api/chats/group/leave', payload);

    if (result.success) {
        const nextLocation = String(result.redirect || '/');
        navigateWithFallback(nextLocation, { source: 'leave-group', updateHistory: true });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_leave_group'), 'error');
}

async function deleteCurrentGroup() {
    if (!currentChat) return;
    if (normalizeChatType(currentChat.type) !== 'group') {
        showToast(uiText('only_group_chats_can_be_deleted'), 'error');
        return;
    }

    if (typeof window.openDeleteGroupModal === 'function') {
        window.openDeleteGroupModal();
    }
}

async function submitDeleteGroup() {
    if (!currentChat) return;
    if (normalizeChatType(currentChat.type) !== 'group') {
        showToast(uiText('only_group_chats_can_be_deleted'), 'error');
        return;
    }

    const result = await postForm('/api/chats/group/delete', {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id)
    });

    if (result.success) {
        navigateWithFallback(String(result.redirect || '/'), { source: 'delete-group', updateHistory: true });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_delete_group'), 'error');
}

async function takeCurrentGroupOwnership() {
    if (!currentChat) return;
    if (normalizeChatType(currentChat.type) !== 'group') {
        showToast(uiText('only_group_chats_can_change_ownership'), 'error');
        return;
    }

    const ownerUserId = Number(currentChat.owner_user_id || 0);
    if (ownerUserId > 0 && ownerUserId === Number(currentUserId || 0)) {
        showToast(uiText('you_already_own_this_group'), 'error');
        return;
    }

    if (typeof window.openTakeOwnershipModal === 'function') {
        window.openTakeOwnershipModal();
        return;
    }

    await submitTakeCurrentGroupOwnership();
}

async function submitTakeCurrentGroupOwnership() {
    if (!currentChat) return;
    if (normalizeChatType(currentChat.type) !== 'group') {
        showToast(uiText('only_group_chats_can_change_ownership'), 'error');
        return;
    }

    const ownerUserId = Number(currentChat.owner_user_id || 0);
    if (ownerUserId > 0 && ownerUserId === Number(currentUserId || 0)) {
        showToast(uiText('you_already_own_this_group'), 'error');
        return;
    }

    const result = await postForm('/api/chats/group/take-ownership', {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id)
    });

    if (result.success) {
        showToast(uiText('you_now_own_this_group'), 'success');
        await reloadCurrentView({ source: 'take-group-ownership' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_take_ownership'), 'error');
}

window.takeCurrentGroupOwnership = takeCurrentGroupOwnership;

function bindTakeOwnershipModal() {
    const modal = document.getElementById('take-ownership-modal');
    const form = document.getElementById('take-ownership-form');
    const cancel = document.getElementById('take-ownership-cancel');
    const submit = document.getElementById('take-ownership-submit');
    if (!modal || !form || !cancel || !submit) return;

    const setOpenState = (isOpen) => {
        modal.classList.toggle('hidden', !isOpen);
        if (!isOpen) {
            submit.disabled = false;
            submit.textContent = uiText('take_ownership');
        }
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

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;

        submit.disabled = true;
        submit.textContent = uiText('taking');

        try {
            await submitTakeCurrentGroupOwnership();
        } finally {
            submit.disabled = false;
            submit.textContent = uiText('take_ownership');
            closeModal();
        }
    });

    window.openTakeOwnershipModal = () => setOpenState(true);
}

function bindLeaveGroupModal() {
    const modal = document.getElementById('leave-group-modal');
    const form = document.getElementById('leave-group-form');
    const cancel = document.getElementById('leave-group-cancel');
    const submit = document.getElementById('leave-group-submit');
    const ownerTransferBox = document.getElementById('leave-group-owner-transfer');
    const ownerSelect = document.getElementById('leave-group-new-owner');
    const description = document.getElementById('leave-group-modal-description');

    if (!modal || !form || !cancel || !submit || !ownerTransferBox || !ownerSelect || !description) return;

    const isOwner = () => {
        const ownerId = Number(currentChat?.owner_user_id || 0);
        const me = Number(currentUserId || 0);
        return ownerId > 0 && ownerId === me;
    };

    const setOpenState = (isOpen) => {
        modal.classList.toggle('hidden', !isOpen);

        if (!isOpen) {
            submit.disabled = false;
            submit.textContent = uiText('leave_group');
            return;
        }

        const ownerFlow = isOwner();
        ownerTransferBox.classList.toggle('hidden', !ownerFlow);

        if (ownerFlow) {
            const options = getEligibleNewOwners();
            ownerSelect.innerHTML = `<option value="">${escapeHtml(uiText('select_member'))}</option>`
                + options.map((option) => `<option value="${option.userId}">${escapeHtml(option.username)}</option>`).join('');

            description.textContent = options.length > 0
                ? uiText('group_owner_choose')
                : uiText('group_owner_none');
            submit.disabled = options.length === 0;
            submit.textContent = options.length > 0 ? uiText('group_owner_transfer_and_leave') : uiText('leave_group');
            return;
        }

        ownerSelect.innerHTML = `<option value="">${escapeHtml(uiText('select_member'))}</option>`;
        description.textContent = uiText('are_you_sure_you_want_to_leave_this');
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

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;

        submit.disabled = true;
        submit.textContent = uiText('leaving');

        try {
            const selectedOwnerUserId = Number(ownerSelect.value || 0);
            await submitLeaveGroup(selectedOwnerUserId);
        } finally {
            submit.disabled = false;
            submit.textContent = uiText('leave_group');
        }
    });

    window.openLeaveGroupModal = () => setOpenState(true);
}

function bindDeleteGroupModal() {
    const modal = document.getElementById('delete-group-modal');
    const form = document.getElementById('delete-group-form');
    const cancel = document.getElementById('delete-group-cancel');
    const submit = document.getElementById('delete-group-submit');
    if (!modal || !form || !cancel || !submit) return;

    const setOpenState = (isOpen) => {
        modal.classList.toggle('hidden', !isOpen);
        if (!isOpen) {
            submit.disabled = false;
            submit.textContent = uiText('delete_group');
        }
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

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;

        submit.disabled = true;
        submit.textContent = uiText('deleting');

        try {
            await submitDeleteGroup();
        } finally {
            submit.disabled = false;
            submit.textContent = uiText('delete_group');
        }
    });

    window.openDeleteGroupModal = () => setOpenState(true);
}

async function renameCurrentChat() {
    const chat = currentChat;
    if (!chat) return;
    if (normalizeChatType(chat.type) !== 'group') {
        showToast(uiText('only_group_chats_can_be_renamed'), 'error');
        return;
    }

    const input = document.getElementById('rename-chat-input');
    const modal = document.getElementById('rename-chat-modal');
    if (!input || !modal) return;

    const safeTitle = String(input.value || '').trim();

    const result = await postForm('/api/chats/rename', {
        csrf_token: getCsrfToken(),
        chat_id: String(chat.id),
        title: safeTitle
    });
    if (currentChat !== chat) return;

    if (result.success) {
        await reloadCurrentView({ source: 'rename-chat' });
        return;
    }

    showToast(uiActionError(result.error, 'unable_to_rename_chat'), 'error');
}

function bindRenameChatModal() {
    const modal = document.getElementById('rename-chat-modal');
    const form = document.getElementById('rename-chat-form');
    const input = document.getElementById('rename-chat-input');
    const cancel = document.getElementById('rename-chat-cancel');
    const submit = document.getElementById('rename-chat-submit');
    if (!modal || !form || !input || !cancel || !submit) return;

    const defaultCancelLabel = uiText('cancel');

    const setOpenState = (isOpen, options = {}) => {
        modal.classList.toggle('hidden', !isOpen);

        if (isOpen) {
            const preferGroupNumber = options && options.preferGroupNumber === true;
            const currentTitle = String(document.getElementById('chat-title')?.textContent || '').trim();
            input.value = currentTitle;
            cancel.textContent = preferGroupNumber ? uiText('use_group_number') : defaultCancelLabel;
            modal.dataset.preferGroupNumberPrompt = preferGroupNumber ? '1' : '0';
            setTimeout(() => {
                input.focus();
                input.select();
            }, 0);
            return;
        }

        submit.disabled = false;
        submit.textContent = uiText('save');
        cancel.textContent = defaultCancelLabel;
        modal.dataset.preferGroupNumberPrompt = '0';
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

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) return;

        const isInitialGroupNumberPrompt = modal.dataset.preferGroupNumberPrompt === '1';
        const safeTitle = String(input.value || '').trim();
        const groupNumberLabel = currentChat ? String(formatNumber(currentChat.chat_number)) : '';
        if (isInitialGroupNumberPrompt && groupNumberLabel !== '' && safeTitle === groupNumberLabel) {
            closeModal();
            return;
        }

        submit.disabled = true;
        submit.textContent = uiText('saving');

        try {
            await renameCurrentChat();
        } finally {
            submit.disabled = false;
            submit.textContent = uiText('save');
        }
    });

    window.openRenameChatModal = (options = {}) => setOpenState(true, options);
}

const backgroundMessagePolls = new WeakSet();

async function submitChatMessage(form) {
    const chat = currentChat;
    const input = document.getElementById('message-input');
    const timeline = document.getElementById('messages');
    const errorBox = document.getElementById('message-send-error');
    const quoteInput = document.getElementById('quoted-message-id');
    const draft = input?.value ?? '';
    const content = draft.trim();
    const attachments = [...pendingAttachments];
    if (!input || !chat || !timeline || submittingMessageForms.has(form) || (!content && !attachments.length)) return;
    if (!isCurrentChatView(chat, form)) {
        showToast(uiText('chat_context_changed'), 'error');
        return;
    }
    if (content.length > 16384) {
        showToast(uiText('message_exceeds_the_maximum_length_of_16_384'), 'error');
        return;
    }
    if (chat.can_send_messages === false) {
        showToast(getPersonalChatMessageRestrictionToast(chat.message_restriction_reason), 'error');
        return;
    }
    if (errorBox) {
        errorBox.textContent = '';
        errorBox.classList.add('hidden');
        errorBox.classList.remove('message-send-timeout');
    }

    const preview = document.createElement('div');
    preview.className = 'message-send-preview';
    const body = document.createElement('div');
    body.className = 'message-text message-send-preview-content';
    body.textContent = content;
    if (attachments.length) {
        const files = document.createElement('div');
        files.className = 'message-send-preview-files';
        files.textContent = attachments.map(item => item.original_name || uiText('attachment_label')).join(', ');
        body.append(files);
    }
    const status = document.createElement('div');
    status.className = 'message-send-preview-status';
    status.setAttribute('role', 'status');
    preview.append(body, status);
    const entry = { preview, messageId: null };
    const previews = outgoingMessagePreviews.get(timeline) ?? new Set();
    previews.add(entry);
    outgoingMessagePreviews.set(timeline, previews);
    timeline.append(preview);
    timeline.scrollTop = timeline.scrollHeight;

    submittingMessageForms.add(form);
    const wasReadOnly = input.readOnly;
    input.readOnly = true;
    const quoteId = String(quoteInput?.value || '');
    const ids = new Set(attachments.map(item => item.id));
    const statusTimer = setTimeout(() => {
        if (isCurrentChatView(chat, form)) status.textContent = uiText('message_sending');
    }, 1000);
    let result;
    let timedOut = false;
    try {
        result = await postForm('/api/messages', {
            csrf_token: getCsrfToken(), chat_id: String(chat.id), content,
            quoted_message_id: quoteId, attachment_ids: [...ids].join(','),
        }, { timeoutMs: 15000 });
    } catch (error) {
        timedOut = error?.name === 'TimeoutError';
    } finally {
        clearTimeout(statusTimer);
        input.readOnly = wasReadOnly;
        submittingMessageForms.delete(form);
    }
    if (!isCurrentChatView(chat, form)) return;

    if (result?.success !== true || Object.hasOwn(result, 'raw')) {
        previews.delete(entry);
        preview.remove();
        if (timedOut && errorBox) {
            errorBox.classList.add('message-send-timeout');
            errorBox.classList.remove('hidden');
            errorBox.textContent = uiText('message_send_timeout');
        } else if (errorBox && ['attachment_upload_busy', 'attachment_unavailable', 'attachment_batch_too_large'].includes(result?.error)) {
            errorBox.textContent = uiText(result.error);
            errorBox.classList.remove('hidden');
        } else {
            showToast(uiActionError(result?.error, 'failed_to_send_message'), 'error');
        }
        return;
    }

    const messageId = Number(result.message_id);
    if (Number.isSafeInteger(messageId) && messageId > 0) {
        entry.messageId = messageId;
        status.remove();
    } else {
        previews.delete(entry);
        preview.remove();
    }
    if (input.value === draft) {
        input.value = '';
        typingActive = false;
        clearTypingStopTimer();
        void sendTypingStatus(false).catch(() => {});
    }
    pendingAttachments = pendingAttachments.filter(item => !ids.has(item.id));
    renderPendingAttachmentList();
    if (String(quoteInput?.value || '') === quoteId) clearQuoteDraft();
    void pollMessages({ scrollMode: 'bottom' }).catch(() => {});
    void loadSidebarChats().catch(() => {});
}

async function pollMessages(options = {}) {
    const chat = currentChat;
    const messagesList = document.getElementById('messages');
    if (!chat || !messagesList) return;
    if (options.background && backgroundMessagePolls.has(chat)) return;
    if (options.background) backgroundMessagePolls.add(chat);
    const rendered = renderedMessageTimelines.get(messagesList);
    const knownRevision = !options.forceRender && rendered?.chat === chat ? rendered.revision : null;
    const sequence = ++messageRefreshSequence;
    const bannerRevision = chatBannerRevision;
    let data;
    try {
        const res = await fetch(appPath(`/api/messages/${chat.id}`), knownRevision
            ? { headers: { 'X-Causette-Timeline': knownRevision } } : undefined);
        if (!res.ok) return;
        data = await res.json();
    } finally {
        if (options.background) backgroundMessagePolls.delete(chat);
    }
    if (currentChat !== chat || document.getElementById('messages') !== messagesList
        || sequence < lastAppliedMessageRefresh) return;
    const messages = Array.isArray(data?.messages) ? data.messages : null;
    const revision = typeof data?.timeline_revision === 'string' && /^[a-f0-9]{64}$/.test(data.timeline_revision)
        ? data.timeline_revision : null;
    const latestRendered = renderedMessageTimelines.get(messagesList);
    if (!messages && (!knownRevision || revision !== knownRevision
        || latestRendered?.chat !== chat || latestRendered?.revision !== revision)) return;
    lastAppliedMessageRefresh = sequence;
    const typingUsers = Array.isArray(data.typing_users) ? data.typing_users : [];
    const pinnedMessage = normalizePinnedMessage(data.pinned_message);
    const activePoll = normalizeActivePoll(data.active_poll);
    if (currentChat && data.group_edit_window !== undefined) {
        currentChat.group_edit_window = String(data.group_edit_window || 'never');
    }
    if (currentChat && data.group_delete_window !== undefined) {
        currentChat.group_delete_window = String(data.group_delete_window || 'never');
    }
    const hasCanSendMessage = Object.prototype.hasOwnProperty.call(data, 'can_send_message');
    const nextRestrictionReason = Object.prototype.hasOwnProperty.call(data, 'can_send_message_reason')
        ? String(data.can_send_message_reason || '')
        : String(currentChat?.message_restriction_reason || '');
    if (currentChat && hasCanSendMessage) {
        currentChat.can_send_messages = Boolean(data.can_send_message);
        currentChat.message_restriction_reason = nextRestrictionReason;
    }
    if (messages) {
        const signature = revision ?? buildMessagesSignature(messages);
        const changed = options.forceRender || latestRendered?.chat !== chat || latestRendered.signature !== signature;
        // A revision can be reused only once the matching timeline is visible.
        const awaitingSend = [...(outgoingMessagePreviews.get(messagesList) || [])].some(entry => entry.messageId === null);
        if (changed && !awaitingSend && !messagesList.querySelector('.js-edit-input')) {
            renderMessages(messages, { scrollMode: options.scrollMode || 'preserve' });
            renderedMessageTimelines.set(messagesList, { chat, signature, revision });
        }
        updatePersonalChatHeaderStatusFromMessages(messages);
    }

    renderTypingIndicator(typingUsers);
    // A refresh already in flight may predate a successful pin or poll action.
    if (bannerRevision === chatBannerRevision) {
        chat.pinned_message = pinnedMessage;
        chat.active_poll = activePoll;
        renderPinnedMessageBanner(pinnedMessage);
        renderActivePollBanner(activePoll);
    }
    refreshChatCallStatusBar();

    if (hasCanSendMessage) {
        setChatComposerEnabled(Boolean(data.can_send_message), nextRestrictionReason);
    }

    if (Object.prototype.hasOwnProperty.call(data, 'can_start_call')) {
        setChatCallEnabled(Boolean(data.can_start_call));
    }

    if (Object.prototype.hasOwnProperty.call(data, 'user_in_active_call')) {
        setChatUserInActiveCall(Boolean(data.user_in_active_call));
    }
}

function buildMessagesSignature(messages) {
    if (!Array.isArray(messages) || messages.length === 0) {
        return 'empty';
    }

    return messages.map((msg) => {
        if (msg?.is_system_event) {
            return `sys|${Number(msg.id || 0)}|${String(msg.created_at || '')}|${String(msg.content || '')}`;
        }

        const messageId = Number(msg?.id || 0);
        const content = String(msg?.content || '');
        const createdAt = String(msg?.created_at || '');
        const quotedMessageId = Number(msg?.quoted_message_id || 0);
        const mentionMap = normalizeMentionMap(msg?.mention_map || {});
        const mentionMapKeys = Object.keys(mentionMap).sort();
        const mentionMapFingerprint = mentionMapKeys.map((key) => `${key}:${mentionMap[key]}`).join(',');

        const attachmentFingerprint = Array.isArray(msg?.attachments)
            ? msg.attachments
                .map((attachment) => normalizeAttachment(attachment))
                .filter(Boolean)
                .map((attachment) => `${attachment.id}:${attachment.file_size}`)
                .join(',')
            : '';

        const reactionFingerprint = Array.isArray(msg?.reactions)
            ? msg.reactions
                .map((reaction) => {
                    const code = normalizeReactionCode(reaction?.reaction_code || '');
                    const count = Number(reaction?.count || 0);
                    const reacted = Number(reaction?.reacted_by_current_user || 0);
                    return `${code}:${count}:${reacted}`;
                })
                .filter(Boolean)
                .sort()
                .join(',')
            : '';

        const editedAt = String(msg?.edited_at || '');

        return [
            messageId,
            createdAt,
            quotedMessageId,
            content,
            mentionMapFingerprint,
            attachmentFingerprint,
            reactionFingerprint,
            editedAt
        ].join('|');
    }).join('\n');
}

function getSystemEventExpandedGroupSet(chatId) {
    const safeChatId = Number(chatId || 0);
    if (!Number.isFinite(safeChatId) || safeChatId <= 0) {
        return new Set();
    }

    if (!expandedSystemEventGroupsByChat.has(safeChatId)) {
        expandedSystemEventGroupsByChat.set(safeChatId, new Set());
    }

    return expandedSystemEventGroupsByChat.get(safeChatId);
}

function isSystemEventGroupExpanded(chatId, groupKey) {
    if (!groupKey) return false;
    return getSystemEventExpandedGroupSet(chatId).has(String(groupKey));
}

function setSystemEventGroupExpanded(chatId, groupKey, expanded) {
    const safeGroupKey = String(groupKey || '');
    if (!safeGroupKey) return;

    const expandedSet = getSystemEventExpandedGroupSet(chatId);
    if (expanded) {
        expandedSet.add(safeGroupKey);
    } else {
        expandedSet.delete(safeGroupKey);
    }
}

function pruneSystemEventExpandedGroups(chatId, visibleGroupKeys) {
    const expandedSet = getSystemEventExpandedGroupSet(chatId);
    if (expandedSet.size === 0) return;

    const visibleKeys = visibleGroupKeys instanceof Set ? visibleGroupKeys : new Set();
    Array.from(expandedSet).forEach((key) => {
        if (!visibleKeys.has(key)) {
            expandedSet.delete(key);
        }
    });
}

function buildSystemEventGroupKey(chatId, runMessages) {
    const safeChatId = Number(chatId || 0);
    const firstMessage = runMessages[0] || {};
    const lastMessage = runMessages[runMessages.length - 1] || {};
    const firstId = Number(firstMessage?.id || 0);
    const lastId = Number(lastMessage?.id || 0);
    const firstTimestamp = String(firstMessage?.created_at || '');
    const lastTimestamp = String(lastMessage?.created_at || '');
    const rawFingerprint = `${safeChatId}|${firstId}|${lastId}|${runMessages.length}|${firstTimestamp}|${lastTimestamp}`;
    const fingerprintHash = Array.from(rawFingerprint).reduce((value, char) => ((value * 31) + char.charCodeAt(0)) >>> 0, 7);
    return `${safeChatId}-${firstId}-${lastId}-${runMessages.length}-${fingerprintHash.toString(16)}`;
}

function isSystemMessagesAutoCombineEnabled() {
    return window.SYSTEM_MESSAGES_AUTO_COMBINE_ENABLED !== false;
}

function systemEventToggleLabel(count, expanded) {
    const action = expanded ? 'hide' : 'show';
    const plural = count === 1 ? 'one' : 'other';
    return chatLabel(`system_events_${action}_${plural}`, { count });
}

function buildRenderableMessageEntries(messages, chatId) {
    const entries = [];
    const visibleSystemGroupKeys = new Set();

    if (!Array.isArray(messages) || messages.length === 0) {
        pruneSystemEventExpandedGroups(chatId, visibleSystemGroupKeys);
        return entries;
    }

    let index = 0;
    while (index < messages.length) {
        const message = messages[index];
        if (!(message?.is_system_event)) {
            entries.push({
                type: 'user',
                message,
                originalIndex: index
            });
            index += 1;
            continue;
        }

        const runStart = index;
        while (index < messages.length && (messages[index]?.is_system_event)) {
            index += 1;
        }

        const runMessages = messages.slice(runStart, index);
        const runContainsPollEvent = runMessages.some((runMessage) => {
            const eventType = String(runMessage?.event_type || '').toLowerCase().trim();
            return eventType === 'poll_created' || eventType === 'poll_expired';
        });

        if (!isSystemMessagesAutoCombineEnabled() || runMessages.length <= 2 || runContainsPollEvent) {
            runMessages.forEach((runMessage, offset) => {
                entries.push({
                    type: 'system',
                    message: runMessage,
                    originalIndex: runStart + offset,
                    groupKey: '',
                    isCollapsibleMiddle: false
                });
            });
            continue;
        }

        const groupKey = buildSystemEventGroupKey(chatId, runMessages);
        const hiddenCount = Math.max(0, runMessages.length - 2);
        const isExpanded = isSystemEventGroupExpanded(chatId, groupKey);
        visibleSystemGroupKeys.add(groupKey);

        entries.push({
            type: 'system',
            message: runMessages[0],
            originalIndex: runStart,
            groupKey,
            isCollapsibleMiddle: false
        });

        entries.push({
            type: 'system-toggle',
            groupKey,
            hiddenCount,
            expanded: isExpanded
        });

        for (let middleIndex = 1; middleIndex < runMessages.length - 1; middleIndex += 1) {
            entries.push({
                type: 'system',
                message: runMessages[middleIndex],
                originalIndex: runStart + middleIndex,
                groupKey,
                isCollapsibleMiddle: true,
                hiddenByDefault: !isExpanded
            });
        }

        entries.push({
            type: 'system',
            message: runMessages[runMessages.length - 1],
            originalIndex: index - 1,
            groupKey,
            isCollapsibleMiddle: false
        });
    }

    pruneSystemEventExpandedGroups(chatId, visibleSystemGroupKeys);
    return entries;
}

function getPersonalChatMessageRestrictionText(reason) {
    if (String(reason || '') === 'group_muted') {
        return uiText('messaging_muted');
    }

    if (String(reason || '') === 'group_members_only') {
        return uiText('messaging_join_required');
    }

    if (String(reason || '') === 'group_read_only_public') {
        return uiText('messaging_public_read_only');
    }

    if (String(reason || '') === 'banned_user') {
        return uiText('messaging_target_banned');
    }

    return uiText('messaging_friend_required');
}

function getPersonalChatMessageRestrictionToast(reason) {
    if (String(reason || '') === 'group_muted') {
        return uiText('messaging_muted');
    }

    if (String(reason || '') === 'group_members_only') {
        return uiText('messaging_join_required');
    }

    if (String(reason || '') === 'group_read_only_public') {
        return uiText('messaging_public_read_only');
    }

    if (String(reason || '') === 'banned_user') {
        return uiText('messaging_target_banned');
    }

    return uiText('messaging_friend_required');
}


function setChatComposerEnabled(enabled, restrictionReason = '') {
    const isEnabled = Boolean(enabled);
    const safeRestrictionReason = String(restrictionReason || '');

    if (currentChat) {
        currentChat.can_send_messages = isEnabled;
        currentChat.message_restriction_reason = safeRestrictionReason;
    }

    const form = document.getElementById('message-form');
    const controls = document.getElementById('message-composer-controls');
    const input = document.getElementById('message-input');
    const sendButton = form?.querySelector('button[type="submit"]');
    const attachmentsToggle = document.getElementById('attachments-toggle');
    const emojiToggle = document.getElementById('emoji-toggle');
    const notice = document.getElementById('message-disabled-notice');
    const attachmentsDrawer = document.getElementById('attachments-drawer');
    const emojiDrawer = document.getElementById('emoji-drawer');

    if (controls) {
        controls.classList.toggle('hidden', !isEnabled);
    }
    if (input) {
        input.disabled = !isEnabled;
        input.required = isEnabled;
        input.placeholder = uiText('message');
    }
    if (sendButton) {
        sendButton.disabled = !isEnabled;
    }
    if (attachmentsToggle) {
        attachmentsToggle.disabled = !isEnabled;
    }
    if (emojiToggle) {
        emojiToggle.disabled = !isEnabled;
    }
    if (notice) {
        notice.textContent = getPersonalChatMessageRestrictionText(safeRestrictionReason);
        notice.classList.toggle('hidden', isEnabled);
    }
    if (!isEnabled) {
        attachmentsDrawer?.classList.add('hidden');
        attachmentsToggle?.setAttribute('aria-expanded', 'false');
        emojiDrawer?.classList.add('hidden');
        emojiToggle?.setAttribute('aria-expanded', 'false');
    }
}

function applyPersonalChatHeaderStatus(statusDotClass, statusLabel) {
    const dot = document.getElementById('chat-title-status-dot');
    if (!dot) return;

    dot.classList.remove(...CHAT_STATUS_DOT_CLASSES);
    dot.classList.add(String(statusDotClass || 'bg-zinc-500'));
    dot.setAttribute('title', statusLabel || presenceLabel('offline'));
}

function updatePersonalChatHeaderStatusFromMessages(messages) {
    if (normalizeChatType(currentChat?.type) !== 'personal') return;

    const personalUserId = Number(currentChat?.personal_user_id || 0);
    if (!Number.isFinite(personalUserId) || personalUserId <= 0 || !Array.isArray(messages)) {
        refreshPersonalChatHeaderStatusFallback();
        return;
    }

    for (let index = messages.length - 1; index >= 0; index -= 1) {
        const message = messages[index];
        if (Number(message?.user_id) !== personalUserId) {
            continue;
        }

        applyPersonalChatHeaderStatus(message?.effective_status_dot_class, presenceLabel(message?.effective_status));
        return;
    }

    refreshPersonalChatHeaderStatusFallback();
}

async function refreshPersonalChatHeaderStatusFallback() {
    if (normalizeChatType(currentChat?.type) !== 'personal') return;
    if (personalStatusFallbackInFlight) return;

    const now = Date.now();
    if (now - lastPersonalStatusFallbackAt < CHAT_STATUS_FALLBACK_POLL_MS) {
        return;
    }

    personalStatusFallbackInFlight = true;
    lastPersonalStatusFallbackAt = now;

    try {
        const response = await fetch(appPath('/api/chats'));
        const payload = await response.json();
        const chats = Array.isArray(payload?.chats) ? payload.chats : [];
        const currentChatNumber = String(currentChat?.chat_number || '').replace(/\D/g, '');

        if (!currentChatNumber) {
            return;
        }

        const chat = chats.find((item) => {
            const type = normalizeChatType(item?.type);
            const chatNumber = String(item?.chat_number || '').replace(/\D/g, '');
            return type === 'personal' && chatNumber === currentChatNumber;
        });

        if (!chat) {
            return;
        }

        applyPersonalChatHeaderStatus(chat.effective_status_dot_class, presenceLabel(chat.effective_status));
    } catch {
    } finally {
        personalStatusFallbackInFlight = false;
    }
}

function getMessageScrollAnchor(messageId) {
    const box = document.getElementById('messages');
    if (!box) return null;

    const safeMessageId = Number(messageId);
    if (!Number.isFinite(safeMessageId) || safeMessageId <= 0) return null;

    const target = box.querySelector(`[data-message-id="${safeMessageId}"]`);
    if (!target) return null;

    return Math.max(0, target.offsetTop - box.offsetTop - 16);
}

function focusMessageById(messageId) {
    const box = document.getElementById('messages');
    if (!box) return false;

    const safeMessageId = Number(messageId || 0);
    if (!Number.isFinite(safeMessageId) || safeMessageId <= 0) return false;

    const anchor = getMessageScrollAnchor(safeMessageId);
    if (anchor === null) return false;

    box.scrollTop = anchor;
    const target = box.querySelector(`[data-message-id="${safeMessageId}"]`);
    if (!target) return false;

    target.style.backgroundColor = 'rgba(52, 211, 153, 0.08)';
    target.style.borderRadius = '0.5rem';
    target.style.transition = 'background-color 1.5s ease';
    setTimeout(() => { target.style.backgroundColor = ''; }, 2500);
    return true;
}

function applyInitialChatScrollPosition() {
    const chatView = document.getElementById('chat-view');
    const box = document.getElementById('messages');
    if (!chatView || !box) return;

    const urlParams = new URLSearchParams(window.location.search);
    const targetMsgId = Number(urlParams.get('msg') || 0);
    if (targetMsgId > 0) {
        if (focusMessageById(targetMsgId)) {
            return;
        }
    }

    const firstUnseenMessageId = Number(chatView.dataset.firstUnseenMessageId || 0);
    const anchor = getMessageScrollAnchor(firstUnseenMessageId);

    if (anchor !== null) {
        box.scrollTop = anchor;
        return;
    }

    box.scrollTop = box.scrollHeight;
}

function renderTypingIndicator(typingUsers) {
    const box = document.getElementById('typing-indicator');
    if (!box) return;

    const names = typingUsers
        .map(user => String(user?.username || '').trim())
        .filter(Boolean)
        .slice(0, 3);

    if (names.length === 0) {
        box.classList.add('hidden');
        box.replaceChildren();
        return;
    }

    const subject = names.length === 1
        ? uiText('typing_one', { name: names[0] })
        : uiText('typing_many', { names: new Intl.ListFormat(uiLanguage()).format(names) });

    box.classList.remove('hidden');
    const subjectNode = document.createTextNode(subject);
    const dots = document.createElement('span');
    dots.className = 'typing-dots';
    dots.setAttribute('aria-hidden', 'true');

    for (let i = 0; i < 3; i += 1) {
        const dot = document.createElement('span');
        dot.textContent = '.';
        dots.appendChild(dot);
    }

    box.replaceChildren(subjectNode, dots);
}


async function sendTypingStatus(isTyping) {
    if (!currentChat) return;

    await postForm('/api/chats/typing', {
        csrf_token: getCsrfToken(),
        chat_id: String(currentChat.id),
        is_typing: isTyping ? '1' : '0'
    });
}

function clearTypingStopTimer() {
    if (typingStopTimeoutId) {
        clearTimeout(typingStopTimeoutId);
        typingStopTimeoutId = null;
    }
}

function scheduleTypingStop() {
    clearTypingStopTimer();
    typingStopTimeoutId = setTimeout(() => {
        if (!typingActive) return;
        typingActive = false;
        sendTypingStatus(false).catch(() => {});
    }, 2500);
}

function handleMessageInputTyping() {
    const input = document.getElementById('message-input');
    const hasText = Boolean(input?.value?.trim());

    if (!hasText) {
        if (typingActive) {
            typingActive = false;
            sendTypingStatus(false).catch(() => {});
        }
        clearTypingStopTimer();
        return;
    }

    const now = Date.now();
    if (!typingActive || now - lastTypingPingAt >= 2000) {
        typingActive = true;
        lastTypingPingAt = now;
        sendTypingStatus(true).catch(() => {});
    }

    scheduleTypingStop();
}

function renderTimelineTimestamp(timestamp, previous) {
    const label = formatTimelineTimestamp(timestamp, previous);
    const utc = escapeHtml(timestamp);
    return `<span class="text-zinc-500" data-timeline-time data-utc="${utc}" data-previous-utc="${escapeHtml(previous)}" title="${utc}"${label === '' ? ' hidden' : ''}>${escapeHtml(label)}</span>`;
}

function renderMessages(messages, options = {}) {
    const box = document.getElementById('messages');
    if (!box) return;

    const scrollMode = String(options.scrollMode || 'preserve');
    const previousScrollHeight = box.scrollHeight;
    const previousScrollTop = box.scrollTop;
    const previousViewportHeight = box.clientHeight;
    const distanceFromBottom = Math.max(0, previousScrollHeight - (previousScrollTop + previousViewportHeight));
    const wasNearBottom = distanceFromBottom <= MESSAGE_SCROLL_BOTTOM_THRESHOLD_PX;
    const activeChatId = Number(currentChat?.id || 0);
    const renderEntries = buildRenderableMessageEntries(messages, activeChatId);

    const clusterStarts = [];
    let prevClusterUserId = null;
    messages.forEach((msg) => {
        if (msg.is_system_event || bannerText(msg.bot_name)) {
            clusterStarts.push(false);
            prevClusterUserId = null;
            return;
        }
        const userId = Number(msg.user_id);
        clusterStarts.push(prevClusterUserId === null || prevClusterUserId !== userId);
        prevClusterUserId = userId;
    });

    const latestClusterIndexByUser = new Map();
    for (let index = messages.length - 1; index >= 0; index -= 1) {
        if (messages[index]?.is_system_event) continue;
        if (!clusterStarts[index]) continue;

        const clusterUserId = Number(messages[index]?.user_id);
        if (!latestClusterIndexByUser.has(clusterUserId)) {
            latestClusterIndexByUser.set(clusterUserId, index);
        }
    }

    const quotedMessageIds = new Set();
    messages.forEach((m) => {
        const qid = Number(m?.quoted_message_id || 0);
        if (qid > 0) quotedMessageIds.add(qid);
    });
    messages.forEach((m) => {
        if (!m?.is_system_event && m?.is_quoted === undefined) {
            m.is_quoted = quotedMessageIds.has(Number(m?.id || 0));
        }
    });

    let previousUserId = null;
    let previousWasSystemEvent = false;
    let previousTimelineTimestamp = '';
    const chunks = [];

    renderEntries.forEach((entry) => {
        if (entry?.type === 'system-toggle') {
            const groupKey = String(entry.groupKey || '');
            const hiddenCount = Math.max(0, Number(entry.hiddenCount || 0));
            const isExpanded = Boolean(entry.expanded);
            const toggleLabel = systemEventToggleLabel(hiddenCount, isExpanded);

            chunks.push(`
                <div class="flex gap-3 mt-1">
                    <div class="w-10 shrink-0"><div class="w-10 h-1"></div></div>
                    <div class="min-w-0 flex-1">
                        <button
                            type="button"
                            class="js-system-events-toggle text-xs text-zinc-500 hover:text-zinc-300"
                            data-system-event-group-key="${escapeHtml(groupKey)}"
                            data-system-event-hidden-count="${hiddenCount}"
                            aria-expanded="${isExpanded ? 'true' : 'false'}"
                        >
                            <span class="js-system-events-toggle-label">${escapeHtml(toggleLabel)}</span>
                        </button>
                    </div>
                </div>
            `);

            previousWasSystemEvent = true;
            previousUserId = null;
            return;
        }

        const msg = entry?.message;
        if (entry?.type === 'system' && msg?.is_system_event) {
            const systemEventType = String(msg?.event_type || '');
            const isPollCreatedEvent = systemEventType === 'poll_created';
            const isPollExpiredEvent = systemEventType === 'poll_expired';
            const isStandalonePollEvent = isPollCreatedEvent || isPollExpiredEvent;
            const isNewSystemCluster = isStandalonePollEvent ? true : !previousWasSystemEvent;
            const isCallSystemEvent = systemEventType.startsWith('call_');
            const sysFullTimestamp = String(msg.created_at || '');
            const groupKey = String(entry?.groupKey || '');
            const isCollapsibleMiddle = Boolean(entry?.isCollapsibleMiddle);
            const isHiddenByDefault = Boolean(entry?.hiddenByDefault);
            const earlierTimelineTimestamp = previousTimelineTimestamp;
            if (!isHiddenByDefault) previousTimelineTimestamp = sysFullTimestamp;
            const middleAttrs = isCollapsibleMiddle
                ? ` data-system-event-group-key="${escapeHtml(groupKey)}" data-system-event-hidden="1"`
                : '';

            let systemAvatarIcon = 'fa-comments';
            let systemHeaderLabel = 'Causette';
            let systemContent = String(msg.content || '');

            if (isPollCreatedEvent) {
                systemAvatarIcon = 'fa-square-poll-horizontal';

                const lines = systemContent.split(/\r?\n/);
                const firstLine = String(lines[0] || '');
                const match = firstLine.match(/^Poll created by\s+(.+?):\s*(.*)$/i);
                if (match) {
                    const creatorName = String(match[1] || '').trim();
                    const question = String(match[2] || '').trim();
                    systemHeaderLabel = chatLabel('poll_created_by', { creator: creatorName || chatLabel('unknown_user') });
                    lines[0] = question;
                    systemContent = lines.join('\n').trim();
                }
            } else if (isPollExpiredEvent) {
                systemAvatarIcon = 'fa-square-poll-horizontal';
                systemHeaderLabel = chatLabel('poll_expired');
            }

            chunks.push(`
                <div class="flex gap-3 ${isNewSystemCluster ? 'mt-4' : 'mt-1'}${isHiddenByDefault ? ' hidden' : ''}"${middleAttrs}>
                    <div class="w-10 shrink-0">
                        ${isNewSystemCluster ? `<div class="w-10 h-10 rounded-full border border-zinc-700 bg-zinc-900 text-zinc-400 flex items-center justify-center font-semibold mt-0.5"><i class="fa-solid ${escapeHtml(systemAvatarIcon)} text-xs" aria-hidden="true"></i></div>` : '<div class="w-10 h-10"></div>'}
                    </div>
                    <div class="min-w-0 flex-1">
                        ${isNewSystemCluster ? `<div class="flex items-center gap-2 mb-0.5"><span class="text-sm font-semibold leading-5 text-zinc-400">${escapeHtml(systemHeaderLabel)}</span></div>` : ''}
                        <div class="text-zinc-400 text-[15px] leading-6">${renderPlainTextWithEmoji(systemContent)}</div>
                        <div class="relative mt-0.5">
                            <div class="text-xs flex items-center gap-3">
                                ${renderTimelineTimestamp(sysFullTimestamp, earlierTimelineTimestamp)}
                            </div>
                        </div>
                    </div>
                </div>
            `);
            previousWasSystemEvent = !isStandalonePollEvent;
            previousUserId = null;
            return;
        }

        const index = Number(entry?.originalIndex || 0);

        const messageUserId = Number(msg.user_id);
        const botName = bannerText(msg.bot_name);
        const isNewGroup = Boolean(botName) || previousUserId === null || messageUserId !== previousUserId;
        const showStatus = isNewGroup
            && !botName
            && normalizeChatType(currentChat?.type) === 'group'
            && messageUserId !== Number(currentUserId)
            && latestClusterIndexByUser.get(messageUserId) === index;
        const fullTimestamp = String(msg.created_at || '');
        const earlierTimelineTimestamp = previousTimelineTimestamp;
        previousTimelineTimestamp = fullTimestamp;
        const profileUrl = botName ? '' : getProfileUrlForUser(msg);
        const username = escapeHtml(bannerText(msg.username));
        const quoteUsername = botName ? uiText('bot_name', { name: botName }) : bannerText(msg.username);
        const authorMarkup = botName
            ? `<span data-bot-badge class="shrink-0 rounded border border-zinc-700 bg-zinc-900 px-1 text-xs text-zinc-300">${escapeHtml(uiText('bot'))}</span><bdi class="min-w-0 break-words text-sm font-semibold leading-5 causette-accent">${escapeHtml(botName)}</bdi>`
            : profileUrl
                ? `<a href="${escapeHtml(profileUrl)}" class="text-sm font-bold leading-5 inline-block causette-accent hover:text-emerald-300 hover:underline underline-offset-2">${username}</a>`
                : `<div class="text-sm font-bold leading-5 inline-block causette-accent">${username}</div>`;
        const avatarMarkup = botName
            ? '<div class="w-10 h-10 rounded-full border border-zinc-700 bg-zinc-900 text-zinc-400 flex items-center justify-center mt-0.5" aria-hidden="true"><i class="fa-solid fa-robot"></i></div>'
            : isNewGroup ? renderAvatarMarkup(msg, 'w-10 h-10 mt-0.5', 'text-sm') : '';
        const statusLabel = showStatus ? presenceLabel(msg?.effective_status) : '';
        const statusDotClass = String(msg?.effective_status_dot_class || 'bg-zinc-500');
        const mentionMap = normalizeMentionMap(msg?.mention_map || {});
        const mentionMapJson = escapeHtml(JSON.stringify(mentionMap));
        const reactionBadgesMarkup = renderReactionBadgesMarkup(msg.id, Array.isArray(msg?.reactions) ? msg.reactions : []);
        const editedAt = String(msg?.edited_at || '');
        const isQuoted = !!(msg?.is_quoted);
        const hasAttachments = Array.isArray(msg?.attachments) && msg.attachments.length > 0;
        const canDeleteAttachments = canDeleteAttachmentFromMessage(msg);
        const isGroupReadOnlyViewer = normalizeChatType(currentChat?.type) === 'group' && !Boolean(currentChat?.is_group_member);
        const canReplyToMessage = !isGroupReadOnlyViewer && !isCurrentUserGroupMuted();
        const canPinMessage = !isGroupReadOnlyViewer && canCurrentUserPinMessages();
        const pinLabel = escapeHtml(chatLabel('pin'));

        let editButton = '';
        let deleteButton = '';
        if (canEditMessage(msg, isQuoted)) {
            editButton = `<button type="button" class="text-zinc-400 hover:text-zinc-300 js-edit-link" title="${escapeHtml(uiText('edit'))}" aria-label="${escapeHtml(uiText('edit'))}" data-edit-message-id="${Number(msg.id)}"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>`;
        }
        if (canDeleteMessage(msg)) {
            deleteButton = `<button type="button" class="text-zinc-400 hover:text-zinc-300 js-delete-link" title="${escapeHtml(uiText('delete'))}" aria-label="${escapeHtml(uiText('delete'))}" data-delete-message-id="${Number(msg.id)}"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>`;
        }

        const interactionControlsMarkup = isGroupReadOnlyViewer
            ? ''
            : `<div class="message-actions message-body-actions flex items-center gap-1 text-xs md:opacity-0 md:group-hover:opacity-100 md:pointer-events-none md:group-hover:pointer-events-auto md:transition-opacity md:duration-150 md:ease-out">
                                ${canReplyToMessage ? `<button type="button" class="text-zinc-400 hover:text-zinc-300 js-quote-link" title="${escapeHtml(uiText('quote'))}" aria-label="${escapeHtml(uiText('quote'))}" data-quote-message-id="${Number(msg.id)}" data-quote-username="${escapeHtml(quoteUsername)}" data-quote-content="${escapeHtml(String(msg.content || ''))}" data-quote-mention-map="${mentionMapJson}"><i class="fa-solid fa-reply" aria-hidden="true"></i></button>` : ''}
                                ${editButton}
                                ${deleteButton}
                                ${canPinMessage ? `<button type="button" class="text-zinc-400 hover:text-zinc-300 js-pin-link" title="${pinLabel}" aria-label="${pinLabel}" data-pin-message-id="${Number(msg.id)}"><i class="fa-solid fa-thumbtack" aria-hidden="true"></i></button>` : ''}
                                <button type="button" class="text-zinc-400 hover:text-zinc-300 js-react-link" title="${escapeHtml(uiText('react'))}" aria-label="${escapeHtml(uiText('react'))}" data-react-message-id="${Number(msg.id)}"><i class="fa-solid fa-face-smile" aria-hidden="true"></i></button>
                            </div>`;

        chunks.push(`
            <div class="flex gap-3 ${isNewGroup ? 'mt-4' : 'mt-1'} group" data-message-id="${Number(msg.id)}" data-message-user-id="${messageUserId}" data-message-created-at="${escapeHtml(fullTimestamp)}" data-message-is-quoted="${isQuoted ? '1' : '0'}" data-message-has-attachments="${hasAttachments ? '1' : '0'}" data-message-edited-at="${escapeHtml(editedAt)}">
                <div class="w-10 shrink-0">
                    ${avatarMarkup}
                </div>
                <div class="min-w-0 flex-1">
                    ${isNewGroup ? `<div class="flex items-center gap-2 mb-0.5">${authorMarkup}${showStatus ? `<span class="presence-dot inline-block w-1.5 h-1.5 rounded-full ${escapeHtml(statusDotClass)}" title="${escapeHtml(statusLabel)}"></span>` : ''}</div>` : ''}
                    ${renderQuotedMessageBlock(msg)}
                    <div class="message-body">
                        <div class="message-text text-zinc-200 js-message-content" data-raw-content="${escapeHtml(msg.content)}" data-mention-map="${mentionMapJson}">${renderMessageContent(msg.content, mentionMap)}</div>
                        ${interactionControlsMarkup}
                    </div>
                    ${renderMessageAttachments(msg.attachments, canDeleteAttachments)}
                    <div class="relative">
                        ${renderReactionPickerMarkup(msg.id)}
                        <div class="message-meta text-xs flex items-center gap-3">
                            ${renderTimelineTimestamp(fullTimestamp, earlierTimelineTimestamp)}
                            ${editedAt ? `<span class="text-zinc-500 italic">${escapeHtml(uiText('edited'))}</span>` : ''}
                            ${isGroupReadOnlyViewer ? '' : reactionBadgesMarkup}
                        </div>
                    </div>
                </div>
            </div>
        `);

        previousUserId = botName ? null : messageUserId;
        previousWasSystemEvent = false;
    });

    const messagesMarkup = chunks.join('');
    const fragment = document.createRange().createContextualFragment(messagesMarkup);
    const previews = outgoingMessagePreviews.get(box);
    if (previews?.size) {
        const displayedIds = new Set(messages.filter(message => !message.is_system_event).map(message => Number(message.id)));
        for (const entry of previews) {
            if (displayedIds.has(entry.messageId)) previews.delete(entry);
            else fragment.append(entry.preview);
        }
    }
    box.replaceChildren(fragment);
    if (typeof window.refreshUtcTimestamps === 'function') {
        window.refreshUtcTimestamps(box);
    }

    if (openReactionPickerMessageId > 0) {
        const picker = box.querySelector(`.js-reaction-picker[data-reaction-picker-for="${openReactionPickerMessageId}"]`);
        if (picker) {
            showReactionPalette(picker, openReactionPickerMessageId, 'message');
        } else {
            openReactionPickerMessageId = 0;
        }
    }

    if (scrollMode === 'bottom') {
        box.scrollTop = box.scrollHeight;
        return;
    }

    if (scrollMode === 'preserve' && !wasNearBottom) {
        const nextScrollTop = Math.max(0, box.scrollHeight - previousViewportHeight - distanceFromBottom);
        box.scrollTop = nextScrollTop;
        return;
    }

    box.scrollTop = box.scrollHeight;
}

function isCurrentUserGroupMuted() {
    if (!currentChat || normalizeChatType(currentChat.type) !== 'group') {
        return false;
    }

    return String(currentChat.message_restriction_reason || '') === 'group_muted';
}

function canCurrentUserPinMessages() {
    if (!currentChat) {
        return false;
    }

    if (normalizeChatType(currentChat.type) !== 'group') {
        return true;
    }

    const ownerUserId = Number(currentChat.owner_user_id || 0);
    const me = Number(currentUserId || 0);
    if (ownerUserId > 0 && ownerUserId === me) {
        return true;
    }

    return currentChat.is_group_moderator === true;
}

function isWithinWindow(windowValue, createdAt) {
    if (windowValue === 'never') return false;
    if (windowValue === 'forever') return true;
    const seconds = parseInt(windowValue, 10);
    if (isNaN(seconds) || seconds <= 0) return false;
    const messageTime = new Date(createdAt + (createdAt.includes('Z') || createdAt.includes('+') ? '' : 'Z')).getTime();
    if (isNaN(messageTime)) return false;
    return (Date.now() - messageTime) <= seconds * 1000;
}

function canEditMessage(msg, isQuoted) {
    if (!currentChat) return false;
    if (isQuoted) return false;
    const isOwn = Number(msg.user_id) === Number(currentUserId);
    const isAdmin = !!(currentChat.is_admin);
    if (!isOwn && !isAdmin) return false;
    const isPersonal = normalizeChatType(currentChat.type) === 'personal';
    if (isPersonal || isAdmin) return true;
    return isWithinWindow(currentChat.group_edit_window || 'never', String(msg.created_at || ''));
}

function canDeleteMessage(msg) {
    if (!currentChat) return false;
    if (msg?.is_quoted) return false;
    const isOwn = Number(msg.user_id) === Number(currentUserId);
    const isAdmin = !!(currentChat.is_admin);
    if (!isOwn && !isAdmin) return false;
    const isPersonal = normalizeChatType(currentChat.type) === 'personal';
    if (isPersonal || isAdmin) return true;
    return isWithinWindow(currentChat.group_delete_window || 'never', String(msg.created_at || ''));
}

function canDeleteAttachmentFromMessage(msg) {
    if (!currentChat) return false;
    const isOwn = Number(msg.user_id) === Number(currentUserId);
    const isAdmin = !!(currentChat.is_admin);
    if (!isOwn && !isAdmin) return false;
    const isPersonal = normalizeChatType(currentChat.type) === 'personal';
    if (isPersonal || isAdmin) return true;
    return isWithinWindow(currentChat.group_delete_window || 'never', String(msg.created_at || ''));
}

function bindMessageEditAndDelete() {
    const messagesContainer = document.getElementById('messages');
    if (!messagesContainer || messagesContainer.dataset.editActionsBound === '1') return;
    messagesContainer.dataset.editActionsBound = '1';

    const composer = document.getElementById('message-input');
    composer?.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowUp' || event.defaultPrevented || event.isComposing || event.repeat ||
            event.altKey || event.ctrlKey || event.metaKey || event.shiftKey || composer.value !== '' ||
            composer.disabled || composer.readOnly || messagesContainer.querySelector('.js-edit-input')) {
            return;
        }

        const messages = messagesContainer.querySelectorAll('[data-message-id][data-message-user-id]');
        for (let index = messages.length - 1; index >= 0; index -= 1) {
            const message = messages[index];
            if (Number(message.dataset.messageUserId) !== Number(currentUserId) || message.querySelector('[data-bot-badge]')) {
                continue;
            }
            const editButton = message.querySelector('.js-edit-link');
            if (editButton) {
                event.preventDefault();
                editButton.click();
            }
            return;
        }
    });

    messagesContainer.addEventListener('click', (event) => {
        const editBtn = event.target.closest('.js-edit-link');
        if (editBtn) {
            event.preventDefault();
            const messageId = Number(editBtn.dataset.editMessageId || 0);
            if (messageId > 0) startEditMessage(messageId);
            return;
        }

        const deleteBtn = event.target.closest('.js-delete-link');
        if (deleteBtn) {
            event.preventDefault();
            const messageId = Number(deleteBtn.dataset.deleteMessageId || 0);
            if (messageId > 0) promptDeleteMessage(messageId);
            return;
        }

        const attachmentDeleteBtn = event.target.closest('.js-attachment-delete');
        if (attachmentDeleteBtn) {
            event.preventDefault();
            const attachmentId = Number(attachmentDeleteBtn.dataset.attachmentId || 0);
            if (attachmentId > 0) deleteSubmittedAttachment(attachmentId);
            return;
        }

        const saveBtn = event.target.closest('.js-edit-save');
        if (saveBtn) {
            event.preventDefault();
            const messageId = Number(saveBtn.dataset.editMessageId || 0);
            if (messageId > 0) submitEditMessage(messageId);
            return;
        }

        const cancelBtn = event.target.closest('.js-edit-cancel');
        if (cancelBtn) {
            event.preventDefault();
            const messageId = Number(cancelBtn.dataset.editMessageId || 0);
            if (messageId > 0) cancelEditMessage(messageId);
            return;
        }
    });
}

async function deleteSubmittedAttachment(attachmentId) {
    try {
        const result = await postForm('/api/attachments/delete', {
            csrf_token: getCsrfToken(),
            attachment_id: attachmentId
        });

        if (result.error) {
            showToast(result.error, 'error');
            return;
        }

        await pollMessages({ scrollMode: 'preserve', forceRender: true });
    } catch (error) {
        showToast(uiText('failed_to_delete_attachment'), 'error');
    }
}

function startEditMessage(messageId) {
    const messageDiv = document.querySelector(`[data-message-id="${messageId}"]`);
    if (!messageDiv) return;
    const existingInput = messageDiv.querySelector('.js-edit-input');
    if (existingInput) {
        existingInput.focus();
        return;
    }
    const contentDiv = messageDiv.querySelector('.js-message-content');
    if (!contentDiv) return;

    const rawContent = contentDiv.dataset.rawContent || '';
    contentDiv.dataset.originalContent = contentDiv.innerHTML;
    contentDiv.innerHTML = `
        <div class="flex flex-col gap-2">
            <input type="text" class="js-edit-input w-full bg-zinc-800 border border-zinc-700 rounded-xl px-4 py-2 text-zinc-100 text-[15px]" value="${escapeHtml(rawContent)}" maxlength="16384">
            <div class="flex gap-2">
                <button type="button" class="js-edit-save text-xs px-3 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white" data-edit-message-id="${messageId}">${escapeHtml(uiText('save'))}</button>
                <button type="button" class="js-edit-cancel text-xs px-3 py-1 rounded-lg bg-zinc-700 hover:bg-zinc-600 text-zinc-200" data-edit-message-id="${messageId}">${escapeHtml(uiText('cancel'))}</button>
            </div>
        </div>
    `;
    const input = contentDiv.querySelector('.js-edit-input');
    if (input) {
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
        input.addEventListener('keydown', (e) => {
            if (e.isComposing) return;
            if (e.key === 'Enter') { e.preventDefault(); submitEditMessage(messageId); }
            if (e.key === 'Escape') { e.preventDefault(); cancelEditMessage(messageId); }
        });
    }
}

async function submitEditMessage(messageId) {
    const messageDiv = document.querySelector(`[data-message-id="${messageId}"]`);
    if (!messageDiv) return;
    const input = messageDiv.querySelector('.js-edit-input');
    if (!input || input.readOnly) return;
    const newContent = input.value.trim();
    if (!newContent) {
        showToast(uiText('message_cannot_be_empty'), 'error');
        return;
    }

    let result;
    input.readOnly = true;
    try {
        result = await postForm('/api/messages/edit', {
            csrf_token: getCsrfToken(),
            message_id: messageId,
            content: newContent
        });
    } catch {
        // The common error path below keeps the draft available for another attempt.
    } finally {
        input.readOnly = false;
    }
    if (!input.isConnected) return;
    if (result?.success !== true || typeof result.raw === 'string') {
        showToast(result?.error || uiText('failed_to_edit_message'), 'error');
        input.focus();
        return;
    }
    cancelEditMessage(messageId);
    await pollMessages({ scrollMode: 'preserve', forceRender: true }).catch(() => {
        // The save succeeded. The next scheduled refresh can update the timeline.
    });
}

function cancelEditMessage(messageId) {
    const messageDiv = document.querySelector(`[data-message-id="${messageId}"]`);
    if (!messageDiv || messageDiv.querySelector('.js-edit-input')?.readOnly) return;
    const contentDiv = messageDiv.querySelector('.js-message-content');
    if (!contentDiv || contentDiv.dataset.originalContent === undefined) return;
    contentDiv.innerHTML = contentDiv.dataset.originalContent;
    delete contentDiv.dataset.originalContent;
}

function promptDeleteMessage(messageId) {
    const modal = document.getElementById('delete-message-modal');
    const idInput = document.getElementById('delete-message-id');
    if (!modal || !idInput) return;
    idInput.value = messageId;
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
}

function bindDeleteMessageModal() {
    const modal = document.getElementById('delete-message-modal');
    if (!modal) return;
    const form = document.getElementById('delete-message-form');
    const cancelBtn = document.getElementById('delete-message-cancel');
    const idInput = document.getElementById('delete-message-id');

    const closeModal = () => {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        if (idInput) idInput.value = '';
    };

    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
    });

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const messageId = Number(idInput?.value || 0);
            if (messageId <= 0) return;
            closeModal();
            try {
                const result = await postForm('/api/messages/delete', {
                    csrf_token: getCsrfToken(),
                    message_id: messageId
                });
                if (result.error) {
                    showToast(result.error, 'error');
                    return;
                }
                await pollMessages({ scrollMode: 'preserve', forceRender: true });
            } catch (err) {
                showToast(uiText('failed_to_delete_message'), 'error');
            }
        });
    }
}

function bindGroupJoinRequestButtons() {
    const requestButton = document.getElementById('request-group-join');
    const cancelButton = document.getElementById('cancel-group-join-request');

    if (requestButton) {
        requestButton.addEventListener('click', async (event) => {
            event.preventDefault();
            await requestCurrentGroupJoin();
        });
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', async (event) => {
            event.preventDefault();
            await cancelCurrentGroupJoinRequest();
        });
    }
}

function closeGroupMemberActionMenus(exceptMenuId = '') {
    const menus = document.querySelectorAll('[data-group-member-menu]');
    const toggles = document.querySelectorAll('[data-group-member-menu-toggle]');

    menus.forEach((menu) => {
        if (!(menu instanceof HTMLElement)) return;
        const shouldKeepOpen = exceptMenuId && menu.id === exceptMenuId;
        menu.classList.toggle('hidden', !shouldKeepOpen);
    });

    toggles.forEach((toggle) => {
        if (!(toggle instanceof HTMLElement)) return;
        const menuId = String(toggle.dataset.menuId || '');
        const isExpanded = Boolean(exceptMenuId && menuId === exceptMenuId);
        toggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
    });
}

function bindGroupMemberActionMenus() {
    if (document.body.dataset.groupMemberActionMenusBound === '1') return;
    document.body.dataset.groupMemberActionMenusBound = '1';

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const toggle = target.closest('[data-group-member-menu-toggle]');
        if (toggle instanceof HTMLElement) {
            event.preventDefault();
            event.stopPropagation();

            const menuId = String(toggle.dataset.menuId || '');
            if (!menuId) {
                closeGroupMemberActionMenus();
                return;
            }

            const menu = document.getElementById(menuId);
            if (!menu) {
                closeGroupMemberActionMenus();
                return;
            }

            const shouldOpen = menu.classList.contains('hidden');
            const poke = menu.querySelector('[data-app-action="poke-group-member"]');
            if (poke) {
                const member = toggle.closest('[data-group-member-in-call]');
                poke.classList.toggle('hidden', !canPokeInCurrentGroup() || member?.dataset.groupMemberInCall === '1');
            }
            const hasActions = menu.querySelector('[data-group-member-action]:not(.hidden)');
            closeGroupMemberActionMenus(shouldOpen && hasActions ? menuId : '');
            return;
        }

        const clickedAction = target.closest('[data-group-member-action]');
        if (clickedAction) {
            closeGroupMemberActionMenus();
            return;
        }

        if (target.closest('[data-group-member-menu]')) {
            return;
        }

        closeGroupMemberActionMenus();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        closeGroupMemberActionMenus();
    });
}

function bindMessageSettingsModal() {
    const modal = document.getElementById('message-settings-modal');
    if (!modal) return;
    const form = document.getElementById('message-settings-form');
    const cancelBtn = document.getElementById('message-settings-cancel');
    const editSelect = document.getElementById('message-settings-edit-window');
    const deleteSelect = document.getElementById('message-settings-delete-window');
    const visibilitySelect = document.getElementById('group-settings-visibility');

    const closeModal = () => {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
    };

    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
    });

    window.openMessageSettingsModal = () => {
        if (!currentChat) return;
        if (editSelect) editSelect.value = currentChat.group_edit_window || 'never';
        if (deleteSelect) deleteSelect.value = currentChat.group_delete_window || 'never';
        if (visibilitySelect) visibilitySelect.value = currentChat.group_visibility || 'none';
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
    };

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!currentChat) return;
            const editWindow = editSelect?.value || 'never';
            const deleteWindow = deleteSelect?.value || 'never';
            const nonMemberVisibility = visibilitySelect?.value || 'none';
            closeModal();
            try {
                const result = await postForm('/api/chats/group/message-settings', {
                    csrf_token: getCsrfToken(),
                    chat_id: currentChat.id,
                    edit_window: editWindow,
                    delete_window: deleteWindow,
                    non_member_visibility: nonMemberVisibility
                });
                if (result.error) {
                    showToast(result.error, 'error');
                    return;
                }
                currentChat.group_edit_window = editWindow;
                currentChat.group_delete_window = deleteWindow;
                currentChat.group_visibility = nonMemberVisibility;
                showToast(uiText('group_settings_saved'));
                await pollMessages({ scrollMode: 'preserve', forceRender: true });
            } catch (err) {
                showToast(uiText('failed_to_save_settings'), 'error');
            }
        });
    }
}
