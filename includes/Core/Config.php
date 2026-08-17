<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core;

defined('ABSPATH') || exit;

final class Config
{
    private const OPTION_NAME = 'vi_config';

    private const DEFAULTS = [
        'enabled' => true,
        'tracking_enabled' => true,

        'respect_dnt' => true,
        'consent_required' => false,

        'retention_days' => 90,

        'session_timeout' => 1800,

        'visitor_cookie_lifetime' => 31536000,

        'batch_size' => 50,
        'batch_max_bytes' => 65536,

        'heartbeat_interval' => 10,
        'heartbeat_max_delta' => 15,

        'consent_buffer_size' => 50,

        'rate_limit_requests' => 120,
        'rate_limit_window' => 60,

        'environment' => 'production',

        'tracking_mode' => 'full',

        'logging_enabled' => true,

        'geoip_enabled' => true,
    ];

    private const TRACKING_MODES = [
        'full',
        'server_only',
    ];

    private const ENVIRONMENTS = [
        'production',
        'staging',
        'development',
        'testing',
    ];

    private static ?array $cache = null;

    public static function get(
        string $key,
        mixed $default = null
    ): mixed {
        $config = self::all();

        return array_key_exists(
            $key,
            $config
        )
            ? $config[$key]
            : $default;
    }

    public static function set(
        string $key,
        mixed $value
    ): bool {
        if (
            !array_key_exists(
                $key,
                self::DEFAULTS
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Unknown Visitor Intelligence configuration key: %s',
                    $key
                )
            );
        }

        $value = self::validate(
            $key,
            $value
        );

        $config = self::all();

        $config[$key] = $value;

        self::$cache = $config;

        return update_option(
            self::OPTION_NAME,
            $config,
            false
        );
    }

    public static function all(): array
    {
        if (
            self::$cache !== null
        ) {
            return self::$cache;
        }

        $stored = get_option(
            self::OPTION_NAME,
            []
        );

        if (
            !is_array(
                $stored
            )
        ) {
            $stored = [];
        }

        $config = array_merge(
            self::DEFAULTS,
            $stored
        );

        foreach (
            $config as $key => $value
        ) {
            if (
                !array_key_exists(
                    $key,
                    self::DEFAULTS
                )
            ) {
                unset(
                    $config[$key]
                );

                continue;
            }

            $config[$key] = self::validate(
                $key,
                $value
            );
        }

        self::$cache = $config;

        return self::$cache;
    }

    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    public static function reset(): bool
    {
        self::$cache =
            self::DEFAULTS;

        return update_option(
            self::OPTION_NAME,
            self::DEFAULTS,
            false
        );
    }

    public static function has(
        string $key
    ): bool {
        return array_key_exists(
            $key,
            self::all()
        );
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    private static function validate(
        string $key,
        mixed $value
    ): mixed {
        return match ($key) {
            'enabled',
            'tracking_enabled',
            'respect_dnt',
            'consent_required',
            'logging_enabled',
            'geoip_enabled'
                => self::validateBool(
                    $key,
                    $value
                ),

            'retention_days'
                => self::validateInt(
                    $key,
                    $value,
                    1,
                    3650
                ),

            'session_timeout'
                => self::validateInt(
                    $key,
                    $value,
                    60,
                    86400
                ),

            'visitor_cookie_lifetime'
                => self::validateInt(
                    $key,
                    $value,
                    3600,
                    63072000
                ),

            'batch_size'
                => self::validateInt(
                    $key,
                    $value,
                    1,
                    50
                ),

            'batch_max_bytes'
                => self::validateInt(
                    $key,
                    $value,
                    1024,
                    65536
                ),

            'heartbeat_interval'
                => self::validateInt(
                    $key,
                    $value,
                    1,
                    300
                ),

            'heartbeat_max_delta'
                => self::validateInt(
                    $key,
                    $value,
                    1,
                    600
                ),

            'consent_buffer_size'
                => self::validateInt(
                    $key,
                    $value,
                    1,
                    50
                ),

            'rate_limit_requests'
                => self::validateInt(
                    $key,
                    $value,
                    1,
                    120
                ),

            'rate_limit_window'
                => self::validateInt(
                    $key,
                    $value,
                    1,
                    60
                ),

            'environment'
                => self::validateEnum(
                    $key,
                    $value,
                    self::ENVIRONMENTS
                ),

            'tracking_mode'
                => self::validateEnum(
                    $key,
                    $value,
                    self::TRACKING_MODES
                ),

            default
                => throw new \InvalidArgumentException(
                    sprintf(
                        'Unsupported Visitor Intelligence configuration key: %s',
                        $key
                    )
                ),
        };
    }

    private static function validateBool(
        string $key,
        mixed $value
    ): bool {
        if (
            !is_bool(
                $value
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor Intelligence configuration "%s" must be boolean.',
                    $key
                )
            );
        }

        return $value;
    }

    private static function validateInt(
        string $key,
        mixed $value,
        int $min,
        int $max
    ): int {
        if (
            !is_int(
                $value
            )
            && !(
                is_string(
                    $value
                )
                && preg_match(
                    '/^\d+$/',
                    $value
                ) === 1
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor Intelligence configuration "%s" must be an integer.',
                    $key
                )
            );
        }

        $value = (int) $value;

        if (
            $value < $min
            || $value > $max
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor Intelligence configuration "%s" must be between %d and %d.',
                    $key,
                    $min,
                    $max
                )
            );
        }

        return $value;
    }

    private static function validateEnum(
        string $key,
        mixed $value,
        array $allowed
    ): string {
        if (
            !is_string(
                $value
            )
            || !in_array(
                $value,
                $allowed,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid Visitor Intelligence configuration "%s".',
                    $key
                )
            );
        }

        return $value;
    }
}