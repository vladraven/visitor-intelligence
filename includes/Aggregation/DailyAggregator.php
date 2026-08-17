<?php

declare(strict_types=1);

namespace VisitorIntelligence\Aggregation;

use VisitorIntelligence\Database\Database;
use VisitorIntelligence\Database\Repositories\DailyStatsRepository;

defined('ABSPATH') || exit;

final class DailyAggregator
{
    private const DIMENSION_SESSION_FIELDS = [
        'source' => 'source_type',
        'country' => 'country_code',
        'region' => 'region_code',
        'device' => 'device_type',
        'browser' => 'browser',
        'os' => 'os',
    ];

    private const UNKNOWN = 'unknown';

    public function __construct(
        private readonly Database $database,
        private readonly DailyStatsRepository $repository
    ) {
    }

    public function aggregateDate(
        string $dateKey
    ): void {
        $dateKey = trim($dateKey);

        $this->validateDate(
            $dateKey
        );

        $this->aggregateOverview(
            $dateKey
        );

        $this->aggregatePage(
            $dateKey
        );

        foreach (
            array_keys(
                self::DIMENSION_SESSION_FIELDS
            ) as $dimensionType
        ) {
            $this->aggregateSessionDimension(
                $dateKey,
                $dimensionType
            );
        }
    }

    private function aggregateOverview(
        string $dateKey
    ): void {
        $sessionsTable =
            $this->database->table(
                'sessions'
            );

        $pageviewsTable =
            $this->database->table(
                'pageviews'
            );

        $overview =
            $this->database->getRow(
                "SELECT
                    COUNT(DISTINCT visitor_id)
                        AS visitors_count,
                    COUNT(DISTINCT session_id)
                        AS sessions_count,
                    COALESCE(
                        SUM(active_seconds),
                        0
                    ) AS active_seconds_total
                 FROM {$sessionsTable}
                 WHERE DATE(started_at) = %s",
                $dateKey
            );

        $pageviews =
            $this->database->getRow(
                "SELECT
                    COUNT(*) AS pageviews_count,
                    COALESCE(
                        SUM(active_seconds),
                        0
                    ) AS active_seconds_total,
                    COALESCE(
                        SUM(is_landing),
                        0
                    ) AS entries_count,
                    COALESCE(
                        SUM(is_exit),
                        0
                    ) AS exits_count
                 FROM {$pageviewsTable}
                 WHERE DATE(occurred_at) = %s",
                $dateKey
            );

        $bounces =
            $this->countBouncesForDate(
                $dateKey
            );

        $this->repository->upsert(
            $dateKey,
            'overview',
            'total',
            [
                'visitors_count' =>
                    (int) (
                        $overview['visitors_count']
                        ?? 0
                    ),

                'sessions_count' =>
                    (int) (
                        $overview['sessions_count']
                        ?? 0
                    ),

                'pageviews_count' =>
                    (int) (
                        $pageviews['pageviews_count']
                        ?? 0
                    ),

                'active_seconds_total' =>
                    (int) (
                        $overview['active_seconds_total']
                        ?? 0
                    ),

                'bounces_count' =>
                    $bounces,

                'entries_count' =>
                    (int) (
                        $pageviews['entries_count']
                        ?? 0
                    ),

                'exits_count' =>
                    (int) (
                        $pageviews['exits_count']
                        ?? 0
                    ),
            ]
        );
    }

