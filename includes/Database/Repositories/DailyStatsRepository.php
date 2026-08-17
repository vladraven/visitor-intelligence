<?php

declare(strict_types=1);

namespace VisitorIntelligence\Database\Repositories;

defined('ABSPATH') || exit;

final class DailyStatsRepository extends AbstractRepository
{
    private const DIMENSIONS = [
        'overview',
        'page',
        'source',
        'country',
        'region',
        'device',
        'browser',
        'os',
    ];

    private const METRICS = [
        'visitors_count',
        'sessions_count',
        'pageviews_count',
        'active_seconds_total',
        'bounces_count',
        'entries_count',
        'exits_count',
    ];

    protected function table(): string
    {
        return $this->database->table('daily_stats');
    }

    protected function identifierColumn(): string
    {
        return 'id';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(
        string $dateKey,
        string $dimensionType,
        string $dimensionValue = 'total'
    ): ?array {
        $dateKey = trim($dateKey);
        $dimensionType = trim($dimensionType);

        $this->validateDate($dateKey);
        $this->validateDimension($dimensionType);

        $dimensionValue = $this->normalizeDimensionValue(
            $dimensionValue
        );

        $table = $this->table();

        $row = $this->database->getRow(
            "SELECT *
             FROM {$table}
             WHERE date_key = %s
               AND dimension_type = %s
               AND dimension_value = %s
             LIMIT 1",
            $dateKey,
            $dimensionType,
            $dimensionValue
        );

        if ($row === false || $row === null) {
            return null;
        }

        if (!is_array($row)) {
            throw new \RuntimeException(
                'Visitor Intelligence database returned an invalid daily stats row.'
            );
        }

        return $row;
    }

    public function create(
        string $dateKey,
        string $dimensionType,
        string $dimensionValue = 'total'
    ): int {
        $dateKey = trim($dateKey);
        $dimensionType = trim($dimensionType);

        $this->validateDate($dateKey);
        $this->validateDimension($dimensionType);

        $dimensionValue = $this->normalizeDimensionValue(
            $dimensionValue
        );

        $existing = $this->find(
            $dateKey,
            $dimensionType,
            $dimensionValue
        );

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        try {
            return $this->insertRecord(
                [
                    'date_key' =>
                        $dateKey,

                    'dimension_type' =>
                        $dimensionType,

                    'dimension_value' =>
                        $dimensionValue,

                    'visitors_count' =>
                        0,

                    'sessions_count' =>
                        0,

                    'pageviews_count' =>
                        0,

                    'active_seconds_total' =>
                        0,

                    'bounces_count' =>
                        0,

                    'entries_count' =>
                        0,

                    'exits_count' =>
                        0,

                    'updated_at' =>
                        $this->nowUtc(),
                ],
                [
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%d',
                    '%d',
                    '%d',
                    '%d',
                    '%d',
                    '%d',
                ]
            );
        } catch (\RuntimeException $exception) {
            $existing = $this->find(
                $dateKey,
                $dimensionType,
                $dimensionValue
            );

            if ($existing !== null) {
                return (int) $existing['id'];
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function persist(
        string $dateKey,
        string $dimensionType,
        string $dimensionValue,
        array $metrics
    ): int {
        return $this->upsert(
            $dateKey,
            $dimensionType,
            $dimensionValue,
            $metrics
        );
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function upsert(
        string $dateKey,
        string $dimensionType,
        string $dimensionValue,
        array $metrics
    ): int {
        $dateKey = trim($dateKey);
        $dimensionType = trim($dimensionType);

        $this->validateDate($dateKey);
        $this->validateDimension($dimensionType);

        $dimensionValue = $this->normalizeDimensionValue(
            $dimensionValue
        );

        $metrics = $this->normalizeMetrics(
            $metrics
        );

        $values = [
            'visitors_count' =>
                0,

            'sessions_count' =>
                0,

            'pageviews_count' =>
                0,

            'active_seconds_total' =>
                0,

            'bounces_count' =>
                0,

            'entries_count' =>
                0,

            'exits_count' =>
                0,
        ];

        foreach ($metrics as $field => $value) {
            $values[$field] = $value;
        }

        $table = $this->table();

        $this->database->execute(
            "INSERT INTO {$table}
            (
                date_key,
                dimension_type,
                dimension_value,
                visitors_count,
                sessions_count,
                pageviews_count,
                active_seconds_total,
                bounces_count,
                entries_count,
                exits_count,
                updated_at
            )
            VALUES
            (
                %s,
                %s,
                %s,
                %d,
                %d,
                %d,
                %d,
                %d,
                %d,
                %d,
                %s
            )
            ON DUPLICATE KEY UPDATE
                visitors_count =
                    VALUES(visitors_count),
                sessions_count =
                    VALUES(sessions_count),
                pageviews_count =
                    VALUES(pageviews_count),
                active_seconds_total =
                    VALUES(active_seconds_total),
                bounces_count =
                    VALUES(bounces_count),
                entries_count =
                    VALUES(entries_count),
                exits_count =
                    VALUES(exits_count),
                updated_at =
                    VALUES(updated_at)",
            $dateKey,
            $dimensionType,
            $dimensionValue,
            $values['visitors_count'],
            $values['sessions_count'],
            $values['pageviews_count'],
            $values['active_seconds_total'],
            $values['bounces_count'],
            $values['entries_count'],
            $values['exits_count'],
            $this->nowUtc()
        );

        $existing = $this->find(
            $dateKey,
            $dimensionType,
            $dimensionValue
        );

        if ($existing === null) {
            throw new \RuntimeException(
                'Unable to persist Visitor Intelligence daily stats row.'
            );
        }

        return (int) $existing['id'];
    }

    /**
     * @param array<string, int> $increments
     */
    public function increment(
        string $dateKey,
        string $dimensionType,
        string $dimensionValue,
        array $increments
    ): int {
        $dateKey = trim($dateKey);
        $dimensionType = trim($dimensionType);

        $this->validateDate($dateKey);
        $this->validateDimension($dimensionType);

        $dimensionValue = $this->normalizeDimensionValue(
            $dimensionValue
        );

        $increments = $this->normalizeMetrics(
            $increments
        );

        if ($increments === []) {
            return $this->create(
                $dateKey,
                $dimensionType,
                $dimensionValue
            );
        }

        $values = [
            'visitors_count' =>
                0,

            'sessions_count' =>
                0,

            'pageviews_count' =>
                0,

            'active_seconds_total' =>
                0,

            'bounces_count' =>
                0,

            'entries_count' =>
                0,

            'exits_count' =>
                0,
        ];

        foreach (
            $increments as $field => $amount
        ) {
            $values[$field] = $amount;
        }

        $assignments = [];

        foreach (
            self::METRICS as $field
        ) {
            if (
                !array_key_exists(
                    $field,
                    $increments
                )
            ) {
                continue;
            }

            $assignments[] =
                "{$field} = "
                . "{$field} + "
                . "VALUES({$field})";
        }

        $assignments[] =
            'updated_at = VALUES(updated_at)';

        $table = $this->table();

        $this->database->execute(
            "INSERT INTO {$table}
            (
                date_key,
                dimension_type,
                dimension_value,
                visitors_count,
                sessions_count,
                pageviews_count,
                active_seconds_total,
                bounces_count,
                entries_count,
                exits_count,
                updated_at
            )
            VALUES
            (
                %s,
                %s,
                %s,
                %d,
                %d,
                %d,
                %d,
                %d,
                %d,
                %d,
                %s
            )
            ON DUPLICATE KEY UPDATE "
            . implode(
                ', ',
                $assignments
            ),
            $dateKey,
            $dimensionType,
            $dimensionValue,
            $values['visitors_count'],
            $values['sessions_count'],
            $values['pageviews_count'],
            $values['active_seconds_total'],
            $values['bounces_count'],
            $values['entries_count'],
            $values['exits_count'],
            $this->nowUtc()
        );

        $existing = $this->find(
            $dateKey,
            $dimensionType,
            $dimensionValue
        );

        if ($existing === null) {
            throw new \RuntimeException(
                'Unable to increment Visitor Intelligence daily stats row.'
            );
        }

        return (int) $existing['id'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByDate(
        string $dateKey,
        ?string $dimensionType = null,
        int $limit = 1000
    ): array {
        $dateKey = trim($dateKey);

        $this->validateDate($dateKey);

        $where = [
            'date_key' =>
                $dateKey,
        ];

        $formats = [
            '%s',
        ];

        if ($dimensionType !== null) {
            $dimensionType =
                trim($dimensionType);

            $this->validateDimension(
                $dimensionType
            );

            $where['dimension_type'] =
                $dimensionType;

            $formats[] = '%s';
        }

        return $this->findMany(
            $where,
            $formats,
            'dimension_type',
            'ASC',
            $this->normalizeLimit(
                $limit
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findRange(
        string $fromDate,
        string $toDate,
        ?string $dimensionType = null,
        int $limit = 5000
    ): array {
        $fromDate = trim($fromDate);
        $toDate = trim($toDate);

        $this->validateDate(
            $fromDate
        );

        $this->validateDate(
            $toDate
        );

        if ($fromDate > $toDate) {
            throw new \InvalidArgumentException(
                'Daily stats range start cannot be after range end.'
            );
        }

        $limit = max(
            1,
            min(
                $limit,
                50000
            )
        );

        $table = $this->table();

        if ($dimensionType !== null) {
            $dimensionType =
                trim($dimensionType);

            $this->validateDimension(
                $dimensionType
            );

            $results =
                $this->database->getResults(
                    "SELECT *
                     FROM {$table}
                     WHERE date_key BETWEEN %s AND %s
                       AND dimension_type = %s
                     ORDER BY
                         date_key ASC,
                         dimension_value ASC
                     LIMIT {$limit}",
                    $fromDate,
                    $toDate,
                    $dimensionType
                );
        } else {
            $results =
                $this->database->getResults(
                    "SELECT *
                     FROM {$table}
                     WHERE date_key BETWEEN %s AND %s
                     ORDER BY
                         date_key ASC,
                         dimension_type ASC,
                         dimension_value ASC
                     LIMIT {$limit}",
                    $fromDate,
                    $toDate
                );
        }

        if (
            $results === false
            || $results === null
        ) {
            return [];
        }

        if (!is_array($results)) {
            throw new \RuntimeException(
                'Visitor Intelligence database returned invalid daily stats range data.'
            );
        }

        return $results;
    }

    public function deleteByDate(
        string $dateKey
    ): int {
        $dateKey = trim($dateKey);

        $this->validateDate(
            $dateKey
        );

        $table = $this->table();

        return $this->database->execute(
            "DELETE FROM {$table}
             WHERE date_key = %s",
            $dateKey
        );
    }

    /**
     * @return string[]
     */
    public function getSupportedDimensions(): array
    {
        return self::DIMENSIONS;
    }

    private function normalizeDimensionValue(
        string $dimensionValue
    ): string {
        $dimensionValue = trim(
            $dimensionValue
        );

        if ($dimensionValue === '') {
            return 'total';
        }

        if (
            (
                function_exists(
                    'mb_strlen'
                )
                    ? mb_strlen(
                        $dimensionValue,
                        'UTF-8'
                    )
                    : strlen($dimensionValue)
            ) > 255
        ) {
            throw new \InvalidArgumentException(
                'Daily stats dimension value cannot exceed 255 characters.'
            );
        }

        return $dimensionValue;
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, int>
     */
    private function normalizeMetrics(
        array $metrics
    ): array {
        $result = [];

        foreach (
            $metrics as $field => $value
        ) {
            if (
                !in_array(
                    $field,
                    self::METRICS,
                    true
                )
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Unsupported daily stats metric: %s',
                        $field
                    )
                );
            }

            if (
                !is_int($value)
                && !(
                    is_string($value)
                    && preg_match(
                        '/^[0-9]+$/',
                        $value
                    ) === 1
                )
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Daily stats metric must be a non-negative integer: %s',
                        $field
                    )
                );
            }

            $value = (int) $value;

            if ($value < 0) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Daily stats metric cannot be negative: %s',
                        $field
                    )
                );
            }

            $result[$field] = $value;
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

        if (
            $parsed === false
            || $parsed->format(
                'Y-m-d'
            ) !== $date
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid daily stats date: %s',
                    $date
                )
            );
        }
    }

    private function validateDimension(
        string $dimensionType
    ): void {
        if (
            !in_array(
                $dimensionType,
                self::DIMENSIONS,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Unsupported daily stats dimension: %s',
                    $dimensionType
                )
            );
        }
    }

    private function normalizeLimit(
        int $limit
    ): int {
        return max(
            1,
            min(
                $limit,
                1000
            )
        );
    }
}