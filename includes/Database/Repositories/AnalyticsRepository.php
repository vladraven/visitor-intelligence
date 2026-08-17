<?php

declare(strict_types=1);

namespace VisitorIntelligence\Database\Repositories;

use VisitorIntelligence\Database\Database;

defined('ABSPATH') || exit;

final class AnalyticsRepository
{
    public function __construct(
        private readonly Database $database
    ) {
    }

    /**
     * @return array{
     *     visitors:int,
     *     sessions:int,
     *     pageviews:int,
     *     active_seconds:int,
     *     bounces:int,
     *     entries:int,
     *     exits:int
     * }
     */
    public function getPeriodSummary(
        string $fromDate,
        string $toDate
    ): array {
        $fromDate = trim($fromDate);
        $toDate = trim($toDate);

        $this->validateDate($fromDate);
        $this->validateDate($toDate);

        if ($fromDate > $toDate) {
            throw new \InvalidArgumentException(
                'Analytics range start cannot be after range end.'
            );
        }

        $sessionsTable =
            $this->database->table(
                'sessions'
            );

        $pageviewsTable =
            $this->database->table(
                'pageviews'
            );

        $sessions =
            $this->database->getRow(
                "SELECT
                    COUNT(
                        DISTINCT visitor_id
                    ) AS visitors,

                    COUNT(
                        DISTINCT session_id
                    ) AS sessions,

                    COALESCE(
                        SUM(active_seconds),
                        0
                    ) AS active_seconds

                 FROM {$sessionsTable}

                 WHERE DATE(started_at)
                       BETWEEN %s AND %s",
                $fromDate,
                $toDate
            );

        $pageviews =
            $this->database->getRow(
                "SELECT
                    COUNT(*) AS pageviews,

                    COALESCE(
                        SUM(is_landing),
                        0
                    ) AS entries,

                    COALESCE(
                        SUM(is_exit),
                        0
                    ) AS exits

                 FROM {$pageviewsTable}

                 WHERE DATE(occurred_at)
                       BETWEEN %s AND %s",
                $fromDate,
                $toDate
            );

        /*
         * Bounce is a property of the complete session.
         *
         * A session is a bounce when it contains exactly one
         * pageview in its complete lifetime.
         *
         * The bounce is included in the requested period
         * according to the session start date.
         */
        $bounces =
            $this->database->getVar(
                "SELECT COUNT(*)

                 FROM {$sessionsTable} AS s

                 INNER JOIN (
                     SELECT
                         session_id,
                         COUNT(*) AS pageview_count

                     FROM {$pageviewsTable}

                     GROUP BY session_id

                     HAVING COUNT(*) = 1
                 ) AS single_pageview_sessions
                     ON single_pageview_sessions.session_id
                        = s.session_id

                 WHERE DATE(s.started_at)
                       BETWEEN %s AND %s",
                $fromDate,
                $toDate
            );

        return [
            'visitors' =>
                max(
                    0,
                    (int) (
                        $sessions['visitors']
                        ?? 0
                    )
                ),

            'sessions' =>
                max(
                    0,
                    (int) (
                        $sessions['sessions']
                        ?? 0
                    )
                ),

            'pageviews' =>
                max(
                    0,
                    (int) (
                        $pageviews['pageviews']
                        ?? 0
                    )
                ),

            'active_seconds' =>
                max(
                    0,
                    (int) (
                        $sessions['active_seconds']
                        ?? 0
                    )
                ),

            'bounces' =>
                max(
                    0,
                    (int) $bounces
                ),

            'entries' =>
                max(
                    0,
                    (int) (
                        $pageviews['entries']
                        ?? 0
                    )
                ),

            'exits' =>
                max(
                    0,
                    (int) (
                        $pageviews['exits']
                        ?? 0
                    )
                ),
        ];
    }