    private function aggregatePage(
        string $dateKey
    ): void {
        $pageviewsTable =
            $this->database->table(
                'pageviews'
            );

        $rows =
            $this->database->getResults(
                "SELECT
                    pv.url_hash AS dimension_value,
                    COUNT(*) AS pageviews_count,
                    COUNT(
                        DISTINCT pv.visitor_id
                    ) AS visitors_count,
                    COUNT(
                        DISTINCT pv.session_id
                    ) AS sessions_count,
                    COALESCE(
                        SUM(pv.active_seconds),
                        0
                    ) AS active_seconds_total,
                    COALESCE(
                        SUM(pv.is_landing),
                        0
                    ) AS entries_count,
                    COALESCE(
                        SUM(pv.is_exit),
                        0
                    ) AS exits_count
                 FROM {$pageviewsTable} AS pv
                 WHERE DATE(pv.occurred_at) = %s
                   AND pv.url_hash IS NOT NULL
                   AND TRIM(pv.url_hash) <> ''
                 GROUP BY pv.url_hash
                 ORDER BY pv.url_hash ASC",
                $dateKey
            );

        if (
            !is_array($rows)
        ) {
            return;
        }

        foreach (
            $rows as $row
        ) {
            $dimensionValue =
                trim(
                    (string) (
                        $row['dimension_value']
                        ?? ''
                    )
                );

            if (
                $dimensionValue === ''
            ) {
                continue;
            }

            $bounces =
                $this->countPageBounces(
                    $dateKey,
                    $dimensionValue
                );

            $this->repository->upsert(
                $dateKey,
                'page',
                $dimensionValue,
                [
                    'visitors_count' =>
                        max(
                            0,
                            (int) (
                                $row['visitors_count']
                                ?? 0
                            )
                        ),

                    'sessions_count' =>
                        max(
                            0,
                            (int) (
                                $row['sessions_count']
                                ?? 0
                            )
                        ),

                    'pageviews_count' =>
                        max(
                            0,
                            (int) (
                                $row['pageviews_count']
                                ?? 0
                            )
                        ),

                    'active_seconds_total' =>
                        max(
                            0,
                            (int) (
                                $row['active_seconds_total']
                                ?? 0
                            )
                        ),

                    'bounces_count' =>
                        max(
                            0,
                            $bounces
                        ),

                    'entries_count' =>
                        max(
                            0,
                            (int) (
                                $row['entries_count']
                                ?? 0
                            )
                        ),

                    'exits_count' =>
                        max(
                            0,
                            (int) (
                                $row['exits_count']
                                ?? 0
                            )
                        ),
                ]
            );
        }
    }

    private function aggregateSessionDimension(
        string $dateKey,
        string $dimensionType
    ): void {
        if (
            !isset(
                self::DIMENSION_SESSION_FIELDS[
                    $dimensionType
                ]
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Unsupported session dimension: %s',
                    $dimensionType
                )
            );
        }

        $sessionsTable =
            $this->database->table(
                'sessions'
            );

        $field =
            self::DIMENSION_SESSION_FIELDS[
                $dimensionType
            ];

        $rows =
            $this->database->getResults(
                "SELECT
                    COALESCE(
                        NULLIF(
                            TRIM(s.{$field}),
                            ''
                        ),
                        %s
                    ) AS dimension_value,

                    COUNT(
                        DISTINCT s.visitor_id
                    ) AS visitors_count,

                    COUNT(
                        DISTINCT s.session_id
                    ) AS sessions_count,

                    COALESCE(
                        SUM(s.active_seconds),
                        0
                    ) AS active_seconds_total

                 FROM {$sessionsTable} AS s

                 WHERE DATE(s.started_at) = %s

                 GROUP BY
                    COALESCE(
                        NULLIF(
                            TRIM(s.{$field}),
                            ''
                        ),
                        %s
                    )

                 ORDER BY
                    dimension_value ASC",
                self::UNKNOWN,
                $dateKey,
                self::UNKNOWN
            );

        if (
            !is_array($rows)
        ) {
            return;
        }

