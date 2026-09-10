<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\PwaController;
use Illuminate\Support\Facades\Route;

// PWA: манифест и офлайн-заглушка доступны без авторизации
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('manifest');
Route::get('/offline', [PwaController::class, 'offline'])->name('offline');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'presence'])->group(function () {
    Route::get('/', [ChatController::class, 'index'])->name('chat');

    Route::get('/attachments/{message}', [ChatController::class, 'attachment'])->name('attachment');
    Route::get('/avatars/{user}', [ChatController::class, 'avatar'])->name('avatar');

    Route::prefix('api')->group(function () {
        Route::get('/messages', [ChatController::class, 'messages']);
        Route::post('/messages', [ChatController::class, 'store']);
        Route::patch('/messages/{message}', [ChatController::class, 'update']);
        Route::delete('/messages/{message}', [ChatController::class, 'destroy']);
        Route::post('/messages/{message}/react', [ChatController::class, 'react']);
        Route::post('/messages/{message}/pin', [ChatController::class, 'pin']);

        Route::get('/sync', [ChatController::class, 'sync']);
        Route::get('/search', [ChatController::class, 'search']);
        Route::post('/read', [ChatController::class, 'read']);
        Route::post('/typing', [ChatController::class, 'typing']);
        Route::delete('/history', [ChatController::class, 'clear']);

        Route::post('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/code', [ProfileController::class, 'changeCode']);

        Route::post('/push/subscribe', [PushController::class, 'subscribe']);
        Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe']);
        Route::post('/push/test', [PushController::class, 'test']);
    });
});
