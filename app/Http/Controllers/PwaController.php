<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\View\View;

class PwaController extends Controller
{
    public function manifest(): Response
    {
        $manifest = [
            'id' => '/',
            'name' => config('app.name'),
            'short_name' => config('app.name'),
            'description' => 'Приватный чат на двоих',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'display_override' => ['window-controls-overlay', 'standalone'],
            'orientation' => 'any',
            'background_color' => '#0e1621',
            'theme_color' => '#17212b',
            'lang' => 'ru',
            'dir' => 'ltr',
            'categories' => ['social', 'communication'],
            'icons' => [
                [
                    'src' => asset('icons/icon-192.png'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => asset('icons/icon-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => asset('icons/icon-maskable-192.png'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
                [
                    'src' => asset('icons/icon-maskable-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];

        return response(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
            ->header('Content-Type', 'application/manifest+json; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function offline(): View
    {
        return view('offline');
    }
}