        foreach (
            $rows as $row
        ) {
            $dimensionValue =
                trim(
                    (string) (
                        $row['dimension_value']
                        ?? self::UNKNOWN
                    )
                );

            if (
                $dimensionValue === ''
            ) {
                $dimensionValue =
                    self::UNKNOWN;
            }

            $metrics =
                $this->aggregateSessionDimensionMetrics(
                    $dateKey,
                    $field,
                    $dimensionValue
                );

            $this->repository->upsert(
                $dateKey,
                $dimensionType,
                $dimensionValue,
                [
                    'visitors_count' =>
                        max(
                            0,
                            (int) (
                                $row['visitors_count']
                                ?? 0
                            )
                        ),

                    'sessions_count' =>
                        max(
                            0,
                            (int) (
                                $row['sessions_count']
                                ?? 0
                            )
                        ),

                    'pageviews_count' =>
                        $metrics['pageviews_count'],

                    'active_seconds_total' =>
                        max(
                            0,
                            (int) (
                                $row['active_seconds_total']
                                ?? 0
                            )
                        ),

                    'bounces_count' =>
                        $metrics['bounces_count'],

                    'entries_count' =>
                        $metrics['entries_count'],

                    'exits_count' =>
                        $metrics['exits_count'],
                ]
            );
        }
    }

    /**
     * @return array{
     *     pageviews_count:int,
     *     bounces_count:int,
     *     entries_count:int,
     *     exits_count:int
     * }
     */
    private function aggregateSessionDimensionMetrics(
        string $dateKey,
        string $field,
        string $dimensionValue
    ): array {
        $sessionsTable =
            $this->database->table(
                'sessions'
            );

        $pageviewsTable =
            $this->database->table(
                'pageviews'
            );

        $unknown =
            $dimensionValue === self::UNKNOWN;

        if ($unknown) {
            $condition = "
                (
                    s.{$field} IS NULL
                    OR TRIM(s.{$field}) = ''
                )
            ";

            $queryArgs = [
                $dateKey,
            ];
        } else {
            $condition = "
                s.{$field} = %s
            ";

            $queryArgs = [
                $dateKey,
                $dimensionValue,
            ];
        }

        $pageviews =
            $this->database->getRow(
                "SELECT
                    COUNT(pv.id)
                        AS pageviews_count,

                    COALESCE(
                        SUM(pv.is_landing),
                        0
                    ) AS entries_count,

                    COALESCE(
                        SUM(pv.is_exit),
                        0
                    ) AS exits_count

                 FROM {$sessionsTable} AS s

                 LEFT JOIN {$pageviewsTable} AS pv
                    ON pv.session_id = s.session_id
                   AND DATE(pv.occurred_at) = %s

                 WHERE DATE(s.started_at) = %s
                   AND {$condition}",
                $dateKey,
                ...$queryArgs
            );

        $bounces =
            $this->database->getVar(
                "SELECT COUNT(*)
                 FROM (
                     SELECT
                         pv.session_id

                     FROM {$pageviewsTable} AS pv

                     INNER JOIN {$sessionsTable} AS s
                         ON s.session_id = pv.session_id

                     WHERE DATE(s.started_at) = %s
                       AND DATE(pv.occurred_at) = %s
                       AND {$condition}

                     GROUP BY
                         pv.session_id

                     HAVING COUNT(*) = 1
                 ) AS bounced_sessions",
                $dateKey,
                $dateKey,
                ...$queryArgs
            );

        return [
            'pageviews_count' =>
                max(
                    0,
                    (int) (
                        $pageviews['pageviews_count']
                        ?? 0
                    )
                ),

            'bounces_count' =>
                max(
                    0,
                    (int) $bounces
                ),

            'entries_count' =>
                max(
                    0,
                    (int) (
                        $pageviews['entries_count']
                        ?? 0
                    )
                ),

            'exits_count' =>
                max(
                    0,
                    (int) (
                        $pageviews['exits_count']
                        ?? 0
                    )
                ),
        ];
    }

    private function countBouncesForDate(
        string $dateKey
    ): int {
        $pageviewsTable =
            $this->database->table(
                'pageviews'
            );

        return max(
            0,
            (int) $this->database->getVar(
                "SELECT COUNT(*)
                 FROM (
                     SELECT session_id
                     FROM {$pageviewsTable}
                     WHERE DATE(occurred_at) = %s
                     GROUP BY session_id
                     HAVING COUNT(*) = 1
                 ) AS bounced_sessions",
                $dateKey
            )
        );
    }

    private function countPageBounces(
        string $dateKey,
        string $urlHash
    ): int {
        $pageviewsTable =
            $this->database->table(
                'pageviews'
            );

        return max(
            0,
            (int) $this->database->getVar(
                "SELECT COUNT(*)
                 FROM (
                     SELECT
                         session_id

                     FROM {$pageviewsTable}

                     WHERE DATE(occurred_at) = %s

                     GROUP BY session_id

                     HAVING COUNT(*) = 1
                 ) AS bounced_sessions

                 INNER JOIN {$pageviewsTable} AS pv
                     ON pv.session_id =
                        bounced_sessions.session_id

                 WHERE DATE(pv.occurred_at) = %s
                   AND pv.url_hash = %s",
                $dateKey,
                $dateKey,
                $urlHash
            )
        );
    }

    private function validateDate(
        string $dateKey
    ): void {
        $date =
            \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $dateKey,
                new \DateTimeZone(
                    'UTC'
                )
            );

        $errors =
            \DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || (
                is_array($errors)
                && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                )
            )
            || $date->format(
                'Y-m-d'
            ) !== $dateKey
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid aggregation date: %s',
                    $dateKey
                )
            );
        }
    }
}