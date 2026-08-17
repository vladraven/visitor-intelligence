<?php

declare(strict_types=1);

namespace VisitorIntelligence\Identity;

use VisitorIntelligence\Core\Config;

defined('ABSPATH') || exit;

final class VisitorCookie
{
    private const NAME = 'vi_vid';

    public function get(): ?string
    {
        if (empty($_COOKIE[self::NAME])) {
            return null;
        }

        $value = sanitize_text_field(wp_unslash((string) $_COOKIE[self::NAME]));

        return $this->isUuid($value) ? $value : null;
    }

    public function set(string $visitorId): void
    {
        $lifetime = (int) Config::get('visitor_cookie_lifetime', 31536000);
        $this->write(self::NAME, $visitorId, time() + $lifetime);
    }

    private function write(string $name, string $value, int $expires): void
    {
        if (!headers_sent()) {
            setcookie($name, $value, [
                'expires'  => $expires,
                'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => defined('COOKIE_DOMAIN') ? (string) COOKIE_DOMAIN : '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        $_COOKIE[$name] = $value;
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }
}