    /**
     * Returns daily traffic for an arbitrary period.
     *
     * Every calendar day in the requested range is returned,
     * including days with zero traffic.
     *
     * @return array<int, array{
     *     date:string,
     *     visitors:int,
     *     sessions:int,
     *     pageviews:int,
     *     active_seconds:int,
     *     bounces:int,
     *     entries:int,
     *     exits:int
     * }>
     */
    public function getDailyTrend(
        string $fromDate,
        string $toDate
    ): array {
        $fromDate = trim($fromDate);
        $toDate = trim($toDate);

        $this->validateDate($fromDate);
        $this->validateDate($toDate);

        if ($fromDate > $toDate) {
            throw new \InvalidArgumentException(
                'Analytics range start cannot be after range end.'
            );
        }

        $sessionsTable =
            $this->database->table(
                'sessions'
            );

        $pageviewsTable =
            $this->database->table(
                'pageviews'
            );

        $sessionRows =
            $this->database->getResults(
                "SELECT
                    DATE(started_at) AS date_key,

                    COUNT(
                        DISTINCT visitor_id
                    ) AS visitors,

                    COUNT(
                        DISTINCT session_id
                    ) AS sessions,

                    COALESCE(
                        SUM(active_seconds),
                        0
                    ) AS active_seconds

                 FROM {$sessionsTable}

                 WHERE DATE(started_at)
                       BETWEEN %s AND %s

                 GROUP BY DATE(started_at)

                 ORDER BY date_key ASC",
                $fromDate,
                $toDate
            );

        $pageviewRows =
            $this->database->getResults(
                "SELECT
                    DATE(occurred_at) AS date_key,

                    COUNT(*) AS pageviews,

                    COALESCE(
                        SUM(is_landing),
                        0
                    ) AS entries,

                    COALESCE(
                        SUM(is_exit),
                        0
                    ) AS exits

                 FROM {$pageviewsTable}

                 WHERE DATE(occurred_at)
                       BETWEEN %s AND %s

                 GROUP BY DATE(occurred_at)

                 ORDER BY date_key ASC",
                $fromDate,
                $toDate
            );

        /*
         * Bounce is a property of the complete session.
         *
         * A session is a bounce when it contains exactly one
         * pageview in its complete lifetime.
         *
         * The bounce is assigned to the calendar day on which
         * that session started.
         */
        $bounceRows =
            $this->database->getResults(
                "SELECT
                    DATE(s.started_at) AS date_key,
                    COUNT(*) AS bounces

                 FROM {$sessionsTable} AS s

                 INNER JOIN (
                     SELECT
                         session_id,
                         COUNT(*) AS pageview_count

                     FROM {$pageviewsTable}

                     GROUP BY session_id

                     HAVING COUNT(*) = 1
                 ) AS single_pageview_sessions
                     ON single_pageview_sessions.session_id
                        = s.session_id

                 WHERE DATE(s.started_at)
                       BETWEEN %s AND %s

                 GROUP BY DATE(s.started_at)

                 ORDER BY date_key ASC",
                $fromDate,
                $toDate
            );

        $sessionsByDate = [];

        foreach ($sessionRows as $row) {
            $date =
                (string) (
                    $row['date_key']
                    ?? ''
                );

            if ($date === '') {
                continue;
            }

            $sessionsByDate[$date] = [
                'visitors' =>
                    max(
                        0,
                        (int) (
                            $row['visitors']
                            ?? 0
                        )
                    ),

                'sessions' =>
                    max(
                        0,
                        (int) (
                            $row['sessions']
                            ?? 0
                        )
                    ),

                'active_seconds' =>
                    max(
                        0,
                        (int) (
                            $row['active_seconds']
                            ?? 0
                        )
                    ),
            ];
        }

        $pageviewsByDate = [];

        foreach ($pageviewRows as $row) {
            $date =
                (string) (
                    $row['date_key']
                    ?? ''
                );

            if ($date === '') {
                continue;
            }

            $pageviewsByDate[$date] = [
                'pageviews' =>
                    max(
                        0,
                        (int) (
                            $row['pageviews']
                            ?? 0
                        )
                    ),

                'entries' =>
                    max(
                        0,
                        (int) (
                            $row['entries']
                            ?? 0
                        )
                    ),

                'exits' =>
                    max(
                        0,
                        (int) (
                            $row['exits']
                            ?? 0
                        )
                    ),
            ];
        }

        $bouncesByDate = [];

        foreach ($bounceRows as $row) {
            $date =
                (string) (
                    $row['date_key']
                    ?? ''
                );

            if ($date === '') {
                continue;
            }

            $bouncesByDate[$date] =
                max(
                    0,
                    (int) (
                        $row['bounces']
                        ?? 0
                    )
                );
        }

        $result = [];

        $current =
            new \DateTimeImmutable(
                $fromDate,
                new \DateTimeZone('UTC')
            );

        $end =
            new \DateTimeImmutable(
                $toDate,
                new \DateTimeZone('UTC')
            );

        while ($current <= $end) {
            $date =
                $current->format('Y-m-d');

            $session =
                $sessionsByDate[$date]
                ?? [];

            $pageview =
                $pageviewsByDate[$date]
                ?? [];

            $result[] = [
                'date' => $date,

                'visitors' =>
                    max(
                        0,
                        (int) (
                            $session['visitors']
                            ?? 0
                        )
                    ),

                'sessions' =>
                    max(
                        0,
                        (int) (
                            $session['sessions']
                            ?? 0
                        )
                    ),

                'pageviews' =>
                    max(
                        0,
                        (int) (
                            $pageview['pageviews']
                            ?? 0
                        )
                    ),

                'active_seconds' =>
                    max(
                        0,
                        (int) (
                            $session['active_seconds']
                            ?? 0
                        )
                    ),

                'bounces' =>
                    max(
                        0,
                        (int) (
                            $bouncesByDate[$date]
                            ?? 0
                        )
                    ),

                'entries' =>
                    max(
                        0,
                        (int) (
                            $pageview['entries']
                            ?? 0
                        )
                    ),

                'exits' =>
                    max(
                        0,
                        (int) (
                            $pageview['exits']
                            ?? 0
                        )
                    ),
            ];

            $current =
                $current->modify(
                    '+1 day'
                );
        }

        return $result;
    }

