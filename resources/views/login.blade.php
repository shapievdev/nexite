<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <title>Вход — {{ config('app.name') }}</title>

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
<body class="login-body">
<div class="login-card">
    <form method="POST" action="{{ route('login') }}" id="login-form" autocomplete="off">
        @csrf
        <div class="code-input" id="code-input">
            <input type="password" inputmode="numeric" maxlength="1" autocomplete="off" data-1p-ignore data-lpignore="true" aria-label="Код доступа, цифра 1" autofocus>
            <input type="password" inputmode="numeric" maxlength="1" autocomplete="off" data-1p-ignore data-lpignore="true" aria-label="Цифра 2">
            <input type="password" inputmode="numeric" maxlength="1" autocomplete="off" data-1p-ignore data-lpignore="true" aria-label="Цифра 3">
            <input type="password" inputmode="numeric" maxlength="1" autocomplete="off" data-1p-ignore data-lpignore="true" aria-label="Цифра 4">
        </div>
        <input type="hidden" name="code" id="code-value" value="">

        @error('code')
            <p class="login-error">{{ $message }}</p>
        @enderror

        <button type="submit" class="btn-primary btn-block">Войти</button>
    </form>
</div>

<script>
    (function () {
        const wrap = document.getElementById('code-input');
        const boxes = [...wrap.querySelectorAll('input')];
        const hidden = document.getElementById('code-value');
        const form = document.getElementById('login-form');

        const value = () => boxes.map(b => b.value).join('');

        const sync = () => { hidden.value = value(); };

        boxes.forEach((box, i) => {
            box.addEventListener('input', () => {
                box.value = box.value.replace(/\D/g, '').slice(0, 1);
                sync();
                if (box.value && i < boxes.length - 1) boxes[i + 1].focus();
                if (value().length === 4) form.requestSubmit();
            });

            box.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !box.value && i > 0) {
                    boxes[i - 1].focus();
                    boxes[i - 1].value = '';
                    sync();
                    e.preventDefault();
                }
                if (e.key === 'ArrowLeft' && i > 0) boxes[i - 1].focus();
                if (e.key === 'ArrowRight' && i < boxes.length - 1) boxes[i + 1].focus();
            });

            box.addEventListener('paste', (e) => {
                e.preventDefault();
                const digits = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 4);
                digits.split('').forEach((d, k) => { if (boxes[k]) boxes[k].value = d; });
                sync();
                boxes[Math.min(digits.length, 3)].focus();
                if (digits.length === 4) form.requestSubmit();
            });
        });

        form.addEventListener('submit', sync);
    })();
</script>
</body>
</html>
