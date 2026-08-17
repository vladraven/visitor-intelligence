<?php

declare(strict_types=1);

namespace VisitorIntelligence\Sessions;

use VisitorIntelligence\Core\Config;

defined('ABSPATH') || exit;

final class Sessionizer
{
    private const COOKIE = 'vi_sid';

    public function getSessionId(): ?string
    {
        if (empty($_COOKIE[self::COOKIE])) {
            return null;
        }

        $value = sanitize_text_field(wp_unslash((string) $_COOKIE[self::COOKIE]));

        return $this->isUuid($value) ? $value : null;
    }

    public function issueSessionId(): string
    {
        $id = wp_generate_uuid4();
        $this->write($id);

        return $id;
    }

    public function refresh(string $sessionId): void
    {
        $this->write($sessionId);
    }

    private function write(string $sessionId): void
    {
        $timeout = (int) Config::get('session_timeout', 1800);
        $expires = time() + $timeout;

        if (!headers_sent()) {
            setcookie(self::COOKIE, $sessionId, [
                'expires'  => $expires,
                'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => defined('COOKIE_DOMAIN') ? (string) COOKIE_DOMAIN : '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        $_COOKIE[self::COOKIE] = $sessionId;
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }
}