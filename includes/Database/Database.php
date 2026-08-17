<?php

declare(strict_types=1);

namespace VisitorIntelligence\Database;

defined('ABSPATH') || exit;

final class Database
{
    private \wpdb $wpdb;

    private const TABLES = [
        'visitors',
        'sessions',
        'pageviews',
        'events',
        'daily_stats',
    ];

    private const SQL_FORMATS = [
        '%s',
        '%d',
        '%f',
    ];

    public function __construct()
    {
        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException(
                'WordPress database object is not available.'
            );
        }

        $this->wpdb = $wpdb;
    }

    public function table(string $name): string
    {
        $name = trim($name);

        if (
            !in_array(
                $name,
                self::TABLES,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Unknown Visitor Intelligence table: %s',
                    $name
                )
            );
        }

        return $this->wpdb->prefix
            . 'vi_'
            . $name;
    }

    /**
     * @return string[]
     */
    public function getTableNames(): array
    {
        return array_map(
            fn (string $name): string =>
                $this->table($name),
            self::TABLES
        );
    }

    public function tableExists(
        string $name
    ): bool {
        $table = $this->table($name);

        $like = $this->wpdb->esc_like(
            $table
        );

        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $like
            )
        );

        if (
            $result === null
            && $this->getLastError() !== ''
        ) {
            throw new \RuntimeException(
                $this->getLastError()
            );
        }

        return (string) $result === $table;
    }

    public function query(
        string $sql,
        mixed ...$args
    ): int|false {
        $sql = $this->validateSql(
            $sql
        );

        $prepared = $this->prepare(
            $sql,
            ...$args
        );

        $result = $this->wpdb->query(
            $prepared
        );

        if ($result === false) {
            return false;
        }

        if ($result === true) {
            return 1;
        }

        return (int) $result;
    }

    public function execute(
        string $sql,
        mixed ...$args
    ): int {
        $result = $this->query(
            $sql,
            ...$args
        );

        if ($result === false) {
            $error = $this->getLastError();

            throw new \RuntimeException(
                $error !== ''
                    ? $error
                    : 'Visitor Intelligence database query failed.'
            );
        }

        return $result;
    }

    public function prepare(
        string $query,
        mixed ...$args
    ): string {
        $query = $this->validateSql(
            $query
        );

        if ($args === []) {
            return $query;
        }

        $prepared = $this->wpdb->prepare(
            $query,
            ...$args
        );

        if (!is_string($prepared)) {
            throw new \RuntimeException(
                'Unable to prepare Visitor Intelligence database query.'
            );
        }

        return $prepared;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRow(
        string $query,
        mixed ...$args
    ): ?array {
        $query = $this->prepare(
            $query,
            ...$args
        );

        $result = $this->wpdb->get_row(
            $query,
            ARRAY_A
        );

        if ($result === null) {
            $error = $this->getLastError();

            if ($error !== '') {
                throw new \RuntimeException(
                    $error
                );
            }

            return null;
        }

        if (!is_array($result)) {
            $error = $this->getLastError();

            throw new \RuntimeException(
                $error !== ''
                    ? $error
                    : 'Visitor Intelligence database row query returned invalid data.'
            );
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getResults(
        string $query,
        mixed ...$args
    ): array {
        $query = $this->prepare(
            $query,
            ...$args
        );

        $results = $this->wpdb->get_results(
            $query,
            ARRAY_A
        );

        if ($results === null) {
            $error = $this->getLastError();

            throw new \RuntimeException(
                $error !== ''
                    ? $error
                    : 'Visitor Intelligence database results query failed.'
            );
        }

        if (!is_array($results)) {
            throw new \RuntimeException(
                'Visitor Intelligence database returned invalid results.'
            );
        }

        foreach ($results as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException(
                    'Visitor Intelligence database returned an invalid result row.'
                );
            }
        }

        return $results;
    }

    public function getVar(
        string $query,
        mixed ...$args
    ): mixed {
        $query = $this->prepare(
            $query,
            ...$args
        );

        $result = $this->wpdb->get_var(
            $query
        );

        $error = $this->getLastError();

        if (
            $result === null
            && $error !== ''
        ) {
            throw new \RuntimeException(
                $error
            );
        }

        return $result;
    }

    public function insert(
        string $table,
        array $data,
        array $format = []
    ): int|false {
        $this->assertTableName(
            $table
        );

        $this->assertData(
            $data
        );

        $format = $this->normalizeFormat(
            $data,
            $format
        );

        $result = $this->wpdb->insert(
            $table,
            $data,
            $format
        );

        if ($result === false) {
            return false;
        }

        if ($result === true) {
            return 1;
        }

        return (int) $result;
    }

    public function insertGetId(
        string $table,
        array $data,
        array $format = []
    ): int {
        $result = $this->insert(
            $table,
            $data,
            $format
        );

        if ($result === false) {
            $error = $this->getLastError();

            throw new \RuntimeException(
                $error !== ''
                    ? $error
                    : 'Visitor Intelligence database insert failed.'
            );
        }

        return $this->lastInsertId();
    }

    public function update(
        string $table,
        array $data,
        array $where,
        array $format = [],
        array $whereFormat = []
    ): int|false {
        $this->assertTableName(
            $table
        );

        $this->assertData(
            $data
        );

        $this->assertData(
            $where
        );

        $format = $this->normalizeFormat(
            $data,
            $format
        );

        $whereFormat = $this->normalizeFormat(
            $where,
            $whereFormat
        );

        $result = $this->wpdb->update(
            $table,
            $data,
            $where,
            $format,
            $whereFormat
        );

        if ($result === false) {
            return false;
        }

        if ($result === true) {
            return 1;
        }

        return (int) $result;
    }

    public function delete(
        string $table,
        array $where,
        array $whereFormat = []
    ): int|false {
        $this->assertTableName(
            $table
        );

        $this->assertData(
            $where
        );

        $whereFormat = $this->normalizeFormat(
            $where,
            $whereFormat
        );

        $result = $this->wpdb->delete(
            $table,
            $where,
            $whereFormat
        );

        if ($result === false) {
            return false;
        }

        if ($result === true) {
            return 1;
        }

        return (int) $result;
    }

    public function beginTransaction(): void
    {
        $this->execute(
            'START TRANSACTION'
        );
    }

    public function commit(): void
    {
        $this->execute(
            'COMMIT'
        );
    }

    public function rollback(): void
    {
        $this->execute(
            'ROLLBACK'
        );
    }

    public function lastInsertId(): int
    {
        return (int) $this->wpdb->insert_id;
    }

    public function getLastError(): string
    {
        return trim(
            (string) $this->wpdb->last_error
        );
    }

    public function getWpdb(): \wpdb
    {
        return $this->wpdb;
    }

    private function assertTableName(
        string $table
    ): void {
        $table = trim($table);

        if (
            !in_array(
                $table,
                $this->getTableNames(),
                true
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid Visitor Intelligence table name: %s',
                    $table
                )
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertData(
        array $data
    ): void {
        if ($data === []) {
            throw new \InvalidArgumentException(
                'Visitor Intelligence database data cannot be empty.'
            );
        }

        foreach ($data as $column => $value) {
            if (
                !is_string($column)
                || preg_match(
                    '/^[A-Za-z_][A-Za-z0-9_]*$/',
                    $column
                ) !== 1
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Invalid Visitor Intelligence database column: %s',
                        (string) $column
                    )
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $format
     * @return array<int, string>
     */
    private function normalizeFormat(
        array $data,
        array $format
    ): array {
        if ($format === []) {
            return array_fill(
                0,
                count($data),
                '%s'
            );
        }

        if (
            count($format)
            !== count($data)
        ) {
            throw new \InvalidArgumentException(
                'Visitor Intelligence database format count does not match data count.'
            );
        }

        foreach ($format as $item) {
            if (
                !in_array(
                    $item,
                    self::SQL_FORMATS,
                    true
                )
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Invalid Visitor Intelligence database format: %s',
                        $item
                    )
                );
            }
        }

        return array_values(
            $format
        );
    }

    private function validateSql(
        string $sql
    ): string {
        $sql = trim($sql);

        if ($sql === '') {
            throw new \InvalidArgumentException(
                'Visitor Intelligence database SQL query cannot be empty.'
            );
        }

        return $sql;
    }
}