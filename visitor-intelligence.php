<?php

/**
 * Plugin Name: Visitor Intelligence
 * Description: Independent first-party visitor telemetry and behavior analytics for WordPress.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Vladimir Klekovkin
 * License: GPL-2.0-or-later
 * Text Domain: visitor-intelligence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('VI_VERSION', '1.0.1');
define('VI_FILE', __FILE__);
define('VI_DIR', plugin_dir_path(__FILE__));
define('VI_URL', plugin_dir_url(__FILE__));
define('VI_BASENAME', plugin_basename(__FILE__));

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'VisitorIntelligence\\';

        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        if ($relative === '') {
            return;
        }

        $file = VI_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }
);

// Vendored, dependency-free MaxMind DB reader (Apache-2.0), used by GeoIpManager.
spl_autoload_register(
    static function (string $class): void {
        $prefix = 'MaxMind\\Db\\';

        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        if ($relative === '') {
            return;
        }

        $file = VI_DIR . 'includes/GeoIP/Vendor/MaxMind/Db/' . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }
);

register_activation_hook(
    VI_FILE,
    static function (): void {
        if (class_exists(\VisitorIntelligence\Core\Plugin::class)) {
            \VisitorIntelligence\Core\Plugin::activate();
        }
    }
);

register_deactivation_hook(
    VI_FILE,
    static function (): void {
        if (class_exists(\VisitorIntelligence\Core\Plugin::class)) {
            \VisitorIntelligence\Core\Plugin::deactivate();
        }
    }
);

add_action(
    'plugins_loaded',
    static function (): void {
        if (!class_exists(\VisitorIntelligence\Core\Plugin::class)) {
            return;
        }

        \VisitorIntelligence\Core\Plugin::boot();
    },
    20
);