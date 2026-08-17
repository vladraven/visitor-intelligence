<?php

declare(strict_types=1);

namespace VisitorIntelligence\Database\Repositories;

use VisitorIntelligence\Database\Database;

defined('ABSPATH') || exit;

abstract class AbstractRepository
{
    private const MAX_QUERY_LIMIT = 1000;

    private const ALLOWED_FORMATS = [
        '%s',
        '%d',
        '%f',
    ];

    public function __construct(
        protected readonly Database $database
    ) {
    }

    abstract protected function table(): string;

    abstract protected function identifierColumn(): string;

    /**
     * @return array<string, mixed>|null
     */
    protected function findByIdentifier(
        string $identifier
    ): ?array {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $table = $this->table();
        $column = $this->identifierColumn();

        $this->assertIdentifier($column);

        $row = $this->database->getRow(
            "SELECT *
             FROM {$table}
             WHERE {$column} = %s
             LIMIT 1",
            $identifier
        );

        if ($row === false || $row === null) {
            return null;
        }

        if (!is_array($row)) {
            throw new \RuntimeException(
                'Visitor Intelligence database returned an invalid row.'
            );
        }

        return $row;
    }

    protected function existsByIdentifier(
        string $identifier
    ): bool {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return false;
        }

        $table = $this->table();
        $column = $this->identifierColumn();

        $this->assertIdentifier($column);

        $result = $this->database->getVar(
            "SELECT 1
             FROM {$table}
             WHERE {$column} = %s
             LIMIT 1",
            $identifier
        );