    /**
     * Returns hourly traffic for one calendar day.
     *
     * All 24 hours are returned, including hours with zero traffic.
     *
     * @return array<int, array{
     *     hour:int,
     *     label:string,
     *     visitors:int,
     *     sessions:int,
     *     pageviews:int,
     *     active_seconds:int,
     *     bounces:int,
     *     entries:int,
     *     exits:int
     * }>
     */
    public function getHourlyTrend(
        string $date
    ): array {
        $date = trim($date);

        $this->validateDate($date);

        $sessionsTable =
            $this->database->table(
                'sessions'
            );

        $pageviewsTable =
            $this->database->table(
                'pageviews'
            );

        $sessionRows =
            $this->database->getResults(
                "SELECT
                    HOUR(started_at) AS hour_key,

                    COUNT(
                        DISTINCT visitor_id
                    ) AS visitors,

                    COUNT(
                        DISTINCT session_id
                    ) AS sessions,

                    COALESCE(
                        SUM(active_seconds),
                        0
                    ) AS active_seconds

                 FROM {$sessionsTable}

                 WHERE DATE(started_at) = %s

                 GROUP BY HOUR(started_at)

                 ORDER BY hour_key ASC",
                $date
            );

        $pageviewRows =
            $this->database->getResults(
                "SELECT
                    HOUR(occurred_at) AS hour_key,

                    COUNT(*) AS pageviews,

                    COALESCE(
                        SUM(is_landing),
                        0
                    ) AS entries,

                    COALESCE(
                        SUM(is_exit),
                        0
                    ) AS exits

                 FROM {$pageviewsTable}

                 WHERE DATE(occurred_at) = %s

                 GROUP BY HOUR(occurred_at)

                 ORDER BY hour_key ASC",
                $date
            );

        /*
         * Bounce belongs to the complete session.
         *
         * Therefore:
         *
         * 1. Find sessions having exactly one pageview.
         * 2. Join them to sessions.
         * 3. Assign the bounce to the hour in which
         *    the session started.
         */
        $bounceRows =
            $this->database->getResults(
                "SELECT
                    HOUR(s.started_at) AS hour_key,
                    COUNT(*) AS bounces

                 FROM {$sessionsTable} AS s

                 INNER JOIN (
                     SELECT
                         session_id,
                         COUNT(*) AS pageview_count

                     FROM {$pageviewsTable}

                     GROUP BY session_id

                     HAVING COUNT(*) = 1
                 ) AS single_pageview_sessions
                     ON single_pageview_sessions.session_id
                        = s.session_id

                 WHERE DATE(s.started_at) = %s

                 GROUP BY HOUR(s.started_at)

                 ORDER BY hour_key ASC",
                $date
            );

        $sessionsByHour = [];

        foreach ($sessionRows as $row) {
            $hour =
                (int) (
                    $row['hour_key']
                    ?? 0
                );

            if ($hour < 0 || $hour > 23) {
                continue;
            }

            $sessionsByHour[$hour] = [
                'visitors' =>
                    max(
                        0,
                        (int) (
                            $row['visitors']
                            ?? 0
                        )
                    ),

                'sessions' =>
                    max(
                        0,
                        (int) (
                            $row['sessions']
                            ?? 0
                        )
                    ),

                'active_seconds' =>
                    max(
                        0,
                        (int) (
                            $row['active_seconds']
                            ?? 0
                        )
                    ),
            ];
        }

        $pageviewsByHour = [];

        foreach ($pageviewRows as $row) {
            $hour =
                (int) (
                    $row['hour_key']
                    ?? 0
                );

            if ($hour < 0 || $hour > 23) {
                continue;
            }

            $pageviewsByHour[$hour] = [
                'pageviews' =>
                    max(
                        0,
                        (int) (
                            $row['pageviews']
                            ?? 0
                        )
                    ),

                'entries' =>
                    max(
                        0,
                        (int) (
                            $row['entries']
                            ?? 0
                        )
                    ),

                'exits' =>
                    max(
                        0,
                        (int) (
                            $row['exits']
                            ?? 0
                        )
                    ),
            ];
        }

        $bouncesByHour = [];

        foreach ($bounceRows as $row) {
            $hour =
                (int) (
                    $row['hour_key']
                    ?? 0
                );

            if ($hour < 0 || $hour > 23) {
                continue;
            }

            $bouncesByHour[$hour] =
                max(
                    0,
                    (int) (
                        $row['bounces']
                        ?? 0
                    )
                );
        }

        $result = [];

        for ($hour = 0; $hour <= 23; $hour++) {
            $session =
                $sessionsByHour[$hour]
                ?? [];

            $pageview =
                $pageviewsByHour[$hour]
                ?? [];

            $result[] = [
                'hour' => $hour,

                'label' =>
                    sprintf(
                        '%02d:00',
                        $hour
                    ),

                'visitors' =>
                    max(
                        0,
                        (int) (
                            $session['visitors']
                            ?? 0
                        )
                    ),

                'sessions' =>
                    max(
                        0,
                        (int) (
                            $session['sessions']
                            ?? 0
                        )
                    ),

                'pageviews' =>
                    max(
                        0,
                        (int) (
                            $pageview['pageviews']
                            ?? 0
                        )
                    ),

                'active_seconds' =>
                    max(
                        0,
                        (int) (
                            $session['active_seconds']
                            ?? 0
                        )
                    ),

                'bounces' =>
                    max(
                        0,
                        (int) (
                            $bouncesByHour[$hour]
                            ?? 0
                        )
                    ),

                'entries' =>
                    max(
                        0,
                        (int) (
                            $pageview['entries']
                            ?? 0
                        )
                    ),

                'exits' =>
                    max(
                        0,
                        (int) (
                            $pageview['exits']
                            ?? 0
                        )
                    ),
            ];
        }

        return $result;
    }

