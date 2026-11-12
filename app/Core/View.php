<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Renders PHP templates from app/Views. A view can be wrapped in a layout:
 * the layout receives the rendered view as $content plus the same variables.
 */
final class View
{
    public static function render(string $view, array $data = [], ?string $layout = null): string
    {
        $content = self::capture($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture('layouts/' . $layout, $data + ['content' => $content]);
    }

    /** Render a partial from inside another view: partial('partials/tag', [...]). */
    public static function capture(string $view, array $data = []): string
    {
        $file = APP_DIR . '/Views/' . $view . '.php';
        if (!preg_match('#^[a-z0-9_/\-]+$#i', $view) || !is_file($file)) {
            throw new \RuntimeException('View not found: ' . $view);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
