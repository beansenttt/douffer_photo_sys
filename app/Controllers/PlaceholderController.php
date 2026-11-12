<?php
declare(strict_types=1);

namespace App\Controllers;

/**
 * Temporary "coming in Phase N" screens so every navigation link works
 * while the system is built phase by phase. Removed screen by screen.
 */
final class PlaceholderController
{
    public function client(string $screen, string $title, int $phase): void
    {
        $nav = ['C2' => 'availability', 'C5' => 'booking'][$screen] ?? '';
        view('partials/placeholder', [
            'title' => $title, 'screen' => $screen, 'phase' => $phase, 'back' => '/', 'navActive' => $nav,
        ], 'client');
    }

    public function admin(string $screen, string $title, int $phase): void
    {
        view('partials/placeholder', [
            'title' => $title, 'screen' => $screen, 'phase' => $phase, 'back' => '/admin',
        ], 'admin');
    }
}
