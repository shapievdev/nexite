/* =============================================================================
 * Мессенджер на двоих — клиентская логика
 * Реальное время реализовано коротким поллингом (/api/sync), без внешних сервисов.
 * ========================================================================== */
(() => {
    'use strict';

    const CFG = window.CHAT;
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;

    const $ = (sel) => document.querySelector(sel);
    const el = (tag, cls, html) => {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (html != null) n.innerHTML = html;
        return n;
    };

    /* ---------------------------------------------------------------------
     * Состояние
     * ------------------------------------------------------------------ */
    const S = {
        me: CFG.me,
        peer: CFG.peer,
        msgs: new Map(),          // id -> данные
        nodes: new Map(),         // id -> DOM-узел
        lastId: 0,
        since: null,
        hasMore: false,
        loadingMore: false,
        atBottom: true,
        unread: 0,
        firstUnreadId: null,
        replyTo: null,
        editing: null,
        files: [],
        pinned: [],
        searchTerm: '',
        jumped: false,
        soundOn: localStorage.getItem('chat.sound') !== 'off',
        pushOn: false,
        swReg: null,
        installPrompt: null,
        promptTimer: null,
        keyboard: 0,
        pollTimer: null,
        typingSentAt: 0,
        typingStopTimer: null,
        readPending: false,
        recorder: null,
    };

    const DOM = {
        scroller: $('#scroller'),
        thread: $('#thread'),
        loaderTop: $('#loader-top'),
        empty: $('#empty-state'),
        input: $('#input'),
        send: $('#btn-send'),
        peerName: $('#peer-name'),
        peerStatus: $('#peer-status'),
        peerAvatar: $('#peer-avatar'),
        typingFloat: $('#typing-float'),
        typingText: $('#typing-text'),
        scrollDown: $('#scroll-down'),
        unreadBadge: $('#unread-badge'),
        ctxCompose: $('#compose-context'),
        ctxTitle: $('#ctx-title'),
        ctxText: $('#ctx-text'),
        attachPreview: $('#attach-preview'),
        fileInput: $('#file-input'),
        emojiPanel: $('#emoji-panel'),
        ctxMenu: $('#ctx-menu'),
        menu: $('#menu'),
        pinnedBar: $('#pinned-bar'),
        pinnedText: $('#pinned-text'),
        searchPanel: $('#search-panel'),
        searchInput: $('#search-input'),
        searchResults: $('#search-results'),
        searchCount: $('#search-count'),
        recorder: $('#recorder'),
        recTime: $('#rec-time'),
        recWave: $('#rec-wave'),
        composeRow: document.querySelector('.compose-row'),
        lightbox: $('#lightbox'),
        lightboxImg: $('#lightbox-img'),
        lightboxDl: $('#lightbox-download'),
        modal: $('#modal'),
        modalBox: $('#modal-box'),
        toasts: $('#toasts'),
        dropOverlay: $('#drop-overlay'),
    };

    /* ---------------------------------------------------------------------
     * Мелкие утилиты
     * ------------------------------------------------------------------ */

    const esc = (s) => String(s ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    const escRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    const linkify = (html) => html.replace(
        /(https?:\/\/[^\s<]+[^\s<.,:;"')\]])/g,
        (m) => `<a href="${m}" target="_blank" rel="noopener noreferrer">${m}</a>`
    );

    const highlight = (html, term) => {
        if (!term) return html;
        try {
            return html.replace(new RegExp(`(${escRe(esc(term))})`, 'gi'), '<mark>$1</mark>');
        } catch { return html; }
    };

    const pad = (n) => String(n).padStart(2, '0');

    const timeOf = (iso) => {
        const d = new Date(iso);
        return `${d.getHours()}:${pad(d.getMinutes())}`;
    };

    const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
        'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    const dayKey = (iso) => {
        const d = new Date(iso);
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    };

    const dayLabel = (iso) => {
        const d = new Date(iso);
        const today = new Date();
        const yest = new Date(); yest.setDate(today.getDate() - 1);
        if (dayKey(iso) === dayKey(today.toISOString())) return 'Сегодня';
        if (dayKey(iso) === dayKey(yest.toISOString())) return 'Вчера';
        const y = d.getFullYear() !== today.getFullYear() ? ` ${d.getFullYear()}` : '';
        return `${d.getDate()} ${MONTHS[d.getMonth()]}${y}`;
    };

    const lastSeenLabel = (iso) => {
        if (!iso) return 'не в сети';
        const d = new Date(iso);
        const diff = (Date.now() - d.getTime()) / 1000;
        if (diff < 60) return 'был(а) только что';
        if (diff < 3600) return `был(а) ${Math.floor(diff / 60)} мин назад`;
        if (dayKey(iso) === dayKey(new Date().toISOString())) return `был(а) в ${timeOf(iso)}`;
        return `был(а) ${dayLabel(iso).toLowerCase()} в ${timeOf(iso)}`;
    };

    const fileSize = (bytes) => {
        if (!bytes) return '';
        const u = ['Б', 'КБ', 'МБ', 'ГБ'];
        let i = 0, v = bytes;
        while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
        return `${v < 10 && i > 0 ? v.toFixed(1) : Math.round(v)} ${u[i]}`;
    };

    const duration = (sec) => `${Math.floor((sec || 0) / 60)}:${pad(Math.floor((sec || 0) % 60))}`;

    const toast = (text, kind) => {
        const t = el('div', `toast${kind ? ' ' + kind : ''}`, esc(text));
        DOM.toasts.append(t);
        setTimeout(() => {
            t.style.transition = 'opacity .25s';
            t.style.opacity = '0';
            setTimeout(() => t.remove(), 250);
        }, 2600);
    };

    const nameOf = (userId) => (userId === S.me.id ? 'Вы' : (S.peer?.name ?? 'Собеседник'));

    /* ---------------------------------------------------------------------
     * HTTP
     * ------------------------------------------------------------------ */

    async function api(method, url, body) {
        const opts = {
            method,
            headers: { 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        };

        if (body instanceof FormData) {
            opts.body = body;
        } else if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }

        const res = await fetch(url, opts);

        if (res.status === 401 || res.status === 419) {
            location.href = '/login';
            throw new Error('unauthenticated');
        }

        const data = await res.json().catch(() => ({}));

        if (!res.ok) {
            const msg = data.message
                || (data.errors && Object.values(data.errors)[0]?.[0])
                || 'Ошибка запроса';
            throw new Error(msg);
        }

        return data;
    }

    /** Загрузка с прогрессом (для файлов и голосовых). */
    function upload(form, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', CFG.routes.messages);
            xhr.setRequestHeader('X-CSRF-TOKEN', CSRF);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total);
            };
            xhr.onload = () => {
                let data = {};
                try { data = JSON.parse(xhr.responseText); } catch { /* noop */ }
                if (xhr.status >= 200 && xhr.status < 300) return resolve(data);
                reject(new Error(data.message
                    || (data.errors && Object.values(data.errors)[0]?.[0])
                    || `Ошибка загрузки (${xhr.status})`));
            };
            xhr.onerror = () => reject(new Error('Сеть недоступна'));
            xhr.send(form);
        });
    }

    /* ---------------------------------------------------------------------
     * Отрисовка сообщения
     * ------------------------------------------------------------------ */

    /** Детерминированная «форма волны» для голосового по id сообщения. */
    function waveBars(id, count = 34) {
        let seed = id * 9301 + 49297;
        const rnd = () => ((seed = (seed * 9301 + 49297) % 233280) / 233280);
        return Array.from({ length: count }, () => 0.25 + rnd() * 0.75);
    }

    const MEDIA_MAX_W = 360;
    const MEDIA_MAX_H = 400;

    /** Место под медиа резервируем заранее — без этого лента прыгает при загрузке. */
    function reserveBox(node, w, h) {
        if (!w || !h) return;
        const scale = Math.min(MEDIA_MAX_W / w, MEDIA_MAX_H / h, 1);
        node.style.width = `${Math.round(w * scale)}px`;
        node.style.aspectRatio = `${w} / ${h}`;
    }

    /** Если пользователь стоял внизу, догружаемое медиа не должно уводить ленту вверх. */
    const keepBottom = () => { if (S.atBottom) scrollToBottom(); };

    function renderAttachment(m) {
        const a = m.attachment;
        if (!a) return null;

        if (a.kind === 'image') {
            const wrap = el('div');
            const img = el('img', 'att-image');
            img.src = a.url;
            img.alt = a.name || '';
            img.loading = 'lazy';
            reserveBox(img, a.width, a.height);
            img.addEventListener('load', keepBottom);
            img.addEventListener('click', () => openLightbox(a));
            wrap.append(img);
            return wrap;
        }

        if (a.kind === 'video') {
            const v = el('video', 'att-video');
            v.src = a.url;
            v.controls = true;
            v.preload = 'metadata';
            reserveBox(v, a.width, a.height);
            v.addEventListener('loadedmetadata', keepBottom);
            return v;
        }

        if (a.kind === 'voice') return renderVoice(m, a);

        if (a.kind === 'audio') {
            const wrap = el('div');
            const au = el('audio', 'att-audio');
            au.src = a.url;
            au.controls = true;
            au.preload = 'none';
            wrap.append(el('div', 'fn', esc(a.name)), au);
            return wrap;
        }

        const link = el('a', 'att-file');
        link.href = `${a.url}?download=1`;
        link.append(
            el('div', 'fi', '<svg viewBox="0 0 24 24"><path d="M14 3v5h5"/><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/></svg>'),
            (() => {
                const box = el('div');
                box.append(el('div', 'fn', esc(a.name)), el('div', 'fs', fileSize(a.size)));
                return box;
            })()
        );
        return link;
    }

    function renderVoice(m, a) {
        const box = el('div', 'voice');
        const btn = el('button', 'voice-play', playIcon());
        const body = el('div', 'voice-body');
        const wave = el('div', 'voice-wave');
        const bars = waveBars(m.id);
        bars.forEach((h) => {
            const b = el('i');
            b.style.height = `${Math.round(h * 24)}px`;
            wave.append(b);
        });
        const time = el('div', 'voice-time', duration(a.duration));
        body.append(wave, time);
        box.append(btn, body);

        const audio = new Audio(a.url);
        audio.preload = 'none';

        const paint = () => {
            const total = audio.duration || a.duration || 1;
            const ratio = Math.min(audio.currentTime / total, 1);
            const active = Math.round(ratio * bars.length);
            [...wave.children].forEach((b, i) => b.classList.toggle('on', i < active));
            time.textContent = audio.paused && !audio.currentTime
                ? duration(a.duration)
                : `${duration(audio.currentTime)} / ${duration(total)}`;
        };

        btn.addEventListener('click', () => {
            document.querySelectorAll('audio.playing').forEach((x) => { if (x !== audio) x.pause(); });
            if (audio.paused) { audio.classList.add('playing'); audio.play(); } else { audio.pause(); }
        });

        wave.addEventListener('click', (e) => {
            const rect = wave.getBoundingClientRect();
            const total = audio.duration || a.duration || 0;
            if (total) audio.currentTime = ((e.clientX - rect.left) / rect.width) * total;
            paint();
        });

        audio.addEventListener('play', () => { btn.innerHTML = pauseIcon(); });
        audio.addEventListener('pause', () => { btn.innerHTML = playIcon(); });
        audio.addEventListener('ended', () => { audio.currentTime = 0; paint(); });
        audio.addEventListener('timeupdate', paint);

        return box;
    }

    const playIcon = () => '<svg viewBox="0 0 24 24" style="stroke-width:1.6"><path d="M8 5v14l11-7z" fill="currentColor"/></svg>';
    const pauseIcon = () => '<svg viewBox="0 0 24 24"><path d="M9 5v14M15 5v14" stroke-width="2.6"/></svg>';

    const tickIcon = (read) =>
        `<span class="ticks${read ? ' read' : ''}">` +
        (read
            ? '<svg viewBox="0 0 24 24"><path d="m2 13 4 4 8-9"/><path d="m11 16 1.5 1.5L22 8"/></svg>'
            : '<svg viewBox="0 0 24 24"><path d="m5 13 4 4L19 7"/></svg>') +
        '</span>';

    function buildRow(m) {
        const mine = m.user_id === S.me.id;
        const row = el('div', `row ${mine ? 'out' : 'in'}`);
        row.dataset.id = m.id;
        row.dataset.day = dayKey(m.created_at);
        row.dataset.user = m.user_id;

        // Аватар собеседника
        if (!mine) {
            const av = el('div', 'avatar');
            const author = S.peer;
            av.style.setProperty('--c', author?.color || '#5b8def');
            if (author?.avatar_url) {
                const img = el('img');
                img.src = author.avatar_url;
                av.append(img);
            } else {
                av.textContent = author?.initials || '?';
            }
            row.append(av);
        }

        const wrap = el('div', 'bubble-wrap');
        const bubble = el('div', 'bubble');

        if (m.deleted) {
            bubble.classList.add('deleted');
            bubble.textContent = 'Сообщение удалено';
            wrap.append(bubble);
            row.append(wrap);
            return row;
        }

        // Цитата
        if (m.reply_to) {
            const q = el('div', 'reply-quote');
            q.append(el('div', 'rq-bar'));
            const body = el('div');
            body.append(
                el('div', 'rq-name', esc(nameOf(m.reply_to.user_id))),
                el('div', 'rq-text', esc(m.reply_to.preview))
            );
            q.append(body);
            q.addEventListener('click', (e) => { e.stopPropagation(); jumpTo(m.reply_to.id); });
            bubble.append(q);
        }

        const att = renderAttachment(m);
        if (att) bubble.append(att);

        if (m.body) {
            const text = el('div', 'text');
            text.innerHTML = highlight(linkify(esc(m.body)), S.searchTerm);
            bubble.append(text);
        }

        // Метаданные
        const meta = el('div', 'meta');
        if (m.pinned) meta.append(el('span', null, '📌'));
        if (m.edited_at) meta.append(el('span', 'edited', 'изм.'));
        meta.append(el('time', null, timeOf(m.created_at)));
        if (mine) meta.insertAdjacentHTML('beforeend', tickIcon(!!m.read_at));
        bubble.append(meta);

        wrap.append(bubble);

        // Реакции
        if (m.reactions?.length) wrap.append(buildReactions(m));

        row.append(wrap);

        // Быстрые действия при наведении
        const actions = el('div', 'row-actions');
        actions.append(
            iconAction('Ответить', '<svg viewBox="0 0 24 24"><path d="M9 14 4 9l5-5"/><path d="M4 9h9a7 7 0 0 1 7 7v4"/></svg>', () => startReply(m.id)),
            iconAction('Ещё', '<svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>',
                (e) => { e.stopPropagation(); openContextMenu(m.id, e.clientX, e.clientY); })
        );
        row.append(actions);

        row.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            openContextMenu(m.id, e.clientX, e.clientY);
        });

        let pressTimer = null;
        row.addEventListener('touchstart', (e) => {
            pressTimer = setTimeout(() => {
                const t = e.touches[0];
                openContextMenu(m.id, t.clientX, t.clientY);
            }, 480);
        }, { passive: true });
        ['touchend', 'touchmove', 'touchcancel'].forEach((ev) =>
            row.addEventListener(ev, () => clearTimeout(pressTimer), { passive: true }));

        // Двойной клик — быстрый ответ
        row.addEventListener('dblclick', () => startReply(m.id));

        return row;
    }

    function iconAction(title, svg, handler) {
        const b = el('button', 'icon-btn', svg);
        b.title = title;
        b.addEventListener('click', handler);
        return b;
    }

    function buildReactions(m) {
        const box = el('div', 'reactions');
        m.reactions.forEach((r) => {
            const mine = r.users.includes(S.me.id);
            const chip = el('button', `reaction${mine ? ' mine' : ''}`);
            chip.append(el('span', null, r.emoji));
            if (r.users.length > 1) chip.append(el('span', 'cnt', String(r.users.length)));
            chip.title = r.users.map(nameOf).join(', ');
            chip.addEventListener('click', () => react(m.id, r.emoji));
            box.append(chip);
        });
        return box;
    }

    /* ---------------------------------------------------------------------
     * Вставка / обновление сообщений в ленте
     * ------------------------------------------------------------------ */

    function putMessage(m, { silent = false } = {}) {
        const known = S.msgs.get(m.id);
        S.msgs.set(m.id, m);
        S.lastId = Math.max(S.lastId, m.id);

        const node = buildRow(m);
        const old = S.nodes.get(m.id);

        if (old) {
            old.replaceWith(node);
            S.nodes.set(m.id, node);
            return { isNew: false };
        }

        // Вставка по возрастанию id
        let ref = null;
        for (const [id, n] of S.nodes) {
            if (id > m.id) { ref = n; break; }
        }
        if (ref) DOM.thread.insertBefore(node, ref); else DOM.thread.append(node);

        // Держим Map отсортированной, чтобы поиск точки вставки был корректным
        S.nodes.set(m.id, node);
        if (ref) {
            const sorted = [...S.nodes.entries()].sort((a, b) => a[0] - b[0]);
            S.nodes = new Map(sorted);
        }

        return { isNew: !known && !silent };
    }

    function removeStale(id) {
        const n = S.nodes.get(id);
        if (n) n.remove();
        S.nodes.delete(id);
        S.msgs.delete(id);
    }

    /** Разделители дат, группировка и линия непрочитанного. */
    function refreshDecorations() {
        DOM.thread.querySelectorAll('.day, .unread-line').forEach((n) => n.remove());

        const rows = [...DOM.thread.querySelectorAll('.row')];
        let prevDay = null;
        let prevUser = null;
        let unreadPlaced = false;

        rows.forEach((row, i) => {
            const day = row.dataset.day;
            const user = row.dataset.user;
            const id = Number(row.dataset.id);

            if (day !== prevDay) {
                const iso = S.msgs.get(id)?.created_at;
                DOM.thread.insertBefore(el('div', 'day', esc(dayLabel(iso))), row);
                prevUser = null;
            }

            if (!unreadPlaced && S.firstUnreadId && id === S.firstUnreadId
                && S.msgs.get(id)?.user_id !== S.me.id) {
                DOM.thread.insertBefore(el('div', 'unread-line', 'Непрочитанные сообщения'), row);
                unreadPlaced = true;
                prevUser = null;
            }

            row.classList.toggle('grouped', user === prevUser && day === prevDay);

            const next = rows[i + 1];
            row.classList.toggle('last', !next || next.dataset.user !== user || next.dataset.day !== day);

            prevDay = day;
            prevUser = user;
        });

        DOM.empty.hidden = rows.length > 0;
    }

    /* ---------------------------------------------------------------------
     * Прокрутка
     * ------------------------------------------------------------------ */

    const isAtBottom = () =>
        DOM.scroller.scrollHeight - DOM.scroller.scrollTop - DOM.scroller.clientHeight < 90;

    function scrollToBottom(smooth = false) {
        DOM.scroller.scrollTo({ top: DOM.scroller.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
        S.atBottom = true;
        S.unread = 0;
        updateScrollDown();
    }

    function updateScrollDown() {
        DOM.scrollDown.hidden = S.atBottom;
        DOM.unreadBadge.hidden = S.unread === 0;
        DOM.unreadBadge.textContent = String(S.unread);
        if (S.unread > 0) {
            document.title = `(${S.unread}) ${S.peer?.name ?? 'Чат'}`;
        } else {
            document.title = `${S.peer?.name ?? 'Чат'} — Мессенджер`;
        }
    }

    async function jumpTo(id) {
        let node = S.nodes.get(id);

        if (!node) {
            const data = await api('GET', `${CFG.routes.messages}?around_id=${id}`).catch(() => null);
            if (!data) return;
            DOM.thread.innerHTML = '';
            S.nodes.clear();
            data.messages.forEach((m) => putMessage(m, { silent: true }));
            S.hasMore = data.has_more;
            S.jumped = true;
            refreshDecorations();
            node = S.nodes.get(id);
        }

        if (!node) return;
        node.scrollIntoView({ block: 'center', behavior: 'smooth' });
        node.classList.remove('highlight');
        void node.offsetWidth;
        node.classList.add('highlight');
        setTimeout(() => node.classList.remove('highlight'), 1500);
    }

    /* ---------------------------------------------------------------------
     * Загрузка истории
     * ------------------------------------------------------------------ */

    async function loadInitial() {
        const data = await api('GET', CFG.routes.messages);
        S.hasMore = data.has_more;
        S.firstUnreadId = data.first_unread_id;
        data.messages.forEach((m) => putMessage(m, { silent: true }));
        refreshDecorations();
        scrollToBottom();
        markRead();
    }

    async function loadOlder() {
        if (S.loadingMore || !S.hasMore) return;
        const first = S.nodes.keys().next().value;
        if (!first) return;

        S.loadingMore = true;
        DOM.loaderTop.hidden = false;

        const prevHeight = DOM.scroller.scrollHeight;
        const prevTop = DOM.scroller.scrollTop;

        try {
            const data = await api('GET', `${CFG.routes.messages}?before_id=${first}`);
            data.messages.forEach((m) => putMessage(m, { silent: true }));
            S.hasMore = data.has_more;
            refreshDecorations();
            DOM.scroller.scrollTop = prevTop + (DOM.scroller.scrollHeight - prevHeight);
        } catch (e) {
            toast(e.message, 'error');
        } finally {
            S.loadingMore = false;
            DOM.loaderTop.hidden = true;
        }
    }

    /* ---------------------------------------------------------------------
     * Поллинг
     * ------------------------------------------------------------------ */

    async function sync() {
        try {
            const params = new URLSearchParams({
                last_id: String(S.lastId),
                // Сервер шлёт push, только когда чат не на экране.
                visible: document.visibilityState === 'visible' ? '1' : '0',
            });
            if (S.since) params.set('since', S.since);

            const data = await api('GET', `${CFG.routes.sync}?${params}`);
            S.since = data.now;

            let incoming = 0;
            const wasAtBottom = isAtBottom();

            data.messages.forEach((m) => {
                const { isNew } = putMessage(m);
                if (isNew && m.user_id !== S.me.id) incoming++;
            });

            data.updated.forEach((m) => {
                const before = S.msgs.get(m.id);
                if (!before || before.updated_at !== m.updated_at) putMessage(m, { silent: true });
            });

            if (data.messages.length || data.updated.length) refreshDecorations();

            if (data.messages.length) {
                if (wasAtBottom) {
                    scrollToBottom(true);
                } else if (incoming) {
                    S.unread += incoming;
                    S.atBottom = false;
                }
                updateScrollDown();
            }

            if (incoming) {
                notify(data.messages.filter((m) => m.user_id !== S.me.id).at(-1));
                if (document.visibilityState === 'visible' && isAtBottom()) markRead();
            }

            applyPeer(data.peer);
            applyPinned(data.pinned);
        } catch (e) {
            if (e.message !== 'unauthenticated') console.warn('sync:', e.message);
        } finally {
            const delay = document.visibilityState === 'visible' ? 1500 : 5000;
            S.pollTimer = setTimeout(sync, delay);
        }
    }

    function applyPeer(peer) {
        if (!peer) return;
        const changed = JSON.stringify(peer) !== JSON.stringify(S.peer);
        S.peer = peer;
        if (!changed) return;

        DOM.peerName.textContent = peer.name;
        DOM.peerAvatar.style.setProperty('--c', peer.color);
        DOM.peerAvatar.innerHTML = peer.avatar_url
            ? `<img src="${esc(peer.avatar_url)}" alt="">`
            : esc(peer.initials);

        // Собеседник может скрыть присутствие — тогда сервер не присылает
        // ни online, ни время последнего захода, и строку статуса не показываем.
        DOM.peerStatus.classList.toggle('online', !peer.presence_hidden && peer.online && !peer.typing);
        DOM.peerStatus.classList.toggle('typing', !!peer.typing);

        if (peer.presence_hidden) {
            DOM.peerStatus.textContent = peer.typing ? 'печатает…' : '';
        } else {
            DOM.peerStatus.textContent = peer.typing
                ? 'печатает…'
                : (peer.online ? 'в сети' : lastSeenLabel(peer.last_seen_at));
        }

        DOM.typingFloat.hidden = !peer.typing;
        DOM.typingText.textContent = `${peer.name} печатает…`;
    }

    function applyPinned(pinned) {
        S.pinned = pinned || [];
        const top = S.pinned[0];
        DOM.pinnedBar.hidden = !top;
        if (top) {
            DOM.pinnedText.innerHTML = `<b>Закреплено · ${esc(nameOf(top.user_id))}</b> — ${esc(top.preview)}`;
            DOM.pinnedBar.dataset.id = top.id;
        }
    }

    /* ---------------------------------------------------------------------
     * Отправка
     * ------------------------------------------------------------------ */

    async function send() {
        const text = DOM.input.value.trim();

        if (S.editing) {
            if (!text) return;
            try {
                const data = await api('PATCH', `${CFG.routes.messages}/${S.editing}`, { body: text });
                putMessage(data.message, { silent: true });
                refreshDecorations();
                clearCompose();
            } catch (e) { toast(e.message, 'error'); }
            return;
        }

        if (!text && S.files.length === 0) return;

        const files = S.files.slice();
        const replyTo = S.replyTo;

        clearCompose();
        stopTyping();

        try {
            if (files.length === 0) {
                const data = await api('POST', CFG.routes.messages, {
                    body: text,
                    reply_to_id: replyTo,
                });
                appendOwn(data.message);
                return;
            }

            for (let i = 0; i < files.length; i++) {
                const entry = files[i];
                const form = new FormData();
                form.append('attachment', entry.file);
                if (i === 0 && text) form.append('body', text);
                if (i === 0 && replyTo) form.append('reply_to_id', String(replyTo));
                if (entry.voice) form.append('voice', '1');
                if (entry.duration) form.append('duration', String(entry.duration));
                if (entry.width) form.append('width', String(entry.width));
                if (entry.height) form.append('height', String(entry.height));

                const data = await upload(form, (p) => {
                    if (entry.bar) entry.bar.style.width = `${Math.round(p * 100)}%`;
                });
                appendOwn(data.message);
            }
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    function appendOwn(m) {
        putMessage(m, { silent: true });
        refreshDecorations();
        scrollToBottom(true);
    }

    function clearCompose() {
        DOM.input.value = '';
        autoGrow();
        S.replyTo = null;
        S.editing = null;
        S.files = [];
        DOM.attachPreview.innerHTML = '';
        DOM.attachPreview.hidden = true;
        DOM.ctxCompose.hidden = true;
        DOM.fileInput.value = '';
        saveDraft();
    }

    /* ---------------------------------------------------------------------
     * Ответ / редактирование
     * ------------------------------------------------------------------ */

    function startReply(id) {
        const m = S.msgs.get(id);
        if (!m || m.deleted) return;
        S.editing = null;
        S.replyTo = id;
        DOM.ctxCompose.hidden = false;
        DOM.ctxTitle.textContent = `Ответ · ${nameOf(m.user_id)}`;
        DOM.ctxText.textContent = previewOf(m);
        DOM.input.focus();
    }

    function startEdit(id) {
        const m = S.msgs.get(id);
        if (!m || m.deleted || m.user_id !== S.me.id) return;
        S.replyTo = null;
        S.editing = id;
        DOM.ctxCompose.hidden = false;
        DOM.ctxTitle.textContent = 'Редактирование';
        DOM.ctxText.textContent = previewOf(m);
        DOM.input.value = m.body || '';
        autoGrow();
        DOM.input.focus();
        DOM.input.setSelectionRange(DOM.input.value.length, DOM.input.value.length);
    }

    const previewOf = (m) => {
        if (m.body) return m.body.slice(0, 120);
        return ({
            image: '📷 Фото', video: '🎬 Видео', voice: '🎤 Голосовое сообщение',
            audio: '🎵 Аудио', file: `📎 ${m.attachment?.name ?? ''}`,
        })[m.attachment?.kind] || 'Сообщение';
    };

    /* ---------------------------------------------------------------------
     * Действия над сообщением
     * ------------------------------------------------------------------ */

    async function react(id, emoji) {
        try {
            const data = await api('POST', `${CFG.routes.messages}/${id}/react`, { emoji });
            putMessage(data.message, { silent: true });
            refreshDecorations();
        } catch (e) { toast(e.message, 'error'); }
    }

    async function removeMessage(id) {
        try {
            await api('DELETE', `${CFG.routes.messages}/${id}`);
            const m = S.msgs.get(id);
            putMessage({ ...(m || {}), id, deleted: true }, { silent: true });
            if (S.pinned.some((p) => p.id === id)) applyPinned(S.pinned.filter((p) => p.id !== id));
            refreshDecorations();
        } catch (e) { toast(e.message, 'error'); }
    }

    async function togglePin(id) {
        try {
            const data = await api('POST', `${CFG.routes.messages}/${id}/pin`);
            putMessage(data.message, { silent: true });
            applyPinned(data.pinned);
            refreshDecorations();
        } catch (e) { toast(e.message, 'error'); }
    }

    async function markRead() {
        if (S.readPending) return;
        const hasUnread = [...S.msgs.values()].some((m) => m.user_id !== S.me.id && !m.read_at && !m.deleted);
        if (!hasUnread) return;

        S.readPending = true;
        try {
            await api('POST', CFG.routes.read);
            S.msgs.forEach((m) => { if (m.user_id !== S.me.id) m.read_at = m.read_at || new Date().toISOString(); });
        } catch { /* повторим на следующем цикле */ } finally {
            S.readPending = false;
        }
    }

    /* ---------------------------------------------------------------------
     * Контекстное меню
     * ------------------------------------------------------------------ */

    const QUICK = ['👍', '❤️', '😂', '😮', '😢', '🔥', '🎉', '🙏'];

    function openContextMenu(id, x, y) {
        const m = S.msgs.get(id);
        if (!m || m.deleted) return;

        closeAllPopups();
        const menu = DOM.ctxMenu;
        menu.innerHTML = '';

        const quick = el('div', 'quick');
        QUICK.forEach((e) => {
            const b = el('button', null, e);
            b.addEventListener('click', () => { react(id, e); closeAllPopups(); });
            quick.append(b);
        });
        menu.append(quick);

        const item = (label, svg, handler, cls) => {
            const b = el('button', `item${cls ? ' ' + cls : ''}`, `${svg}<span>${label}</span>`);
            b.addEventListener('click', () => { handler(); closeAllPopups(); });
            menu.append(b);
        };

        item('Ответить', '<svg viewBox="0 0 24 24"><path d="M9 14 4 9l5-5"/><path d="M4 9h9a7 7 0 0 1 7 7v4"/></svg>', () => startReply(id));

        if (m.body) {
            item('Копировать текст', '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>', () => {
                navigator.clipboard.writeText(m.body).then(() => toast('Скопировано'));
            });
        }

        if (m.attachment) {
            item('Скачать файл', '<svg viewBox="0 0 24 24"><path d="M12 3v12"/><path d="m7 12 5 5 5-5"/><path d="M5 21h14"/></svg>', () => {
                const a = document.createElement('a');
                a.href = `${m.attachment.url}?download=1`;
                a.download = m.attachment.name || '';
                a.click();
            });
        }

        if (m.user_id === S.me.id && m.body) {
            item('Изменить', '<svg viewBox="0 0 24 24"><path d="M4 20h4L20 8l-4-4L4 16z"/></svg>', () => startEdit(id));
        }

        item(m.pinned ? 'Открепить' : 'Закрепить',
            '<svg viewBox="0 0 24 24"><path d="M9 4h6l-1 5 3 3v2H7v-2l3-3-1-5z"/><path d="M12 14v6"/></svg>',
            () => togglePin(id));

        if (m.user_id === S.me.id) {
            item('Удалить', '<svg viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>',
                () => confirmModal('Удалить сообщение?', 'Оно исчезнет у обоих собеседников.', () => removeMessage(id)),
                'danger');
        }

        menu.hidden = false;
        const rect = menu.getBoundingClientRect();
        const left = Math.min(x, window.innerWidth - rect.width - 10);
        const top = Math.min(y, window.innerHeight - rect.height - 10);
        menu.style.left = `${Math.max(8, left)}px`;
        menu.style.top = `${Math.max(8, top)}px`;
    }

    function closeAllPopups() {
        DOM.ctxMenu.hidden = true;
        DOM.menu.hidden = true;
        DOM.emojiPanel.hidden = true;
    }

    /* ---------------------------------------------------------------------
     * Вложения в композере
     * ------------------------------------------------------------------ */

    function addFiles(fileList) {
        [...fileList].forEach((file) => {
            if (file.size > 25 * 1024 * 1024) {
                toast(`«${file.name}» больше 25 МБ`, 'error');
                return;
            }
            const entry = { file };
            S.files.push(entry);
            renderChip(entry);
        });
        DOM.attachPreview.hidden = S.files.length === 0;
        DOM.input.focus();
    }

    function renderChip(entry) {
        const chip = el('div', 'att-chip');
        const isImage = entry.file.type.startsWith('image/');

        if (isImage) {
            const img = el('img');
            img.src = URL.createObjectURL(entry.file);
            img.onload = () => {
                entry.width = img.naturalWidth;
                entry.height = img.naturalHeight;
            };
            chip.append(img);
        } else if (entry.file.type.startsWith('video/')) {
            // Размеры видео знает только браузер — снимаем их до отправки,
            // чтобы получатель сразу отрисовал кадр нужной формы.
            const probe = document.createElement('video');
            probe.preload = 'metadata';
            probe.src = URL.createObjectURL(entry.file);
            probe.onloadedmetadata = () => {
                entry.width = probe.videoWidth;
                entry.height = probe.videoHeight;
                entry.duration = Math.round(probe.duration) || null;
                URL.revokeObjectURL(probe.src);
            };
            chip.append(el('div', 'ac-ico', '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="13" height="14" rx="2"/><path d="m16 12 5-3v9l-5-3z"/></svg>'));
        } else {
            chip.append(el('div', 'ac-ico', '<svg viewBox="0 0 24 24"><path d="M14 3v5h5"/><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/></svg>'));
        }

        const info = el('div');
        info.append(el('div', 'ac-name', esc(entry.file.name)), el('div', 'ac-size', fileSize(entry.file.size)));
        chip.append(info);

        const bar = el('div');
        bar.style.cssText = 'position:absolute;left:0;bottom:0;height:3px;background:var(--accent);width:0;border-radius:3px;transition:width .15s';
        chip.style.position = 'relative';
        chip.append(bar);
        entry.bar = bar;

        const x = el('button', null, '<svg viewBox="0 0 24 24" style="width:15px;height:15px"><path d="M18 6 6 18M6 6l12 12"/></svg>');
        x.addEventListener('click', () => {
            S.files = S.files.filter((f) => f !== entry);
            chip.remove();
            DOM.attachPreview.hidden = S.files.length === 0;
        });
        chip.append(x);

        DOM.attachPreview.append(chip);
    }

    /* ---------------------------------------------------------------------
     * Запись голосового
     * ------------------------------------------------------------------ */

    async function startRecording() {
        if (!navigator.mediaDevices?.getUserMedia) {
            toast('Браузер не поддерживает запись звука', 'error');
            return;
        }

        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch {
            toast('Нет доступа к микрофону', 'error');
            return;
        }

        const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg']
            .find((t) => MediaRecorder.isTypeSupported(t)) || '';

        const rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
        const chunks = [];
        const startedAt = Date.now();

        rec.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };

        // Визуализация уровня сигнала
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const analyser = ctx.createAnalyser();
        analyser.fftSize = 512;
        ctx.createMediaStreamSource(stream).connect(analyser);
        const buf = new Uint8Array(analyser.frequencyBinCount);

        DOM.recWave.innerHTML = '';

        const draw = () => {
            analyser.getByteTimeDomainData(buf);
            let peak = 0;
            for (const v of buf) peak = Math.max(peak, Math.abs(v - 128) / 128);
            const b = el('i');
            b.style.height = `${Math.max(4, Math.round(peak * 26))}px`;
            DOM.recWave.append(b);
            while (DOM.recWave.childElementCount > 52) DOM.recWave.firstChild.remove();
        };

        const drawTimer = setInterval(draw, 60);
        const tick = setInterval(() => {
            DOM.recTime.textContent = duration((Date.now() - startedAt) / 1000);
        }, 200);

        const cleanup = () => {
            clearInterval(drawTimer);
            clearInterval(tick);
            stream.getTracks().forEach((t) => t.stop());
            ctx.close().catch(() => {});
            DOM.recorder.hidden = true;
            DOM.composeRow.hidden = false;
            DOM.recTime.textContent = '0:00';
            S.recorder = null;
        };

        S.recorder = {
            stop(sendIt) {
                rec.onstop = async () => {
                    const secs = Math.round((Date.now() - startedAt) / 1000);
                    cleanup();
                    if (!sendIt || secs < 1 || !chunks.length) return;

                    const blob = new Blob(chunks, { type: rec.mimeType || 'audio/webm' });
                    const ext = (rec.mimeType || 'audio/webm').includes('mp4') ? 'm4a' : 'webm';
                    const form = new FormData();
                    form.append('attachment', new File([blob], `voice-${Date.now()}.${ext}`, { type: blob.type }));
                    form.append('voice', '1');
                    form.append('duration', String(secs));
                    if (S.replyTo) form.append('reply_to_id', String(S.replyTo));

                    try {
                        const data = await upload(form);
                        appendOwn(data.message);
                        S.replyTo = null;
                        DOM.ctxCompose.hidden = true;
                    } catch (e) { toast(e.message, 'error'); }
                };
                rec.stop();
            },
        };

        rec.start();
        DOM.composeRow.hidden = true;
        DOM.recorder.hidden = false;
    }

    /* ---------------------------------------------------------------------
     * Печатает…
     * ------------------------------------------------------------------ */

    function signalTyping() {
        const now = Date.now();
        if (now - S.typingSentAt > 3000) {
            S.typingSentAt = now;
            api('POST', CFG.routes.typing, { state: true }).catch(() => {});
        }
        clearTimeout(S.typingStopTimer);
        S.typingStopTimer = setTimeout(stopTyping, 3500);
    }

    /**
     * Сообщает серверу, открыт ли чат на экране. При уходе со страницы обычный
     * fetch могут не успеть выполнить, поэтому там используем sendBeacon —
     * он доставляется, даже когда вкладку уже замораживают.
     */
    function reportPresence(visible) {
        const body = JSON.stringify({ visible, _token: CSRF });

        if (!visible && navigator.sendBeacon) {
            const ok = navigator.sendBeacon(
                CFG.routes.presence,
                new Blob([body], { type: 'application/json' })
            );
            if (ok) return;
        }

        api('POST', CFG.routes.presence, { visible }).catch(() => {});
    }

    function stopTyping() {
        clearTimeout(S.typingStopTimer);
        if (S.typingSentAt === 0) return;
        S.typingSentAt = 0;
        api('POST', CFG.routes.typing, { state: false }).catch(() => {});
    }

    /* ---------------------------------------------------------------------
     * Уведомления и звук
     * ------------------------------------------------------------------ */

    function beep() {
        if (!S.soundOn) return;
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const o = ctx.createOscillator();
            const g = ctx.createGain();
            o.type = 'sine';
            o.frequency.setValueAtTime(660, ctx.currentTime);
            o.frequency.exponentialRampToValueAtTime(990, ctx.currentTime + 0.09);
            g.gain.setValueAtTime(0.0001, ctx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.14, ctx.currentTime + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.28);
            o.connect(g).connect(ctx.destination);
            o.start();
            o.stop(ctx.currentTime + 0.3);
            setTimeout(() => ctx.close().catch(() => {}), 500);
        } catch { /* автовоспроизведение может быть запрещено */ }
    }

    function notify(m) {
        beep();
        if (!m || document.visibilityState === 'visible') return;
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        try {
            const n = new Notification(S.peer?.name ?? 'Новое сообщение', {
                body: previewOf(m),
                tag: 'chat-message',
                renotify: true,
            });
            n.onclick = () => { window.focus(); n.close(); };
        } catch { /* noop */ }
    }

    /* ---------------------------------------------------------------------
     * PWA: service worker, push-подписка, установка приложения
     * ------------------------------------------------------------------ */

    const pushSupported = () =>
        'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    function urlBase64ToUint8Array(base64) {
        const padded = (base64 + '='.repeat((4 - base64.length % 4) % 4))
            .replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(padded);
        return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
    }

    async function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return null;

        try {
            const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            await navigator.serviceWorker.ready;
            return reg;
        } catch (e) {
            console.warn('service worker:', e.message);
            return null;
        }
    }

    /** Подпись устройства: чтобы в списке подписок было понятно, что это за железка. */
    function deviceLabel() {
        const ua = navigator.userAgent;
        const browser = /Firefox\//.test(ua) ? 'Firefox'
            : /Edg\//.test(ua) ? 'Edge'
                : /OPR\//.test(ua) ? 'Opera'
                    : /Chrome\//.test(ua) ? 'Chrome'
                        : /Safari\//.test(ua) ? 'Safari' : 'Браузер';
        const os = /iPhone|iPad/.test(ua) ? 'iOS'
            : /Android/.test(ua) ? 'Android'
                : /Mac OS X/.test(ua) ? 'Mac'
                    : /Windows/.test(ua) ? 'Windows' : '';
        return os ? `${browser} на ${os}` : browser;
    }

    /**
     * Тело подписки для сервера. contentEncoding в subscription.toJSON() не входит,
     * а серверу он нужен, чтобы правильно зашифровать полезную нагрузку.
     */
    function subscriptionPayload(sub) {
        const encodings = (window.PushManager && PushManager.supportedContentEncodings) || ['aes128gcm'];

        return {
            ...sub.toJSON(),
            contentEncoding: encodings[0] || 'aes128gcm',
            label: deviceLabel(),
        };
    }

    async function enablePush() {
        if (!pushSupported()) {
            toast('Браузер не поддерживает push-уведомления', 'error');
            return false;
        }

        if (!CFG.vapidPublicKey) {
            toast('На сервере нет VAPID-ключей: php artisan chat:vapid', 'error');
            return false;
        }

        if (!isSecureContext) {
            toast('Push работает только по HTTPS (или на localhost)', 'error');
            return false;
        }

        const permission = await Notification.requestPermission();

        if (permission !== 'granted') {
            toast(permission === 'denied'
                ? 'Уведомления запрещены в настройках браузера'
                : 'Разрешение не выдано', 'error');
            return false;
        }

        const reg = S.swReg || await registerServiceWorker();
        if (!reg) {
            toast('Не удалось запустить service worker', 'error');
            return false;
        }

        try {
            let sub = await reg.pushManager.getSubscription();

            if (!sub) {
                sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(CFG.vapidPublicKey),
                });
            }

            await api('POST', CFG.routes.pushSubscribe, subscriptionPayload(sub));
            return true;
        } catch (e) {
            toast(e.message || 'Не удалось подписаться на уведомления', 'error');
            return false;
        }
    }

    async function disablePush() {
        const reg = S.swReg || (('serviceWorker' in navigator) ? await navigator.serviceWorker.ready : null);
        if (!reg) return false;

        const sub = await reg.pushManager.getSubscription();
        if (!sub) return false;

        await api('POST', CFG.routes.pushUnsubscribe, { endpoint: sub.endpoint }).catch(() => {});
        await sub.unsubscribe().catch(() => {});
        return false;
    }

    function paintPushState() {
        const btn = $('#menu-push');
        if (!btn) return;
        btn.textContent = `Уведомления: ${S.pushOn ? 'вкл.' : 'выкл.'}`;
        $('#menu-push-test').hidden = !S.pushOn;
    }

    /** Уже разрешённые подписки переотправляем на сервер — endpoint умеет протухать. */
    async function refreshPushState() {
        if (!pushSupported() || Notification.permission !== 'granted') {
            S.pushOn = false;
            paintPushState();
            return;
        }

        try {
            const reg = S.swReg || await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.getSubscription();

            if (sub) {
                await api('POST', CFG.routes.pushSubscribe, subscriptionPayload(sub));
                S.pushOn = true;
            }
        } catch { /* не критично */ }

        paintPushState();
    }

    /* ---------------------------------------------------------------------
     * Предложение установить приложение и включить уведомления
     * ------------------------------------------------------------------ */

    /** Приложение уже стоит на экране «Домой» / открыто как отдельное окно. */
    const isInstalled = () =>
        window.matchMedia?.('(display-mode: standalone)').matches
        || window.matchMedia?.('(display-mode: window-controls-overlay)').matches
        || navigator.standalone === true;

    const isIOS = () =>
        /iPad|iPhone|iPod/.test(navigator.userAgent)
        || (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);

    const SNOOZE_DAYS = 7;

    function snoozed(kind) {
        const at = Number(localStorage.getItem(`chat.prompt.${kind}`) || 0);
        return at && Date.now() - at < SNOOZE_DAYS * 864e5;
    }

    const snooze = (kind) => localStorage.setItem(`chat.prompt.${kind}`, String(Date.now()));

    /**
     * Что предложить прямо сейчас. Порядок важен: на iOS уведомления
     * работают только после добавления на экран «Домой», поэтому сначала установка.
     */
    function nextPrompt() {
        if (!isInstalled() && !snoozed('install')) {
            if (S.installPrompt) {
                return {
                    kind: 'install',
                    title: 'Установить приложение',
                    text: 'Чат откроется в своём окне, без адресной строки.',
                    ok: 'Установить',
                };
            }

            if (isIOS()) {
                return {
                    kind: 'install',
                    hint: true,
                    title: 'Добавьте чат на экран «Домой»',
                    text: 'Нажмите <svg class="ios-share" viewBox="0 0 24 24" fill="none" stroke="currentColor">'
                        + '<path d="M12 3v13"/><path d="m8 7 4-4 4 4"/>'
                        + '<path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>'
                        + ' внизу экрана → «На экран „Домой“». Без этого iPhone не показывает уведомления.',
                };
            }
        }

        if (pushSupported() && !S.pushOn && Notification.permission !== 'denied' && !snoozed('push')) {
            return {
                kind: 'push',
                title: 'Включить уведомления',
                text: 'Будете видеть новые сообщения, даже когда чат закрыт.',
                ok: 'Включить',
            };
        }

        return null;
    }

    function hidePrompt() {
        $('#app-prompt').hidden = true;
    }

    function showPrompt() {
        const box = $('#app-prompt');
        const p = nextPrompt();

        if (!p) {
            box.hidden = true;
            return;
        }

        box.dataset.kind = p.kind;
        box.classList.toggle('hint', !!p.hint);
        $('#ap-title').textContent = p.title;
        $('#ap-text').innerHTML = p.text;
        if (p.ok) $('#ap-ok').textContent = p.ok;
        box.hidden = false;
    }

    /** Не лезем сразу: даём человеку сначала увидеть переписку. */
    function schedulePrompt(delay = 4000) {
        clearTimeout(S.promptTimer);
        S.promptTimer = setTimeout(showPrompt, delay);
    }

    async function acceptPrompt() {
        const kind = $('#app-prompt').dataset.kind;
        hidePrompt();

        if (kind === 'install') {
            if (!S.installPrompt) return;
            S.installPrompt.prompt();
            const { outcome } = await S.installPrompt.userChoice;
            S.installPrompt = null;
            $('#menu-install').hidden = true;
            if (outcome !== 'accepted') snooze('install');
            // После установки логично сразу предложить уведомления.
            schedulePrompt(1500);
            return;
        }

        if (kind === 'push') {
            S.pushOn = await enablePush();
            paintPushState();
            if (S.pushOn) {
                toast('Уведомления включены');
            } else {
                snooze('push');
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Поиск
     * ------------------------------------------------------------------ */

    let searchTimer = null;

    function toggleSearch(force) {
        const show = force ?? DOM.searchPanel.hidden;
        DOM.searchPanel.hidden = !show;
        if (show) {
            DOM.searchInput.focus();
        } else {
            DOM.searchInput.value = '';
            DOM.searchResults.innerHTML = '';
            DOM.searchCount.textContent = '';
            if (S.searchTerm) {
                S.searchTerm = '';
                S.msgs.forEach((m) => putMessage(m, { silent: true }));
                refreshDecorations();
            }
        }
    }

    async function runSearch(q) {
        S.searchTerm = q.length >= 2 ? q : '';

        if (q.length < 2) {
            DOM.searchResults.innerHTML = '';
            DOM.searchCount.textContent = '';
            return;
        }

        try {
            const data = await api('GET', `${CFG.routes.search}?q=${encodeURIComponent(q)}`);
            DOM.searchResults.innerHTML = '';
            DOM.searchCount.textContent = data.results.length ? `${data.results.length} найдено` : '';

            if (!data.results.length) {
                DOM.searchResults.append(el('div', 'search-empty', 'Ничего не найдено'));
                return;
            }

            data.results.forEach((r) => {
                const item = el('div', 'search-item');
                const head = el('div', 'si-head');
                head.append(
                    el('span', null, esc(nameOf(r.user_id))),
                    el('span', null, `${dayLabel(r.created_at)}, ${timeOf(r.created_at)}`)
                );
                const body = el('div', 'si-body');
                body.innerHTML = highlight(esc(r.body || ''), q);
                item.append(head, body);
                item.addEventListener('click', () => jumpTo(r.id));
                DOM.searchResults.append(item);
            });
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    /* ---------------------------------------------------------------------
     * Модальные окна
     * ------------------------------------------------------------------ */

    function openModal(html) {
        DOM.modalBox.innerHTML = html;
        DOM.modal.hidden = false;
        setTimeout(() => DOM.modalBox.querySelector('input, textarea')?.focus(), 50);
    }

    const closeModal = () => { DOM.modal.hidden = true; DOM.modalBox.innerHTML = ''; };

    function confirmModal(title, text, onOk) {
        openModal(`
            <h3>${esc(title)}</h3>
            <p>${esc(text)}</p>
            <div class="modal-actions">
                <button class="btn-ghost" data-x="no">Отмена</button>
                <button class="btn-primary" data-x="yes" style="background:var(--danger)">Удалить</button>
            </div>
        `);
        DOM.modalBox.querySelector('[data-x=no]').onclick = closeModal;
        DOM.modalBox.querySelector('[data-x=yes]').onclick = () => { closeModal(); onOk(); };
    }

    const PALETTE = ['#5b8def', '#e0725c', '#4caf7d', '#b57edc', '#e2a03f', '#3fb6c9', '#e05c8f', '#7a8b99'];

    function openProfileModal() {
        const me = S.me;
        openModal(`
            <h3>Мой профиль</h3>
            <div class="avatar-picker">
                <div class="avatar avatar-xl" id="pf-avatar" style="--c:${esc(me.color)}">
                    ${me.avatar_url ? `<img src="${esc(me.avatar_url)}" alt="">` : esc(me.initials)}
                </div>
                <div>
                    <button class="btn-ghost" id="pf-pick">Загрузить фото</button>
                    <input type="file" accept="image/*" id="pf-file" hidden>
                    <p style="margin:8px 0 0;font-size:12px">JPG или PNG, до 4 МБ</p>
                </div>
            </div>
            <label>Имя</label>
            <input type="text" id="pf-name" maxlength="40" value="${esc(me.name)}">
            <label>О себе</label>
            <textarea id="pf-bio" maxlength="200">${esc(me.bio || '')}</textarea>
            <label>Цвет аватара</label>
            <div class="color-row" id="pf-colors">
                ${PALETTE.map((c) => `<button class="color-dot${c === me.color ? ' sel' : ''}" data-c="${c}" style="background:${c}"></button>`).join('')}
            </div>
            <label class="switch-row">
                <input type="checkbox" id="pf-hide" ${me.hide_presence ? 'checked' : ''}>
                <span>
                    <b>Скрывать, когда я в сети</b>
                    <i>Собеседник не увидит ни «в сети», ни время последнего захода.
                       Вы его статус видите по-прежнему.</i>
                </span>
            </label>
            <div class="modal-error" id="pf-error" hidden></div>
            <div class="modal-actions">
                <button class="btn-ghost" id="pf-cancel">Отмена</button>
                <button class="btn-primary" id="pf-save">Сохранить</button>
            </div>
        `);

        let color = me.color;
        let avatarFile = null;

        const box = DOM.modalBox;
        box.querySelector('#pf-colors').addEventListener('click', (e) => {
            const dot = e.target.closest('.color-dot');
            if (!dot) return;
            color = dot.dataset.c;
            box.querySelectorAll('.color-dot').forEach((d) => d.classList.toggle('sel', d === dot));
            box.querySelector('#pf-avatar').style.setProperty('--c', color);
        });

        box.querySelector('#pf-pick').onclick = () => box.querySelector('#pf-file').click();
        box.querySelector('#pf-file').onchange = (e) => {
            avatarFile = e.target.files[0];
            if (avatarFile) {
                box.querySelector('#pf-avatar').innerHTML = `<img src="${URL.createObjectURL(avatarFile)}" alt="">`;
            }
        };

        box.querySelector('#pf-cancel').onclick = closeModal;
        box.querySelector('#pf-save').onclick = async () => {
            const form = new FormData();
            form.append('name', box.querySelector('#pf-name').value.trim());
            form.append('bio', box.querySelector('#pf-bio').value.trim());
            form.append('color', color);
            form.append('hide_presence', box.querySelector('#pf-hide').checked ? '1' : '0');
            if (avatarFile) form.append('avatar', avatarFile);

            try {
                const data = await api('POST', CFG.routes.profile, form);
                S.me = data.user;
                closeModal();
                toast('Профиль обновлён');
                S.msgs.forEach((m) => putMessage(m, { silent: true }));
                refreshDecorations();
            } catch (e) {
                const err = box.querySelector('#pf-error');
                err.hidden = false;
                err.textContent = e.message;
            }
        };
    }

    function openCodeModal() {
        openModal(`
            <h3>Смена кода доступа</h3>
            <p>Код — это 4 цифры, которыми вы входите в чат.</p>
            <label>Текущий код</label>
            <input type="password" inputmode="numeric" maxlength="4" id="cd-cur" autocomplete="off">
            <label>Новый код</label>
            <input type="password" inputmode="numeric" maxlength="4" id="cd-new" autocomplete="off">
            <div class="modal-error" id="cd-error" hidden></div>
            <div class="modal-actions">
                <button class="btn-ghost" id="cd-cancel">Отмена</button>
                <button class="btn-primary" id="cd-save">Сохранить</button>
            </div>
        `);

        const box = DOM.modalBox;
        box.querySelector('#cd-cancel').onclick = closeModal;
        box.querySelector('#cd-save').onclick = async () => {
            try {
                await api('POST', CFG.routes.code, {
                    current: box.querySelector('#cd-cur').value,
                    code: box.querySelector('#cd-new').value,
                });
                closeModal();
                toast('Код обновлён');
            } catch (e) {
                const err = box.querySelector('#cd-error');
                err.hidden = false;
                err.textContent = e.message;
            }
        };
    }

    /* ---------------------------------------------------------------------
     * Панель эмодзи
     * ------------------------------------------------------------------ */

    const EMOJI = {
        'Смайлы': '😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 😉 😍 🥰 😘 😗 😜 🤪 🤨 🧐 🤓 😎 🥳 😏 😒 😞 😔 😟 😕 🙁 😣 😖 😫 😩 🥺 😢 😭 😤 😠 😡 🤬 🤯 😳 🥵 🥶 😱 😨 😰 😥 😓 🤗 🤔 🤭 🤫 🤥 😶 😐 😑 😬 🙄 😯 😦 😧 😮 😲 🥱 😴 🤤 😪 😵 🤐 🥴 🤢 🤮 🤧 😷 🤒 🤕',
        'Жесты и люди': '👍 👎 👌 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐 🖖 👋 🤝 🙏 💪 🦾 👏 🙌 👐 🤲 🤛 🤜 ✊ 👊 🫶 💅 👀 🧠 👶 🧑 👩 👨 🧓 👮 🕵️ 💂 👷 🤴 👸 🦸 🦹 🧙 🧚',
        'Сердца и символы': '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟 ✨ ⭐ 🌟 💫 ⚡ 🔥 💥 💯 ✅ ❌ ❓ ❗ ⚠️ 🔔 🔕 🎵 🎶 💤 💬 👁️‍🗨️ 🗯️ 💭',
        'Природа и еда': '🌸 🌺 🌻 🌹 🌷 🌼 🌱 🌲 🌳 🍀 🍁 🍂 🌈 ☀️ 🌤️ ⛅ 🌧️ ⛈️ ❄️ ⛄ 🌊 🌙 🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🐔 🐧 🦄 🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🫐 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🥑 🍆 🥕 🌽 🍞 🧀 🍗 🍔 🍟 🍕 🌭 🌮 🍣 🍜 🍰 🎂 🍩 🍪 🍫 🍬 ☕ 🍵 🍺 🥂 🍷',
        'Занятия и вещи': '🎉 🎊 🎁 🎈 🎄 🎃 🏆 🥇 ⚽ 🏀 🏈 🎾 🏐 🎱 🏓 🏸 🥊 🎯 🎮 🕹️ 🎲 🎸 🎹 🎤 🎧 🎬 📷 💻 📱 ⌚ 🖥️ ⌨️ 🖱️ 💡 🔦 🔑 🔒 📚 📖 ✏️ 📝 📌 📎 ✂️ 📅 ⏰ ⌛ 💰 💳 💎 🚗 ✈️ 🚀 🏠 🏢 🌍',
    };

    function buildEmojiPanel() {
        DOM.emojiPanel.innerHTML = '';
        Object.entries(EMOJI).forEach(([title, list]) => {
            DOM.emojiPanel.append(el('h4', null, esc(title)));
            const grid = el('div', 'emoji-grid');
            list.split(' ').filter(Boolean).forEach((e) => {
                const b = el('button', null, e);
                b.type = 'button';
                b.addEventListener('click', () => insertAtCursor(e));
                grid.append(b);
            });
            DOM.emojiPanel.append(grid);
        });
    }

    function insertAtCursor(text) {
        const inp = DOM.input;
        const start = inp.selectionStart ?? inp.value.length;
        const end = inp.selectionEnd ?? inp.value.length;
        inp.value = inp.value.slice(0, start) + text + inp.value.slice(end);
        inp.setSelectionRange(start + text.length, start + text.length);
        inp.focus();
        autoGrow();
        saveDraft();
    }

    /* ---------------------------------------------------------------------
     * Черновик, авторазмер, тема
     * ------------------------------------------------------------------ */

    function autoGrow() {
        DOM.input.style.height = 'auto';
        DOM.input.style.height = `${Math.min(DOM.input.scrollHeight, 168)}px`;
    }

    /**
     * Экранная клавиатура в iOS не сжимает вьюпорт — страница просто уезжает
     * под неё вместе с полем ввода. Меряем клавиатуру через visualViewport
     * и отдаём высоту в CSS, чтобы чат ужимался, а композер оставался виден.
     * В Android/Chrome вьюпорт сжимается сам, там разница выходит нулевой.
     */
    function trackKeyboard() {
        const vv = window.visualViewport;
        if (!vv) return;

        let raf = null;

        const apply = () => {
            raf = null;
            const overlap = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
            // Мелкие колебания (панель Safari) игнорируем — иначе лента дёргается.
            const kb = overlap > 80 ? Math.round(overlap) : 0;

            if (kb !== S.keyboard) {
                S.keyboard = kb;
                document.documentElement.style.setProperty('--kb', `${kb}px`);
                if (kb > 0 || S.atBottom) scrollToBottom();
            }
        };

        const schedule = () => { if (!raf) raf = requestAnimationFrame(apply); };

        vv.addEventListener('resize', schedule);
        vv.addEventListener('scroll', schedule);
        apply();
    }

    const saveDraft = () => localStorage.setItem('chat.draft', DOM.input.value);

    function applyTheme(theme) {
        document.documentElement.dataset.theme = theme;
        localStorage.setItem('chat.theme', theme);

        // Строку состояния в iOS и панель браузера в Android красит theme-color.
        // Тема переключается вручную, поэтому обновляем цвет сами — иначе,
        // например, в светлой теме получим белый текст на белой шапке.
        const panel = getComputedStyle(document.documentElement)
            .getPropertyValue('--panel').trim();
        document.querySelector('meta[name="theme-color"]')
            ?.setAttribute('content', panel || '#17212b');
    }

    /* ---------------------------------------------------------------------
     * Лайтбокс
     * ------------------------------------------------------------------ */

    function openLightbox(a) {
        DOM.lightboxImg.src = a.url;
        DOM.lightboxDl.href = `${a.url}?download=1`;
        DOM.lightboxDl.download = a.name || 'image';
        DOM.lightbox.hidden = false;
    }

    /* ---------------------------------------------------------------------
     * Обработчики событий
     * ------------------------------------------------------------------ */

    function bind() {
        // Композер
        DOM.send.addEventListener('click', send);

        DOM.input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey && !e.altKey) {
                e.preventDefault();
                send();
                return;
            }
            if (e.key === 'Escape') { clearCompose(); }
            if (e.key === 'ArrowUp' && DOM.input.value === '' && !S.editing) {
                const own = [...S.msgs.values()].filter((m) => m.user_id === S.me.id && m.body && !m.deleted);
                const last = own.at(-1);
                if (last) { e.preventDefault(); startEdit(last.id); }
            }
        });

        DOM.input.addEventListener('input', () => {
            autoGrow();
            saveDraft();
            if (DOM.input.value) signalTyping(); else stopTyping();
        });

        DOM.input.addEventListener('paste', (e) => {
            const files = [...(e.clipboardData?.files || [])];
            if (files.length) { e.preventDefault(); addFiles(files); }
        });

        $('#ctx-cancel').addEventListener('click', clearCompose);
        $('#btn-attach').addEventListener('click', () => DOM.fileInput.click());
        DOM.fileInput.addEventListener('change', (e) => addFiles(e.target.files));

        $('#btn-emoji').addEventListener('click', (e) => {
            e.stopPropagation();
            const show = DOM.emojiPanel.hidden;
            closeAllPopups();
            DOM.emojiPanel.hidden = !show;
        });
        DOM.emojiPanel.addEventListener('click', (e) => e.stopPropagation());

        // Голосовые
        $('#btn-mic').addEventListener('click', () => { if (!S.recorder) startRecording(); });
        $('#rec-send').addEventListener('click', () => S.recorder?.stop(true));
        $('#rec-cancel').addEventListener('click', () => S.recorder?.stop(false));

        // Прокрутка
        DOM.scroller.addEventListener('scroll', () => {
            S.atBottom = isAtBottom();
            if (S.atBottom) {
                S.unread = 0;
                if (document.visibilityState === 'visible') markRead();
            }
            updateScrollDown();
            if (DOM.scroller.scrollTop < 200) loadOlder();
        });

        DOM.scrollDown.addEventListener('click', async () => {
            // После перехода к найденному сообщению хвост ленты мог быть выгружен —
            // возвращаемся к последней странице целиком.
            if (S.jumped) {
                S.jumped = false;
                DOM.thread.innerHTML = '';
                S.nodes.clear();
                try { await loadInitial(); } catch (e) { toast(e.message, 'error'); }
                return;
            }
            scrollToBottom(true);
            markRead();
        });

        // Шапка
        $('#btn-search').addEventListener('click', () => toggleSearch());
        $('#search-close').addEventListener('click', () => toggleSearch(false));
        DOM.searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimer);
            const q = e.target.value.trim();
            searchTimer = setTimeout(() => runSearch(q), 280);
        });

        $('#btn-theme').addEventListener('click', () => {
            applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
        });

        $('#btn-menu').addEventListener('click', (e) => {
            e.stopPropagation();
            const show = DOM.menu.hidden;
            closeAllPopups();
            DOM.menu.hidden = !show;
        });

        DOM.menu.addEventListener('click', async (e) => {
            const btn = e.target.closest('button');
            if (!btn) return;
            closeAllPopups();

            switch (btn.dataset.action) {
                case 'profile':
                    openProfileModal();
                    break;
                case 'code':
                    openCodeModal();
                    break;
                case 'push':
                    S.pushOn = S.pushOn ? await disablePush() : await enablePush();
                    paintPushState();
                    // Выключили сами — не напоминаем об этом неделю.
                    if (!S.pushOn) snooze('push'); else hidePrompt();
                    toast(S.pushOn
                        ? 'Уведомления включены — они будут приходить, даже когда чат закрыт'
                        : 'Уведомления выключены');
                    break;
                case 'push-test':
                    try {
                        const res = await api('POST', CFG.routes.pushTest);
                        toast(res.message);
                    } catch (err) { toast(err.message, 'error'); }
                    break;
                case 'install':
                    if (!S.installPrompt) return;
                    S.installPrompt.prompt();
                    await S.installPrompt.userChoice;
                    S.installPrompt = null;
                    $('#menu-install').hidden = true;
                    break;
                case 'sound':
                    S.soundOn = !S.soundOn;
                    localStorage.setItem('chat.sound', S.soundOn ? 'on' : 'off');
                    $('#menu-sound').textContent = `Звук: ${S.soundOn ? 'вкл.' : 'выкл.'}`;
                    break;
                case 'clear':
                    confirmModal('Очистить переписку?', 'Все сообщения и файлы будут удалены у обоих участников. Отменить нельзя.', async () => {
                        try {
                            await api('DELETE', CFG.routes.history);
                            DOM.thread.innerHTML = '';
                            S.msgs.clear();
                            S.nodes.clear();
                            S.lastId = 0;
                            S.pinned = [];
                            applyPinned([]);
                            refreshDecorations();
                            toast('Переписка очищена');
                        } catch (err) { toast(err.message, 'error'); }
                    });
                    break;
                case 'logout': {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = CFG.routes.logout;
                    form.innerHTML = `<input type="hidden" name="_token" value="${CSRF}">`;
                    document.body.append(form);
                    form.submit();
                    break;
                }
            }
        });

        DOM.pinnedBar.addEventListener('click', (e) => {
            if (e.target.closest('#pinned-unpin')) {
                togglePin(Number(DOM.pinnedBar.dataset.id));
                return;
            }
            jumpTo(Number(DOM.pinnedBar.dataset.id));
        });

        // Оверлеи
        $('#lightbox-close').addEventListener('click', () => { DOM.lightbox.hidden = true; });
        DOM.lightbox.addEventListener('click', (e) => {
            if (e.target === DOM.lightbox) DOM.lightbox.hidden = true;
        });
        DOM.modal.addEventListener('click', (e) => { if (e.target === DOM.modal) closeModal(); });

        document.addEventListener('click', () => closeAllPopups());

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (!DOM.lightbox.hidden) return void (DOM.lightbox.hidden = true);
                if (!DOM.modal.hidden) return closeModal();
                if (!DOM.ctxMenu.hidden || !DOM.menu.hidden || !DOM.emojiPanel.hidden) return closeAllPopups();
                if (!DOM.searchPanel.hidden) return toggleSearch(false);
                if (S.replyTo || S.editing) return clearCompose();
            }
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
                e.preventDefault();
                toggleSearch(true);
            }
        });

        // Drag & drop
        let dragDepth = 0;
        window.addEventListener('dragenter', (e) => {
            if (![...(e.dataTransfer?.types || [])].includes('Files')) return;
            dragDepth++;
            DOM.dropOverlay.hidden = false;
        });
        window.addEventListener('dragover', (e) => e.preventDefault());
        window.addEventListener('dragleave', () => {
            if (--dragDepth <= 0) { dragDepth = 0; DOM.dropOverlay.hidden = true; }
        });
        window.addEventListener('drop', (e) => {
            e.preventDefault();
            dragDepth = 0;
            DOM.dropOverlay.hidden = true;
            if (e.dataTransfer?.files?.length) addFiles(e.dataTransfer.files);
        });

        // Фокус вкладки
        document.addEventListener('visibilitychange', () => {
            const visible = document.visibilityState === 'visible';

            // Сообщаем сразу, а не ждём следующего опроса: пока сервер думает,
            // что чат открыт, он не шлёт push.
            reportPresence(visible);

            if (visible) {
                if (isAtBottom()) { S.unread = 0; updateScrollDown(); }
                markRead();
            }
        });

        // Приложение свернули или закрыли — снимаем отметку «смотрю в чат».
        window.addEventListener('pagehide', () => reportPresence(false));

        // Установка приложения (Chrome/Edge на десктопе и Android)
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            S.installPrompt = e;
            $('#menu-install').hidden = false;
            if ($('#app-prompt').hidden) schedulePrompt(4000);
        });

        window.addEventListener('appinstalled', () => {
            S.installPrompt = null;
            $('#menu-install').hidden = true;
            hidePrompt();
            toast('Приложение установлено');
            schedulePrompt(1500);   // теперь можно предложить уведомления
        });

        $('#ap-ok').addEventListener('click', acceptPrompt);
        $('#ap-later').addEventListener('click', () => {
            snooze($('#app-prompt').dataset.kind);
            hidePrompt();
            schedulePrompt(1200);   // возможно, есть что предложить следом
        });

        window.addEventListener('beforeunload', () => {
            if (S.typingSentAt) navigator.sendBeacon?.(
                CFG.routes.typing,
                new Blob([JSON.stringify({ state: false, _token: CSRF })], { type: 'application/json' })
            );
        });
    }

    /* ---------------------------------------------------------------------
     * Старт
     * ------------------------------------------------------------------ */

    async function boot() {
        applyTheme(localStorage.getItem('chat.theme') || 'dark');
        $('#menu-sound').textContent = `Звук: ${S.soundOn ? 'вкл.' : 'выкл.'}`;

        buildEmojiPanel();
        bind();
        trackKeyboard();

        DOM.input.value = localStorage.getItem('chat.draft') || '';
        autoGrow();

        applyPeer(S.peer);

        try {
            await loadInitial();
        } catch (e) {
            toast(e.message, 'error');
        }

        sync();

        // PWA поднимаем последним — оно не должно задерживать показ переписки
        S.swReg = await registerServiceWorker();
        await refreshPushState();
        schedulePrompt();

        // Переход из уведомления: /?m=123 — подсветить нужное сообщение
        const target = Number(new URLSearchParams(location.search).get('m'));
        if (target) {
            history.replaceState(null, '', location.pathname);
            jumpTo(target);
        }
    }

    document.addEventListener('DOMContentLoaded', boot);
})();
