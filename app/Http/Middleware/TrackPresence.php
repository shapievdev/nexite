<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Обновляет отметку «был в сети» на каждом запросе авторизованного пользователя,
 * но не чаще одного раза в 5 секунд, чтобы не писать в БД на каждый poll.
 */
class TrackPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = Auth::user()) {
            if ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subSeconds(5))) {
                $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            }
        }

        return $next($request);
    }
}
