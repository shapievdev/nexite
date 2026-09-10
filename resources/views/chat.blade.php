<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $peer?->name ?? 'Чат' }} — {{ config('app.name') }}</title>

    <link rel="manifest" href="{{ route('manifest') }}">
    <meta name="theme-color" content="#17212b">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    <link rel="icon" href="{{ asset('icons/favicon-32.png') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" sizes="192x192">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">

    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
<div class="app" id="app">
    <header class="chat-header">
        <div class="peer" id="peer-info">
            <div class="avatar" id="peer-avatar" style="--c: {{ $peer?->color ?? '#5b8def' }}">
                @if ($peer?->avatar_path)
                    <img src="{{ route('avatar', $peer) }}" alt="">
                @else
                    {{ $peer?->initials() ?? '?' }}
                @endif
            </div>
            <div class="peer-text">
                <div class="peer-name" id="peer-name">{{ $peer?->name ?? 'Собеседник не создан' }}</div>
                <div class="peer-status" id="peer-status">не в сети</div>
            </div>
        </div>

        <div class="header-actions">
            <button class="icon-btn" id="btn-search" title="Поиск (Ctrl+F)" aria-label="Поиск">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            </button>
            <button class="icon-btn" id="btn-theme" title="Сменить тему" aria-label="Тема">
                <svg viewBox="0 0 24 24" id="icon-theme"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            </button>
            <button class="icon-btn" id="btn-menu" title="Меню" aria-label="Меню">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
            </button>

            <div class="dropdown" id="menu" hidden>
                <button data-action="profile">Мой профиль</button>
                <button data-action="code">Сменить код доступа</button>
                <button data-action="push" id="menu-push">Уведомления: выкл.</button>
                <button data-action="push-test" id="menu-push-test" hidden>Прислать проверочное</button>
                <button data-action="sound" id="menu-sound">Звук: вкл.</button>
                <button data-action="install" id="menu-install" hidden>Установить приложение</button>
                <hr>
                <button data-action="clear" class="danger">Очистить переписку</button>
                <button data-action="logout" class="danger">Выйти</button>
            </div>
        </div>
    </header>

    <div class="pinned-bar" id="pinned-bar" hidden>
        <svg viewBox="0 0 24 24" class="pin-icon"><path d="M9 4h6l-1 5 3 3v2H7v-2l3-3-1-5z"/><path d="M12 14v6"/></svg>
        <div class="pinned-text" id="pinned-text"></div>
        <button class="icon-btn sm" id="pinned-unpin" title="Открепить">
            <svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
    </div>

    <div class="search-panel" id="search-panel" hidden>
        <div class="search-row">
            <input type="search" id="search-input" placeholder="Поиск по сообщениям…" autocomplete="off">
            <span class="search-count" id="search-count"></span>
            <button class="icon-btn sm" id="search-close" aria-label="Закрыть поиск">
                <svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="search-results" id="search-results"></div>
    </div>

    <main class="messages" id="scroller">
        <div class="loader" id="loader-top" hidden><span class="spinner"></span></div>
        <div class="thread" id="thread"></div>
        <div class="empty-state" id="empty-state" hidden>
            <div class="empty-emoji">👋</div>
            <p>Здесь пока пусто.<br>Напишите первое сообщение{{ $peer ? ' — '.$peer->name.' его увидит' : '' }}.</p>
        </div>
    </main>

    <button class="scroll-down" id="scroll-down" hidden aria-label="Вниз">
        <svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
        <span class="badge" id="unread-badge" hidden>0</span>
    </button>

    <div class="typing-float" id="typing-float" hidden>
        <span class="dots"><i></i><i></i><i></i></span>
        <span id="typing-text">печатает…</span>
    </div>

    <footer class="composer">
        <div class="compose-context" id="compose-context" hidden>
            <div class="ctx-bar"></div>
            <div class="ctx-body">
                <div class="ctx-title" id="ctx-title"></div>
                <div class="ctx-text" id="ctx-text"></div>
            </div>
            <button class="icon-btn sm" id="ctx-cancel" aria-label="Отменить">
                <svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="attach-preview" id="attach-preview" hidden></div>

        <div class="compose-row">
            <button class="icon-btn" id="btn-attach" title="Прикрепить файл" aria-label="Прикрепить">
                <svg viewBox="0 0 24 24"><path d="M21 11.5 12.5 20a5 5 0 0 1-7-7l8-8a3.5 3.5 0 1 1 5 5l-8 8a2 2 0 1 1-3-3l7.5-7.5"/></svg>
            </button>
            <input type="file" id="file-input" multiple hidden>

            <div class="input-wrap">
                <textarea id="input" rows="1" placeholder="Сообщение…" maxlength="8000"></textarea>
                <button class="icon-btn emoji-btn" id="btn-emoji" title="Эмодзи" aria-label="Эмодзи">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9 10h.01M15 10h.01M8.5 14.5a4.5 4.5 0 0 0 7 0"/></svg>
                </button>
            </div>

            <button class="icon-btn mic-btn" id="btn-mic" title="Голосовое сообщение" aria-label="Записать голосовое">
                <svg viewBox="0 0 24 24"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg>
            </button>

            <button class="send-btn" id="btn-send" title="Отправить (Enter)" aria-label="Отправить">
                <svg viewBox="0 0 24 24"><path d="M3.3 20.6 21.5 12 3.3 3.4l-.1 6.7L16 12 3.2 13.9z"/></svg>
            </button>
        </div>

        <div class="recorder" id="recorder" hidden>
            <button class="icon-btn danger-text" id="rec-cancel" title="Отменить">
                <svg viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>
            </button>
            <span class="rec-dot"></span>
            <span class="rec-time" id="rec-time">0:00</span>
            <div class="rec-wave" id="rec-wave"></div>
            <span class="rec-hint">Идёт запись…</span>
            <button class="send-btn" id="rec-send" title="Отправить">
                <svg viewBox="0 0 24 24"><path d="M3.3 20.6 21.5 12 3.3 3.4l-.1 6.7L16 12 3.2 13.9z"/></svg>
            </button>
        </div>
    </footer>

    <div class="app-prompt" id="app-prompt" hidden>
        <img class="ap-icon" src="{{ asset('icons/icon-192.png') }}" alt="">
        <div class="ap-body">
            <div class="ap-title" id="ap-title"></div>
            <div class="ap-text" id="ap-text"></div>
        </div>
        <div class="ap-actions">
            <button class="btn-primary ap-ok" id="ap-ok"></button>
            <button class="ap-later" id="ap-later">Позже</button>
        </div>
    </div>

    <div class="emoji-panel" id="emoji-panel" hidden></div>
    <div class="drop-overlay" id="drop-overlay" hidden><div>Отпустите файл, чтобы отправить</div></div>
