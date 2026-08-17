<?php

declare(strict_types=1);

namespace VisitorIntelligence\API;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use VisitorIntelligence\Database\Repositories\AnalyticsRepository;
use VisitorIntelligence\Database\Repositories\DailyStatsRepository;

defined('ABSPATH') || exit;

final class AnalyticsController
{
    private const DEFAULT_RANGE_DAYS = 30;

    private const MIN_RANGE_DAYS = 1;

    private const MAX_RANGE_DAYS = 3650;

    private const DEFAULT_PAGE_LIMIT = 50;

    private const MAX_PAGE_LIMIT = 500;

    public function __construct(
        private readonly DailyStatsRepository $repository,
        private readonly AnalyticsRepository $analyticsRepository
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'vi/v1',
            '/analytics',
            [
                'methods' =>
                    'GET',

                'callback' =>
                    [
                        $this,
                        'getAnalytics',
                    ],

                'permission_callback' =>
                    [
                        $this,
                        'canViewAnalytics',
                    ],

                'args' =>
                    [
                        'days' =>
                            [
                                'required' =>
                                    false,

                                'default' =>
                                    self::DEFAULT_RANGE_DAYS,

                                'sanitize_callback' =>
                                    static function (
                                        mixed $value
                                    ): int {
                                        return (int) $value;
                                    },

                                'validate_callback' =>
                                    static function (
                                        mixed $value
                                    ): bool {
                                        if (
                                            is_array($value)
                                            || is_object($value)
                                        ) {
                                            return false;
                                        }

                                        $days =
                                            filter_var(
                                                $value,
                                                FILTER_VALIDATE_INT
                                            );

                                        if (
                                            $days === false
                                        ) {
                                            return false;
                                        }

                                        return
                                            $days >=
                                                self::MIN_RANGE_DAYS
                                            && $days <=
                                                self::MAX_RANGE_DAYS;
                                    },
                            ],

                        'from' =>
                            [
                                'required' =>
                                    false,

                                'sanitize_callback' =>
                                    static function (
                                        mixed $value
                                    ): string {
                                        return trim(
                                            (string) $value
                                        );
                                    },

                                'validate_callback' =>
                                    static function (
                                        mixed $value
                                    ): bool {
                                        return self::isValidDate(
                                            $value
                                        );
                                    },
                            ],

                        'to' =>
                            [
                                'required' =>
                                    false,

                                'sanitize_callback' =>
                                    static function (
                                        mixed $value
                                    ): string {
                                        return trim(
                                            (string) $value
                                        );
                                    },

                                'validate_callback' =>
                                    static function (
                                        mixed $value
                                    ): bool {
                                        return self::isValidDate(
                                            $value
                                        );
                                    },
                            ],

                        'date' =>
                            [
                                'required' =>
                                    false,

                                'sanitize_callback' =>
                                    static function (
                                        mixed $value
                                    ): string {
                                        return trim(
                                            (string) $value
                                        );
                                    },

                                'validate_callback' =>
                                    static function (
                                        mixed $value
                                    ): bool {
                                        return self::isValidDate(
                                            $value
                                        );
                                    },
                            ],

                        'granularity' =>
                            [
                                'required' =>
                                    false,

                                'default' =>
                                    'day',

                                'sanitize_callback' =>
                                    static function (
                                        mixed $value
                                    ): string {
                                        return strtolower(
                                            trim(
                                                (string) $value
                                            )
                                        );
                                    },

                                'validate_callback' =>
                                    static function (
                                        mixed $value
                                    ): bool {
                                        return in_array(
                                            strtolower(
                                                trim(
                                                    (string) $value
                                                )
                                            ),
                                            [
                                                'day',
                                                'hour',
                                            ],
                                            true
                                        );
                                    },
                            ],

                        'view' =>
                            [
                                'required' =>
                                    false,

                                'default' =>
                                    'overview',

                                'sanitize_callback' =>
                                    static function (
                                        mixed $value
                                    ): string {
                                        return strtolower(
                                            trim(
                                                (string) $value
                                            )
                                        );
                                    },

                                'validate_callback' =>
                                    static function (
                                        mixed $value
                                    ): bool {
                                        return in_array(
                                            strtolower(
                                                trim(
                                                    (string) $value
                                                )
                                            ),
                                            [
                                                'overview',
                                                'pages',
                                            ],
                                            true
                                        );
                                    },
                            ],

                        'limit' =>
                            [
                                'required' =>
                                    false,

                                'default' =>
                                    self::DEFAULT_PAGE_LIMIT,

                                'sanitize_callback' =>
                                    static function (
                                        mixed $value
                                    ): int {
                                        return (int) $value;
                                    },

                                'validate_callback' =>
                                    static function (
                                        mixed $value
                                    ): bool {
                                        if (
                                            is_array($value)
                                            || is_object($value)
                                        ) {
                                            return false;
                                        }

                                        $limit =
                                            filter_var(
                                                $value,
                                                FILTER_VALIDATE_INT
                                            );

                                        if (
                                            $limit === false
                                        ) {
                                            return false;
                                        }

                                        return
                                            $limit >= 1
                                            && $limit <=
                                                self::MAX_PAGE_LIMIT;
                                    },
                            ],
                    ],
            ]
        );
    }

    public function getAnalytics(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $view =
            strtolower(
                trim(
                    (string) (
                        $request->get_param(
                            'view'
                        )
                        ?? 'overview'
                    )
                )
            );

        $granularity =
            strtolower(
                trim(
                    (string) (
                        $request->get_param(
                            'granularity'
                        )
                        ?? 'day'
                    )
                )
            );

        try {
            if (
                $granularity === 'hour'
            ) {
                return $this->getHourlyAnalytics(
                    $request
                );
            }

            if (
                $view === 'pages'
            ) {
                return $this->getPageAnalytics(
                    $request
                );
            }

            return $this->getDailyAnalytics(
                $request
            );
        } catch (
            \Throwable $exception
        ) {
            do_action(
                'vi_analytics_error',
                $exception,
                [
                    'request' =>
                        [
                            'days' =>
                                $request->get_param(
                                    'days'
                                ),

                            'from' =>
                                $request->get_param(
                                    'from'
                                ),

                            'to' =>
                                $request->get_param(
                                    'to'
                                ),

                            'date' =>
                                $request->get_param(
                                    'date'
                                ),

                            'granularity' =>
                                $granularity,

                            'view' =>
                                $view,
                        ],
                ]
            );

            return new WP_Error(
                'vi_analytics_unavailable',
                'Unable to load analytics data.',
                [
                    'status' =>
                        500,
                ]
            );
        }
    }

    private function getDailyAnalytics(
        WP_REST_Request $request
    ): WP_REST_Response {
        [
            $fromDate,
            $toDate,
        ] =
            $this->resolveDateRange(
                $request
            );

        $summary =
            $this->analyticsRepository->getPeriodSummary(
                $fromDate,
                $toDate
            );

        $daily =
            $this->analyticsRepository->getDailyTrend(
                $fromDate,
                $toDate
            );

        $labels = [];

        $visitors = [];

        $sessions = [];

        $pageviews = [];

        $activeSeconds = [];

        $bounces = [];

        $entries = [];

        $exits = [];

        foreach ($daily as $row) {
            $labels[] =
                $row['date'];

            $visitors[] =
                max(
                    0,
                    (int) (
                        $row['visitors']
                        ?? 0
                    )
                );

            $sessions[] =
                max(
                    0,
                    (int) (
                        $row['sessions']
                        ?? 0
                    )
                );

            $pageviews[] =
                max(
                    0,
                    (int) (
                        $row['pageviews']
                        ?? 0
                    )
                );

            $activeSeconds[] =
                max(
                    0,
                    (int) (
                        $row['active_seconds']
                        ?? 0
                    )
                );

            $bounces[] =
                max(
                    0,
                    (int) (
                        $row['bounces']
                        ?? 0
                    )
                );

            $entries[] =
                max(
                    0,
                    (int) (
                        $row['entries']
                        ?? 0
                    )
                );

            $exits[] =
                max(
                    0,
                    (int) (
                        $row['exits']
                        ?? 0
                    )
                );
        }

        return new WP_REST_Response(
            [
                'range' =>
                    [
                        'days' =>
                            $this->daysBetween(
                                $fromDate,
                                $toDate
                            ),

                        'from' =>
                            $fromDate,

                        'to' =>
                            $toDate,
                    ],

                'summary' =>
                    $summary,

                'trends' =>
                    [
                        'labels' =>
                            $labels,

                        'visitors' =>
                            $visitors,

                        'sessions' =>
                            $sessions,

                        'pageviews' =>
                            $pageviews,

                        'active_seconds' =>
                            $activeSeconds,

                        'bounces' =>
                            $bounces,

                        'entries' =>
                            $entries,

                        'exits' =>
                            $exits,
                    ],

                'daily' =>
                    $daily,
            ],
            200
        );
    }

    private function getHourlyAnalytics(
        WP_REST_Request $request
    ): WP_REST_Response {
        $date =
            trim(
                (string) (
                    $request->get_param(
                        'date'
                    )
                    ?? ''
                )
            );

        if (
            $date === ''
        ) {
            throw new \InvalidArgumentException(
                'The date parameter is required for hourly analytics.'
            );
        }

        if (
            !self::isValidDate(
                $date
            )
        ) {
            throw new \InvalidArgumentException(
                'Invalid analytics date.'
            );
        }

        $hourly =
            $this->analyticsRepository->getHourlyTrend(
                $date
            );

        $labels = [];

        $visitors = [];

        $sessions = [];

        $pageviews = [];

        $activeSeconds = [];

        $bounces = [];

        $entries = [];

        $exits = [];

        foreach ($hourly as $row) {
            $labels[] =
                $row['label'];

            $visitors[] =
                max(
                    0,
                    (int) (
                        $row['visitors']
                        ?? 0
                    )
                );

            $sessions[] =
                max(
                    0,
                    (int) (
                        $row['sessions']
                        ?? 0
                    )
                );

            $pageviews[] =
                max(
                    0,
                    (int) (
                        $row['pageviews']
                        ?? 0
                    )
                );

            $activeSeconds[] =
                max(
                    0,
                    (int) (
                        $row['active_seconds']
                        ?? 0
                    )
                );

            $bounces[] =
                max(
                    0,
                    (int) (
                        $row['bounces']
                        ?? 0
                    )
                );

            $entries[] =
                max(
                    0,
                    (int) (
                        $row['entries']
                        ?? 0
                    )
                );

            $exits[] =
                max(
                    0,
                    (int) (
                        $row['exits']
                        ?? 0
                    )
                );
        }

        return new WP_REST_Response(
            [
                'range' =>
                    [
                        'date' =>
                            $date,

                        'hours' =>
                            24,
                    ],

                'trends' =>
                    [
                        'labels' =>
                            $labels,

                        'visitors' =>
                            $visitors,

                        'sessions' =>
                            $sessions,

                        'pageviews' =>
                            $pageviews,

                        'active_seconds' =>
                            $activeSeconds,

                        'bounces' =>
                            $bounces,

                        'entries' =>
                            $entries,

                        'exits' =>
                            $exits,
                    ],

                'hourly' =>
                    $hourly,
            ],
            200
        );
    }

    private function getPageAnalytics(
        WP_REST_Request $request
    ): WP_REST_Response {
        [
            $fromDate,
            $toDate,
        ] =
            $this->resolveDateRange(
                $request
            );

        $limit =
            $request->get_param(
                'limit'
            );

        $limit =
            filter_var(
                $limit,
                FILTER_VALIDATE_INT
            );

        if (
            $limit === false
        ) {
            $limit =
                self::DEFAULT_PAGE_LIMIT;
        }

        $limit =
            max(
                1,
                min(
                    self::MAX_PAGE_LIMIT,
                    (int) $limit
                )
            );

        $summary =
            $this->analyticsRepository->getPeriodSummary(
                $fromDate,
                $toDate
            );

        $pages =
            $this->analyticsRepository->getTopPages(
                $fromDate,
                $toDate,
                $limit
            );

        return new WP_REST_Response(
            [
                'range' =>
                    [
                        'days' =>
                            $this->daysBetween(
                                $fromDate,
                                $toDate
                            ),

                        'from' =>
                            $fromDate,

                        'to' =>
                            $toDate,
                    ],

                'summary' =>
                    $summary,

                'pages' =>
                    $pages,
            ],
            200
        );
    }

    public function canViewAnalytics(): bool
    {
        return current_user_can(
            'vi_view_analytics'
        );
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveDateRange(
        WP_REST_Request $request
    ): array {
        $from =
            trim(
                (string) (
                    $request->get_param(
                        'from'
                    )
                    ?? ''
                )
            );

        $to =
            trim(
                (string) (
                    $request->get_param(
                        'to'
                    )
                    ?? ''
                )
            );

        if (
            $from !== ''
            || $to !== ''
        ) {
            if (
                $from === ''
                || $to === ''
            ) {
                throw new \InvalidArgumentException(
                    'Both from and to dates are required.'
                );
            }

            if (
                !self::isValidDate($from)
                || !self::isValidDate($to)
            ) {
                throw new \InvalidArgumentException(
                    'Invalid analytics date range.'
                );
            }

            if (
                $from > $to
            ) {
                throw new \InvalidArgumentException(
                    'Analytics range start cannot be after range end.'
                );
            }

            $days =
                $this->daysBetween(
                    $from,
                    $to
                );

            if (
                $days < self::MIN_RANGE_DAYS
                || $days > self::MAX_RANGE_DAYS
            ) {
                throw new \InvalidArgumentException(
                    'Analytics range is outside the supported limits.'
                );
            }

            return [
                $from,
                $to,
            ];
        }

        $days =
            $this->resolveRangeDays(
                $request
            );

        $today =
            new \DateTimeImmutable(
                'today',
                new \DateTimeZone(
                    'UTC'
                )
            );

        $to =
            $today->format(
                'Y-m-d'
            );

        $from =
            $today
                ->modify(
                    '-' . ($days - 1) . ' days'
                )
                ->format(
                    'Y-m-d'
                );

        return [
            $from,
            $to,
        ];
    }

    private function resolveRangeDays(
        WP_REST_Request $request
    ): int {
        $value =
            $request->get_param(
                'days'
            );

        if (
            $value === null
            || $value === ''
        ) {
            return self::DEFAULT_RANGE_DAYS;
        }

        $days =
            filter_var(
                $value,
                FILTER_VALIDATE_INT
            );

        if (
            $days === false
        ) {
            return self::DEFAULT_RANGE_DAYS;
        }

        return max(
            self::MIN_RANGE_DAYS,
            min(
                self::MAX_RANGE_DAYS,
                (int) $days
            )
        );
    }

    private function daysBetween(
        string $fromDate,
        string $toDate
    ): int {
        $from =
            new \DateTimeImmutable(
                $fromDate,
                new \DateTimeZone('UTC')
            );

        $to =
            new \DateTimeImmutable(
                $toDate,
                new \DateTimeZone('UTC')
            );

        return
            (int) $from
                ->diff($to)
                ->days
            + 1;
    }

    private static function isValidDate(
        mixed $value
    ): bool {
        if (
            is_array($value)
            || is_object($value)
        ) {
            return false;
        }

        $date =
            trim(
                (string) $value
            );

        if (
            $date === ''
        ) {
            return false;
        }

        $parsed =
            \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $date,
                new \DateTimeZone('UTC')
            );

        $errors =
            \DateTimeImmutable::getLastErrors();

        return
            $parsed !== false
            && (
                !is_array($errors)
                || (
                    $errors['warning_count'] === 0
                    && $errors['error_count'] === 0
                )
            )
            && $parsed->format(
                'Y-m-d'
            ) === $date;
    }
}