    /**
     * Returns the most visited pages for an arbitrary period.
     *
     * @return array<int, array{
     *     url:string,
     *     visitors:int,
     *     sessions:int,
     *     pageviews:int,
     *     active_seconds:int,
     *     entries:int,
     *     exits:int
     * }>
     */
    public function getTopPages(
        string $fromDate,
        string $toDate,
        int $limit = 50
    ): array {
        $fromDate = trim($fromDate);
        $toDate = trim($toDate);

        $this->validateDate($fromDate);
        $this->validateDate($toDate);

        if ($fromDate > $toDate) {
            throw new \InvalidArgumentException(
                'Analytics range start cannot be after range end.'
            );
        }

        $limit = max(
            1,
            min(
                500,
                $limit
            )
        );

        $pageviewsTable =
            $this->database->table(
                'pageviews'
            );

        $query = sprintf(
            "SELECT
                url,

                COUNT(
                    DISTINCT visitor_id
                ) AS visitors,

                COUNT(
                    DISTINCT session_id
                ) AS sessions,

                COUNT(*) AS pageviews,

                COALESCE(
                    SUM(active_seconds),
                    0
                ) AS active_seconds,

                COALESCE(
                    SUM(is_landing),
                    0
                ) AS entries,

                COALESCE(
                    SUM(is_exit),
                    0
                ) AS exits

             FROM {$pageviewsTable}

             WHERE DATE(occurred_at)
                   BETWEEN %%s AND %%s

               AND url IS NOT NULL
               AND TRIM(url) <> ''

             GROUP BY url

             ORDER BY pageviews DESC, url ASC

             LIMIT %d",
            $limit
        );

        $rows =
            $this->database->getResults(
                $query,
                $fromDate,
                $toDate
            );

        $result = [];

        foreach ($rows as $row) {
            $url =
                trim(
                    (string) (
                        $row['url']
                        ?? ''
                    )
                );

            if ($url === '') {
                continue;
            }

            $result[] = [
                'url' => $url,

                'visitors' =>
                    max(
                        0,
                        (int) (
                            $row['visitors']
                            ?? 0
                        )
                    ),

                'sessions' =>
                    max(
                        0,
                        (int) (
                            $row['sessions']
                            ?? 0
                        )
                    ),

                'pageviews' =>
                    max(
                        0,
                        (int) (
                            $row['pageviews']
                            ?? 0
                        )
                    ),

                'active_seconds' =>
                    max(
                        0,
                        (int) (
                            $row['active_seconds']
                            ?? 0
                        )
                    ),

                'entries' =>
                    max(
                        0,
                        (int) (
                            $row['entries']
                            ?? 0
                        )
                    ),

                'exits' =>
                    max(
                        0,
                        (int) (
                            $row['exits']
                            ?? 0
                        )
                    ),
            ];
        }

        return $result;
    }

    private function validateDate(
        string $date
    ): void {
        $parsed =
            \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $date,
                new \DateTimeZone('UTC')
            );

        $errors =
            \DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || (
                is_array($errors)
                && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                )
            )
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid analytics date: %s',
                    $date
                )
            );
        }
    }
}