</div>

<div class="ctx-menu" id="ctx-menu" hidden></div>

<div class="lightbox" id="lightbox" hidden>
    <button class="icon-btn lightbox-close" id="lightbox-close" aria-label="Закрыть">
        <svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
    <img id="lightbox-img" alt="">
    <a class="lightbox-download" id="lightbox-download" download>Скачать</a>
</div>

<div class="modal-backdrop" id="modal" hidden>
    <div class="modal" id="modal-box"></div>
</div>

<div class="toasts" id="toasts"></div>

<script>
    window.CHAT = {
        me: @json($me->toPublicArray()),
        peer: @json($peer?->toPublicArray()),
        vapidPublicKey: @json(config('services.vapid.public_key')),
        routes: {
            messages: '/api/messages',
            sync: '/api/sync',
            search: '/api/search',
            read: '/api/read',
            typing: '/api/typing',
            history: '/api/history',
            profile: '/api/profile',
            code: '/api/profile/code',
            pushSubscribe: '/api/push/subscribe',
            pushUnsubscribe: '/api/push/unsubscribe',
            pushTest: '/api/push/test',
            logout: '{{ route('logout') }}',
        },
    };
</script>
<script src="{{ asset('js/chat.js') }}?v={{ filemtime(public_path('js/chat.js')) }}"></script>
</body>
</html>
