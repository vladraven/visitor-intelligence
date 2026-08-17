<?php

declare(strict_types=1);

namespace VisitorIntelligence\Scheduler;

use VisitorIntelligence\Aggregation\DailyAggregator;
use VisitorIntelligence\Core\Plugin;
use VisitorIntelligence\GeoIP\GeoIpUpdater;

defined('ABSPATH') || exit;

final class CronManager
{
    private const AGGREGATION_HOOK = 'vi_daily_aggregate';

    private const RECOVERY_HOOK = 'vi_daily_recovery';

    private const GEOIP_UPDATE_HOOK = 'vi_geoip_update';

    private const RECENT_DAYS = 3;

    private const RECOVERY_BATCH_DAYS = 30;

    private const RECOVERY_CURSOR_OPTION =
        'vi_daily_recovery_cursor';

    private const RECOVERY_COMPLETED_OPTION =
        'vi_daily_recovery_completed';

    private const HOURLY_INTERVAL = 'hourly';

    private const DAILY_INTERVAL = 'daily';

    private const INITIAL_RUN_DELAY = 60;

    public function __construct(
        private readonly DailyAggregator $aggregator,
        private readonly GeoIpUpdater $geoIpUpdater
    ) {
    }

    public function register(): void
    {
        add_action(
            self::AGGREGATION_HOOK,
            [
                $this,
                'runDaily',
            ]
        );

        add_action(
            self::RECOVERY_HOOK,
            [
                $this,
                'runRecovery',
            ]
        );

        add_action(
            self::GEOIP_UPDATE_HOOK,
            [
                $this,
                'runGeoIpUpdate',
            ]
        );

        $this->ensureHourlyAggregationSchedule();

        $this->ensureDailyRecoverySchedule();

        $this->ensureDailyGeoIpSchedule();
    }

    public function runDaily(): void
    {
        $today =
            new \DateTimeImmutable(
                'today',
                new \DateTimeZone(
                    'UTC'
                )
            );

        for (
            $offset = 0;
            $offset < self::RECENT_DAYS;
            $offset++
        ) {
            $dateKey =
                $today
                    ->modify(
                        '-' . $offset . ' days'
                    )
                    ->format(
                        'Y-m-d'
                    );

            try {
                $this->aggregator->aggregateDate(
                    $dateKey
                );
            } catch (
                \Throwable $exception
            ) {
                $this->reportError(
                    'Daily aggregation failed.',
                    $exception,
                    [
                        'date' =>
                            $dateKey,
                    ]
                );
            }
        }
    }

    public function runRecovery(): void
    {
        if (
            $this->isRecoveryCompleted()
        ) {
            return;
        }

        $activationTimestamp =
            Plugin::activationTimestamp();

        if (
            $activationTimestamp === null
        ) {
            return;
        }

        $startDate =
            new \DateTimeImmutable(
                '@' . $activationTimestamp
            );

        $startDate =
            $startDate
                ->setTimezone(
                    new \DateTimeZone(
                        'UTC'
                    )
                )
                ->setTime(
                    0,
                    0,
                    0
                );

        $today =
            new \DateTimeImmutable(
                'today',
                new \DateTimeZone(
                    'UTC'
                )
            );

        $cursor =
            $this->getRecoveryCursor();

        if (
            $cursor !== null
        ) {
            $cursorDate =
                new \DateTimeImmutable(
                    $cursor,
                    new \DateTimeZone(
                        'UTC'
                    )
                );

            if (
                $cursorDate > $startDate
            ) {
                $startDate =
                    $cursorDate;
            }
        }

        if (
            $startDate > $today
        ) {
            $this->markRecoveryCompleted();

            return;
        }

        $processed = 0;

        $currentDate =
            $startDate;

        while (
            $currentDate <= $today
            && $processed < self::RECOVERY_BATCH_DAYS
        ) {
            $dateKey =
                $currentDate->format(
                    'Y-m-d'
                );

            try {
                $this->aggregator->aggregateDate(
                    $dateKey
                );
            } catch (
                \Throwable $exception
            ) {
                $this->reportError(
                    'Historical aggregation failed.',
                    $exception,
                    [
                        'date' =>
                            $dateKey,
                    ]
                );

                return;
            }

            $processed++;

            $currentDate =
                $currentDate->modify(
                    '+1 day'
                );
        }

        if (
            $currentDate > $today
        ) {
            $this->markRecoveryCompleted();

            return;
        }

        $this->setRecoveryCursor(
            $currentDate->format(
                'Y-m-d'
            )
        );
    }

