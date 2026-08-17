<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core;

use VisitorIntelligence\API\AnalyticsController;
use VisitorIntelligence\API\VisitorController;
use VisitorIntelligence\Admin\VisitorsPage;
use VisitorIntelligence\Aggregation\DailyAggregator;
use VisitorIntelligence\BotDetection\BotDetector;
use VisitorIntelligence\Collection\CollectController;
use VisitorIntelligence\Collection\EventPipeline;
use VisitorIntelligence\Collection\RateLimiter;
use VisitorIntelligence\Collection\ServerCollector;
use VisitorIntelligence\Core\Contracts\SourceDetectorInterface;
use VisitorIntelligence\Database\Database;
use VisitorIntelligence\Database\Migrator;
use VisitorIntelligence\Database\Migrations\Migration_001_Initial;
use VisitorIntelligence\Database\Migrations\Migration_002_GeoIp;
use VisitorIntelligence\Database\Repositories\AnalyticsRepository;
use VisitorIntelligence\Database\Repositories\DailyStatsRepository;
use VisitorIntelligence\Database\Repositories\EventRepository;
use VisitorIntelligence\Database\Repositories\PageviewRepository;
use VisitorIntelligence\Database\Repositories\SessionRepository;
use VisitorIntelligence\Database\Repositories\VisitorRepository;
use VisitorIntelligence\Device\DeviceDetector;
use VisitorIntelligence\GeoIP\GeoIpManager;
use VisitorIntelligence\GeoIP\GeoIpUpdater;
use VisitorIntelligence\Identity\VisitorCookie;
use VisitorIntelligence\Identity\VisitorManager;
use VisitorIntelligence\Scheduler\CronManager;
use VisitorIntelligence\Sessions\SessionManager;
use VisitorIntelligence\Sessions\Sessionizer;
use VisitorIntelligence\SourceAttribution\SourceDetector;

defined('ABSPATH') || exit;

final class Plugin
{
    private const ACTIVATION_TIMESTAMP_OPTION = 'vi_activated_at';

    private static ?self $instance = null;

    private readonly Container $container;

    private bool $booted = false;

    private bool $infrastructureRegistered = false;

    private bool $migrationsRegistered = false;

    private function __construct()
    {
        $this->container = new Container();
    }

    public static function boot(): void
    {
        self::instance()->initialize();
    }

    public static function activate(): void
    {
        $plugin = self::instance();

        try {
            $plugin->registerInfrastructure();
            $plugin->registerMigrations();
            $plugin->runMigrations();
            $plugin->ensureActivationTimestamp();
            $plugin->registerCapabilities();
            $plugin->registerCron();
        } catch (\Throwable $exception) {
            $plugin->reportLifecycleError(
                'Plugin activation failed.',
                $exception
            );

            wp_die(
                sprintf(
                    '<h1>Visitor Intelligence activation failed</h1><p>%s</p><p>Check the WordPress/PHP error log for the complete exception.</p>',
                    esc_html(
                        $exception->getMessage()
                    )
                ),
                'Visitor Intelligence activation failed',
                [
                    'response' => 500,
                    'back_link' => true,
                ]
            );
        }
    }

