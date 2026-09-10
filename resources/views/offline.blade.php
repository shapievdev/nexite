<!DOCTYPE html>
<html lang="ru" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Нет сети — {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('icons/favicon-32.png') }}" sizes="32x32">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-emoji">📡</div>
    <h1>Нет соединения</h1>
    <p class="login-sub">
        Чат не может связаться с сервером. Проверьте интернет —
        как только связь появится, всё загрузится.
    </p>
    <button class="btn-primary btn-block" onclick="location.reload()">Повторить</button>
</div>
</body>
</html>