    public function runGeoIpUpdate(): void
    {
        try {
            $this->geoIpUpdater->updateIfNeeded();
        } catch (
            \Throwable $exception
        ) {
            $this->reportError(
                'GeoIP database update failed.',
                $exception
            );
        }
    }

    private function ensureHourlyAggregationSchedule(): void
    {
        $event =
            wp_get_scheduled_event(
                self::AGGREGATION_HOOK
            );

        if (
            $event !== false
            && $event->schedule !== self::HOURLY_INTERVAL
        ) {
            wp_clear_scheduled_hook(
                self::AGGREGATION_HOOK
            );

            $event = false;
        }

        if (
            $event === false
        ) {
            wp_schedule_event(
                time() + self::INITIAL_RUN_DELAY,
                self::HOURLY_INTERVAL,
                self::AGGREGATION_HOOK
            );
        }
    }

    private function ensureDailyRecoverySchedule(): void
    {
        $event =
            wp_get_scheduled_event(
                self::RECOVERY_HOOK
            );

        if (
            $event !== false
            && $event->schedule !== self::DAILY_INTERVAL
        ) {
            wp_clear_scheduled_hook(
                self::RECOVERY_HOOK
            );

            $event = false;
        }

        if (
            $event === false
        ) {
            wp_schedule_event(
                time() + self::INITIAL_RUN_DELAY,
                self::DAILY_INTERVAL,
                self::RECOVERY_HOOK
            );
        }
    }

    private function ensureDailyGeoIpSchedule(): void
    {
        $event =
            wp_get_scheduled_event(
                self::GEOIP_UPDATE_HOOK
            );

        if (
            $event !== false
            && $event->schedule !== self::DAILY_INTERVAL
        ) {
            wp_clear_scheduled_hook(
                self::GEOIP_UPDATE_HOOK
            );

            $event = false;
        }

        if (
            $event === false
        ) {
            wp_schedule_event(
                time() + self::INITIAL_RUN_DELAY,
                self::DAILY_INTERVAL,
                self::GEOIP_UPDATE_HOOK
            );
        }
    }

    private function isRecoveryCompleted(): bool
    {
        return (bool) get_option(
            self::RECOVERY_COMPLETED_OPTION,
            false
        );
    }

    private function markRecoveryCompleted(): void
    {
        update_option(
            self::RECOVERY_COMPLETED_OPTION,
            true,
            false
        );

        delete_option(
            self::RECOVERY_CURSOR_OPTION
        );
    }

    private function getRecoveryCursor(): ?string
    {
        $value =
            get_option(
                self::RECOVERY_CURSOR_OPTION,
                null
            );

        if (
            !is_string($value)
            || trim($value) === ''
        ) {
            return null;
        }

        $date =
            \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $value,
                new \DateTimeZone(
                    'UTC'
                )
            );

        if (
            $date === false
            || $date->format('Y-m-d') !== $value
        ) {
            return null;
        }

        return $value;
    }

    private function setRecoveryCursor(
        string $date
    ): void {
        update_option(
            self::RECOVERY_CURSOR_OPTION,
            $date,
            false
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reportError(
        string $message,
        \Throwable $exception,
        array $context = []
    ): void {
        do_action(
            'vi_cron_error',
            $exception,
            [
                'message' =>
                    $message,

                'exception' =>
                    $exception::class,

                'exception_message' =>
                    $exception->getMessage(),

                'file' =>
                    $exception->getFile(),

                'line' =>
                    $exception->getLine(),

                'context' =>
                    $context,
            ]
        );

        if (
            function_exists(
                'error_log'
            )
        ) {
            error_log(
                sprintf(
                    '[Visitor Intelligence] %s %s: %s',
                    $message,
                    $exception::class,
                    $exception->getMessage()
                )
            );
        }
    }
}