    public static function deactivate(): void
    {
        $timestamp =
            wp_next_scheduled(
                'vi_daily_aggregate'
            );

        if ($timestamp !== false) {
            wp_unschedule_event(
                $timestamp,
                'vi_daily_aggregate'
            );
        }

        $timestamp =
            wp_next_scheduled(
                'vi_daily_recovery'
            );

        if ($timestamp !== false) {
            wp_unschedule_event(
                $timestamp,
                'vi_daily_recovery'
            );
        }

        $timestamp =
            wp_next_scheduled(
                'vi_geoip_update'
            );

        if ($timestamp !== false) {
            wp_unschedule_event(
                $timestamp,
                'vi_geoip_update'
            );
        }
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activationTimestamp(): ?int
    {
        $value =
            get_option(
                self::ACTIVATION_TIMESTAMP_OPTION,
                null
            );

        if (
            is_int($value)
            && $value > 0
        ) {
            return $value;
        }

        if (
            is_string($value)
            && ctype_digit($value)
        ) {
            $timestamp =
                (int) $value;

            return $timestamp > 0
                ? $timestamp
                : null;
        }

        return null;
    }

    public function container(): Container
    {
        return $this->container;
    }

    private function ensureActivationTimestamp(): void
    {
        if (
            self::activationTimestamp() !== null
        ) {
            return;
        }

        $timestamp =
            $this->findHistoricalStartTimestamp();

        if (
            $timestamp === null
        ) {
            $timestamp = time();
        }

        if (
            add_option(
                self::ACTIVATION_TIMESTAMP_OPTION,
                $timestamp,
                '',
                false
            )
        ) {
            return;
        }

        if (
            self::activationTimestamp() === null
        ) {
            throw new \RuntimeException(
                'Unable to persist the Visitor Intelligence activation timestamp.'
            );
        }
    }

    private function findHistoricalStartTimestamp(): ?int
    {
        $database =
            $this->container->get(
                Database::class
            );

        $visitorsTable =
            $database->table(
                'visitors'
            );

        $sessionsTable =
            $database->table(
                'sessions'
            );

        $pageviewsTable =
            $database->table(
                'pageviews'
            );

        $eventsTable =
            $database->table(
                'events'
            );

        $timestamp =
            $database->getVar(
                "SELECT MIN(timestamp_value)
                 FROM (
                     SELECT MIN(first_seen) AS timestamp_value
                     FROM {$visitorsTable}
                     WHERE first_seen IS NOT NULL

                     UNION ALL

                     SELECT MIN(started_at) AS timestamp_value
                     FROM {$sessionsTable}
                     WHERE started_at IS NOT NULL

                     UNION ALL

                     SELECT MIN(occurred_at) AS timestamp_value
                     FROM {$pageviewsTable}
                     WHERE occurred_at IS NOT NULL

                     UNION ALL

                     SELECT MIN(occurred_at) AS timestamp_value
                     FROM {$eventsTable}
                     WHERE occurred_at IS NOT NULL
                 ) AS historical_dates
                 WHERE timestamp_value IS NOT NULL"
            );

        if (
            !is_string($timestamp)
            || trim($timestamp) === ''
        ) {
            return null;
        }

        $parsed =
            strtotime(
                $timestamp
            );

        if (
            $parsed === false
            || $parsed <= 0
        ) {
            return null;
        }

        return $parsed;
    }

    private function initialize(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $this->registerInfrastructure();
        $this->registerMigrations();

        $this->registerFrontendCollection();
        $this->registerBrowserCollector();
        $this->registerRuntimeServices();
        $this->registerRoutesAndMenu();

        do_action(
            'vi_loaded',
            $this->container
        );
    }

    private function registerInfrastructure(): void
    {
        if ($this->infrastructureRegistered) {
            return;
        }

        $this->infrastructureRegistered = true;

        $container =
            $this->container;

        $container->singleton(
            Config::class,
            static fn (): Config =>
                new Config()
        );

        $container->singleton(
            Database::class,
            static fn (): Database =>
                new Database()
        );

        $container->singleton(
            Migrator::class,
            static function (
                Container $container
            ): Migrator {
                return new Migrator(
                    $container->get(
                        Database::class
                    )
                );
            }
        );

        $container->singleton(
            VisitorRepository::class,
            static function (
                Container $container
            ): VisitorRepository {
                return new VisitorRepository(
                    $container->get(
                        Database::class
                    )
                );
            }
        );

        $container->singleton(
            SessionRepository::class,
            static function (
                Container $container
            ): SessionRepository {
                return new SessionRepository(
                    $container->get(
                        Database::class
                    )
                );
            }
        );

        $container->singleton(
            PageviewRepository::class,
            static function (
                Container $container
            ): PageviewRepository {
                return new PageviewRepository(
                    $container->get(
                        Database::class
                    )
                );
            }
        );

        $container->singleton(
            EventRepository::class,
            static function (
                Container $container
            ): EventRepository {
                return new EventRepository(
                    $container->get(
                        Database::class
                    )
                );
            }
        );

        $container->singleton(
            DailyStatsRepository::class,
            static function (
                Container $container
            ): DailyStatsRepository {
                return new DailyStatsRepository(
                    $container->get(
                        Database::class
                    )
                );
            }
        );

        $container->singleton(
            AnalyticsRepository::class,
            static function (
                Container $container
            ): AnalyticsRepository {
                return new AnalyticsRepository(
                    $container->get(
                        Database::class
                    )
                );
            }
        );

        $container->singleton(
            SourceDetectorInterface::class,
            static fn (): SourceDetectorInterface =>
                new SourceDetector()
        );

        $container->singleton(
            VisitorCookie::class,
            static fn (): VisitorCookie =>
                new VisitorCookie()
        );

        $container->singleton(
            VisitorManager::class,
            static function (
                Container $container
            ): VisitorManager {
                return new VisitorManager(
                    $container->get(
                        VisitorRepository::class
                    ),
                    $container->get(
                        VisitorCookie::class
                    )
                );
            }
        );

        $container->singleton(
            Sessionizer::class,
            static fn (): Sessionizer =>
                new Sessionizer()
        );

        $container->singleton(
            SessionManager::class,
            static function (
                Container $container
            ): SessionManager {
                return new SessionManager(
                    $container->get(
                        SessionRepository::class
                    ),
                    $container->get(
                        Sessionizer::class
                    )
                );
            }
        );

        $container->singleton(
            GeoIpManager::class,
            static fn (): GeoIpManager =>
                new GeoIpManager()
        );

        $container->singleton(
            GeoIpUpdater::class,
            static fn (): GeoIpUpdater =>
                new GeoIpUpdater()
        );

        $container->singleton(
            BotDetector::class,
            static fn (): BotDetector =>
                new BotDetector()
        );

        $container->singleton(
            DeviceDetector::class,
            static fn (): DeviceDetector =>
                new DeviceDetector()
        );

        $container->singleton(
            RateLimiter::class,
            static fn (): RateLimiter =>
                new RateLimiter()
        );

        $container->singleton(
            ServerCollector::class,
            static function (
                Container $container
            ): ServerCollector {
                return new ServerCollector(
                    $container->get(
                        VisitorManager::class
                    ),
                    $container->get(
                        SessionManager::class
                    ),
                    $container->get(
                        PageviewRepository::class
                    ),
                    $container->get(
                        GeoIpManager::class
                    ),
                    $container->get(
                        BotDetector::class
                    ),
                    $container->get(
                        DeviceDetector::class
                    ),
                    $container->get(
                        SourceDetectorInterface::class
                    )
                );
            }
        );

        $container->singleton(
            EventPipeline::class,
            static function (
                Container $container
            ): EventPipeline {
                return new EventPipeline(
                    $container->get(
                        EventRepository::class
                    ),
                    $container->get(
                        PageviewRepository::class
                    ),
                    $container->get(
                        SessionRepository::class
                    ),
                    $container->get(
                        VisitorRepository::class
                    )
                );
            }
        );

        $container->singleton(
            CollectController::class,
            static function (
                Container $container
            ): CollectController {
                return new CollectController(
                    $container->get(
                        RateLimiter::class
                    ),
                    $container->get(
                        EventPipeline::class
                    )
                );
            }
        );

        $container->singleton(
            AnalyticsController::class,
            static function (
                Container $container
            ): AnalyticsController {
                return new AnalyticsController(
                    $container->get(
                        DailyStatsRepository::class
                    ),
                    $container->get(
                        AnalyticsRepository::class
                    )
                );
            }
        );

        $container->singleton(
            VisitorController::class,
            static function (
                Container $container
            ): VisitorController {
                return new VisitorController(
                    $container->get(
                        VisitorRepository::class
                    )
                );
            }
        );

        $container->singleton(
            DailyAggregator::class,
            static function (
                Container $container
            ): DailyAggregator {
                return new DailyAggregator(
                    $container->get(
                        Database::class
                    ),
                    $container->get(
                        DailyStatsRepository::class
                    )
                );
            }
        );

        $container->singleton(
            CronManager::class,
            static function (
                Container $container
            ): CronManager {
                return new CronManager(
                    $container->get(
                        DailyAggregator::class
                    ),
                    $container->get(
                        GeoIpUpdater::class
                    )
                );
            }
        );
    }

    private function registerMigrations(): void
    {
        if ($this->migrationsRegistered) {
            return;
        }

        $this->migrationsRegistered = true;

        $migrator =
            $this->container->get(
                Migrator::class
            );

        $migrator->register(
            new Migration_001_Initial()
        );

        $migrator->register(
            new Migration_002_GeoIp()
        );
    }

    private function runMigrations(): void
    {
        $migrator =
            $this->container->get(
                Migrator::class
            );

        if (
            $migrator->isUpToDate()
        ) {
            return;
        }

        $migrator->migrate();
    }

    private function registerCapabilities(): void
    {
        $role =
            get_role(
                'administrator'
            );

        if (
            !$role instanceof \WP_Role
        ) {
            throw new \RuntimeException(
                'Unable to resolve the WordPress administrator role.'
            );
        }

        if (
            !$role->has_cap(
                'vi_view_analytics'
            )
        ) {
            $role->add_cap(
                'vi_view_analytics'
            );
        }
    }

    private function registerCron(): void
    {
        if (
            !wp_next_scheduled(
                'vi_daily_aggregate'
            )
        ) {
            wp_schedule_event(
                time() + HOUR_IN_SECONDS,
                'daily',
                'vi_daily_aggregate'
            );
        }
    }

    private function registerFrontendCollection(): void
    {
        add_action(
            'wp',
            function (): void {
                if (
                    is_admin()
                    || wp_doing_ajax()
                    || (
                        defined('REST_REQUEST')
                        && REST_REQUEST
                    )
                ) {
                    return;
                }

                $collector =
                    $this->container->get(
                        ServerCollector::class
                    );

                try {
                    $collector->handleServerRequest();
                } catch (\Throwable $exception) {
                    $this->reportLifecycleError(
                        'Server collection failed.',
                        $exception
                    );
                }
            }
        );
    }

    private function registerBrowserCollector(): void
    {
        add_action(
            'wp_enqueue_scripts',
            function (): void {
                if (is_admin()) {
                    return;
                }

                if (
                    !(bool) Config::get(
                        'enabled',
                        true
                    )
                    || !(bool) Config::get(
                        'tracking_enabled',
                        true
                    )
                ) {
                    return;
                }

                wp_enqueue_script(
                    'vi-browser-collector',
                    VI_URL
                    . 'assets/browser-collector.js',
                    [],
                    VI_VERSION,
                    true
                );

                $context =
                    $this->container
                        ->get(
                            ServerCollector::class
                        )
                        ->getClientContext();

                if (
                    $context === null
                ) {
                    return;
                }

                wp_localize_script(
                    'vi-browser-collector',
                    'viContext',
                    $context
                );
            },
            20
        );
    }

    private function registerRuntimeServices(): void
    {
        $cron =
            $this->container->get(
                CronManager::class
            );

        $cron->register();
    }

    private function registerRoutesAndMenu(): void
    {
        $this->registerAdminMenu();
        $this->registerRestRoutes();
    }

    private function registerAdminMenu(): void
    {
        if (!is_admin()) {
            return;
        }

        $adminFile =
            VI_DIR
            . 'admin/AdminMenu.php';

        if (
            !is_readable(
                $adminFile
            )
        ) {
            return;
        }

        require_once $adminFile;

        $class =
            \VisitorIntelligence\Admin\AdminMenu::class;

        if (
            !class_exists($class)
        ) {
            return;
        }

        $menu =
            new $class();

        add_action(
            'admin_menu',
            [
                $menu,
                'addMenuPage',
            ]
        );

        add_action(
            'admin_enqueue_scripts',
            [
                $menu,
                'enqueueAssets',
            ]
        );

        $visitorsPageFile =
            VI_DIR
            . 'admin/VisitorsPage.php';

        if (
            is_readable(
                $visitorsPageFile
            )
        ) {
            require_once $visitorsPageFile;

            $visitorsPage =
                new VisitorsPage();

            add_action(
                'admin_menu',
                static function () use (
                    $menu,
                    $visitorsPage
                ): void {
                    $menu->addVisitorsPage(
                        $visitorsPage
                    );
                },
                20
            );
        }
    }

    private function registerRestRoutes(): void
    {
        add_action(
            'rest_api_init',
            function (): void {
                $this->container
                    ->get(
                        CollectController::class
                    )
                    ->registerRoutes();

                $this->container
                    ->get(
                        AnalyticsController::class
                    )
                    ->registerRoutes();

                $this->container
                    ->get(
                        VisitorController::class
                    )
                    ->registerRoutes();
            }
        );
    }

    private function reportLifecycleError(
        string $message,
        \Throwable $exception
    ): void {
        $payload = [
            'message' =>
                $message,

            'exception' =>
                $exception::class,

            'exception_message' =>
                $exception->getMessage(),

            'exception_code' =>
                $exception->getCode(),

            'file' =>
                $exception->getFile(),

            'line' =>
                $exception->getLine(),
        ];

        do_action(
            'vi_plugin_error',
            $exception,
            $payload
        );

        if (
            function_exists(
                'error_log'
            )
        ) {
            error_log(
                sprintf(
                    '[Visitor Intelligence] %s %s: %s in %s:%d',
                    $message,
                    $exception::class,
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine()
                )
            );
        }
    }
}