        return $result !== null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $format
     */
    protected function insertRecord(
        array $data,
        array $format = []
    ): int {
        if ($data === []) {
            throw new \InvalidArgumentException(
                'Cannot insert an empty Visitor Intelligence record.'
            );
        }

        $this->validateFormat(
            $data,
            $format,
            'insert'
        );

        $result = $this->database->insert(
            $this->table(),
            $data,
            $format
        );

        if ($result === false) {
            $error = trim(
                $this->database->getLastError()
            );

            throw new \RuntimeException(
                $error !== ''
                    ? $error
                    : 'Unable to insert Visitor Intelligence record.'
            );
        }

        $insertId = $this->database->lastInsertId();

        if (!is_int($insertId)) {
            $insertId = (int) $insertId;
        }

        return $insertId;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $format
     */
    protected function updateByIdentifier(
        string $identifier,
        array $data,
        array $format = []
    ): bool {
        $identifier = trim($identifier);

        if ($identifier === '') {
            throw new \InvalidArgumentException(
                'Visitor Intelligence identifier cannot be empty.'
            );
        }

        if ($data === []) {
            return false;
        }

        $this->validateFormat(
            $data,
            $format,
            'update'
        );

        $column = $this->identifierColumn();

        $this->assertIdentifier(
            $column
        );

        $result = $this->database->update(
            $this->table(),
            $data,
            [
                $column => $identifier,
            ],
            $format,
            [
                '%s',
            ]
        );

        if ($result === false) {
            $error = trim(
                $this->database->getLastError()
            );

            throw new \RuntimeException(
                $error !== ''
                    ? $error
                    : 'Unable to update Visitor Intelligence record.'
            );
        }

        return $result > 0;
    }

    /**
     * @param array<string, mixed> $where
     * @param array<int, string> $whereFormat
     * @return array<int, array<string, mixed>>
     */
    protected function findMany(
        array $where = [],
        array $whereFormat = [],
        ?string $orderBy = null,
        string $direction = 'ASC',
        int $limit = 100
    ): array {
        $table = $this->table();

        $sql = "SELECT * FROM {$table}";
        $values = [];

        if ($where !== []) {
            $columns = array_keys($where);

            foreach ($columns as $column) {
                $this->assertIdentifier(
                    (string) $column
                );
            }

            $whereFormat = $this->normalizeFormats(
                $where,
                $whereFormat,
                'WHERE'
            );

            $conditions = [];
            $index = 0;

            foreach ($where as $column => $value) {
                $format = $whereFormat[$index];

                $conditions[] =
                    "{$column} = {$format}";

                $values[] = $value;
                $index++;
            }

            $sql .= ' WHERE '
                . implode(
                    ' AND ',
                    $conditions
                );
        }

        if ($orderBy !== null) {
            $orderBy = trim($orderBy);

            if ($orderBy === '') {
                throw new \InvalidArgumentException(
                    'Visitor Intelligence order column cannot be empty.'
                );
            }

            $this->assertIdentifier(
                $orderBy
            );

            $direction = strtoupper(
                trim($direction)
            );

            if (
                !in_array(
                    $direction,
                    [
                        'ASC',
                        'DESC',
                    ],
                    true
                )
            ) {
                throw new \InvalidArgumentException(
                    'Visitor Intelligence order direction must be ASC or DESC.'
                );
            }

            $sql .=
                " ORDER BY {$orderBy} {$direction}";
        }

        $limit = $this->normalizeLimit(
            $limit
        );

        $sql .= " LIMIT {$limit}";

        $results = $this->database->getResults(
            $sql,
            ...$values
        );

        if ($results === false || $results === null) {
            return [];
        }

        if (!is_array($results)) {
            throw new \RuntimeException(
                'Visitor Intelligence database returned invalid result data.'
            );
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $where
     * @param array<int, string> $whereFormat
     */
    protected function count(
        array $where = [],
        array $whereFormat = []
    ): int {
        $table = $this->table();

        $sql = "SELECT COUNT(*) FROM {$table}";
        $values = [];

        if ($where !== []) {
            foreach (array_keys($where) as $column) {
                $this->assertIdentifier(
                    (string) $column
                );
            }

            $whereFormat = $this->normalizeFormats(
                $where,
                $whereFormat,
                'COUNT'
            );

            $conditions = [];
            $index = 0;

            foreach ($where as $column => $value) {
                $format = $whereFormat[$index];

                $conditions[] =
                    "{$column} = {$format}";

                $values[] = $value;
                $index++;
            }

            $sql .= ' WHERE '
                . implode(
                    ' AND ',
                    $conditions
                );
        }

        $result = $this->database->getVar(
            $sql,
            ...$values
        );

        if ($result === null || $result === false) {
            return 0;
        }

        if (
            !is_int($result)
            && !(
                is_string($result)
                && preg_match(
                    '/^-?\d+$/',
                    $result
                ) === 1
            )
        ) {
            throw new \RuntimeException(
                'Visitor Intelligence COUNT query returned an invalid value.'
            );
        }

        $count = (int) $result;

        if ($count < 0) {
            throw new \RuntimeException(
                'Visitor Intelligence COUNT query returned a negative value.'
            );
        }

        return $count;
    }

    protected function encodePayload(
        mixed $payload
    ): string {
        if (is_string($payload)) {
            return $payload;
        }

        $encoded = wp_json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if (!is_string($encoded)) {
            throw new \RuntimeException(
                'Unable to encode Visitor Intelligence payload.'
            );
        }

        return $encoded;
    }

    protected function nowUtc(): string
    {
        return current_time(
            'mysql',
            true
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $format
     */
    private function validateFormat(
        array $data,
        array $format,
        string $operation
    ): void {
        if ($format === []) {
            return;
        }

        if (
            count($format)
            !== count($data)
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor Intelligence %s format count does not match data count.',
                    $operation
                )
            );
        }

        foreach ($format as $value) {
            if (
                !in_array(
                    $value,
                    self::ALLOWED_FORMATS,
                    true
                )
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Unsupported Visitor Intelligence %s format: %s',
                        $operation,
                        $value
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
    private function normalizeFormats(
        array $data,
        array $format,
        string $context
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
                sprintf(
                    'Visitor Intelligence %s format count does not match condition count.',
                    $context
                )
            );
        }

        foreach ($format as $value) {
            if (
                !in_array(
                    $value,
                    self::ALLOWED_FORMATS,
                    true
                )
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Unsupported Visitor Intelligence %s format: %s',
                        $context,
                        $value
                    )
                );
            }
        }

        return array_values(
            $format
        );
    }

    private function normalizeLimit(
        int $limit
    ): int {
        if ($limit < 1) {
            return 1;
        }

        return min(
            $limit,
            self::MAX_QUERY_LIMIT
        );
    }

    private function assertIdentifier(
        string $identifier
    ): void {
        if (
            preg_match(
                '/^[A-Za-z_][A-Za-z0-9_]*$/',
                $identifier
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid Visitor Intelligence SQL identifier: %s',
                    $identifier
                )
            );
        }
    }
}