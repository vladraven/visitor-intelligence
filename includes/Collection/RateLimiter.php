<?php

declare(strict_types=1);

namespace VisitorIntelligence\Collection;

use WP_REST_Request;
use VisitorIntelligence\Core\Config;

defined('ABSPATH') || exit;

final class RateLimiter
{
    public function check(WP_REST_Request $request): bool
    {
        $key = $this->buildKey(
            $this->resolveClientIp()
        );

        $limit = (int) Config::get(
            'rate_limit_requests'
        );

        $window = (int) Config::get(
            'rate_limit_window'
        );

        $now = time();

        $data = get_transient($key);

        if (!is_array($data)) {
            set_transient(
                $key,
                [
                    'tokens' => max(0.0, $limit - 1.0),
                    'last_updated' => $now,
                ],
                $window
            );

            return true;
        }

        $tokens = isset($data['tokens'])
            && is_numeric($data['tokens'])
            ? (float) $data['tokens']
            : 0.0;

        $lastUpdated = isset($data['last_updated'])
            && is_numeric($data['last_updated'])
            ? (int) $data['last_updated']
            : $now;

        $elapsed = max(
            0,
            $now - $lastUpdated
        );

        $fillRate = $limit / $window;

        $tokens = min(
            (float) $limit,
            $tokens + ($elapsed * $fillRate)
        );

        if ($tokens < 1.0) {
            return false;
        }

        $tokens -= 1.0;

        set_transient(
            $key,
            [
                'tokens' => $tokens,
                'last_updated' => $now,
            ],
            $window
        );

        return true;
    }

    public function retryAfter(
        WP_REST_Request $request
    ): int {
        $key = $this->buildKey(
            $this->resolveClientIp()
        );

        $limit = (int) Config::get(
            'rate_limit_requests'
        );

        $window = (int) Config::get(
            'rate_limit_window'
        );

        $data = get_transient($key);

        if (!is_array($data)) {
            return 0;
        }

        $tokens = isset($data['tokens'])
            && is_numeric($data['tokens'])
            ? (float) $data['tokens']
            : 0.0;

        if ($tokens >= 1.0) {
            return 0;
        }

        $fillRate = $limit / $window;

        if ($fillRate <= 0) {
            return $window;
        }

        return max(
            1,
            (int) ceil(
                (1.0 - $tokens) / $fillRate
            )
        );
    }

    private function buildKey(
        string $ip
    ): string {
        return 'vi_rl_' . md5(
            $ip,
            true
        );
    }

    private function resolveClientIp(): string
    {
        $remoteIp = isset($_SERVER['REMOTE_ADDR'])
            ? trim((string) $_SERVER['REMOTE_ADDR'])
            : '';

        if (
            defined('VI_TRUST_PROXIES')
            && VI_TRUST_PROXIES
        ) {
            $forwardedFor = isset(
                $_SERVER['HTTP_X_FORWARDED_FOR']
            )
                ? trim((string) $_SERVER['HTTP_X_FORWARDED_FOR'])
                : '';

            if ($forwardedFor !== '') {
                $ips = array_map(
                    'trim',
                    explode(
                        ',',
                        $forwardedFor
                    )
                );

                foreach ($ips as $ip) {
                    if (
                        filter_var(
                            $ip,
                            FILTER_VALIDATE_IP
                        )
                    ) {
                        return $ip;
                    }
                }
            }
        }

        if (
            filter_var(
                $remoteIp,
                FILTER_VALIDATE_IP
            )
        ) {
            return $remoteIp;
        }

        return '0.0.0.0';
    }
}