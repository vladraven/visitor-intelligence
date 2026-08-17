<?php

declare(strict_types=1);

namespace VisitorIntelligence\Database\Repositories;

use VisitorIntelligence\Core\Contracts\RepositoryInterface;

defined('ABSPATH') || exit;

final class EventRepository extends AbstractRepository implements RepositoryInterface
{
    private const SCHEMA_VERSION = 'v1';

    private const MAX_EVENT_TYPE_LENGTH = 64;

    private const MAX_SCHEMA_VERSION_LENGTH = 8;

    private const MAX_BATCH_SIZE = 1000;

    private const MAX_QUERY_LIMIT = 1000;

    private const MAX_DELETE_LIMIT = 50000;

    private const MAX_PAYLOAD_BYTES = 32768;

    protected function table(): string
    {
        return $this->database->table('events');
    }

    protected function identifierColumn(): string
    {
        return 'event_id';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(
        string $eventId
    ): ?array {
        $eventId =
            trim(
                $eventId
            );

        $this->assertUuid(
            $eventId,
            'Event ID'
        );

        return $this->findByIdentifier(
            $eventId
        );
    }

    public function existsById(
        string $eventId
    ): bool {
        $eventId =
            trim(
                $eventId
            );

        $this->assertUuid(
            $eventId,
            'Event ID'
        );

        return $this->existsByIdentifier(
            $eventId
        );
    }

    /**
     * Persist a canonical immutable event.
     *
     * Existing events are treated as idempotent only when their
     * complete immutable identity matches.
     *
     * @param array<string, mixed> $data
     */
    public function persist(
        array $data
    ): string {
        $identity =
            $this->extractIdentity(
                $data
            );

        $existing =
            $this->findByIdentifier(
                $identity['event_id']
            );

        if (
            $existing !== null
        ) {
            $this->assertIdentityMatch(
                $existing,
                $identity
            );

            return $identity['event_id'];
        }

        $createData =
            $data;

        unset(
            $createData['event_id'],
            $createData['visitor_id'],
            $createData['session_id'],
            $createData['event_type']
        );

        try {
            $this->create(
                $identity['event_id'],
                $identity['visitor_id'],
                $identity['session_id'],
                $identity['event_type'],
                $createData
            );

            return $identity['event_id'];
        } catch (
            \RuntimeException $exception
        ) {
            $existing =
                $this->findByIdentifier(
                    $identity['event_id']
                );

            if (
                $existing === null
            ) {
                throw $exception;
            }

            $this->assertIdentityMatch(
                $existing,
                $identity
            );

            return $identity['event_id'];
        }
    }

    /**
     * Create a canonical immutable event.
     *
     * @param array<string, mixed> $data
     */
    public function create(
        string $eventId,
        string $visitorId,
        string $sessionId,
        string $eventType,
        array $data = []
    ): int {
        $eventId =
            trim(
                $eventId
            );

        $visitorId =
            trim(
                $visitorId
            );

        $sessionId =
            trim(
                $sessionId
            );

        $eventType =
            trim(
                $eventType
            );

        $this->assertUuid(
            $eventId,
            'Event ID'
        );

        $this->assertUuid(
            $visitorId,
            'Visitor ID'
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $this->validateEventType(
            $eventType
        );

        $this->assertRelatedIdentity(
            $visitorId,
            $sessionId
        );

        if (
            $this->existsById(
                $eventId
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Event already exists: %s',
                    $eventId
                )
            );
        }

        $now =
            $this->nowUtc();

        $pageviewId =
            $this->normalizeNullableUuid(
                $data['pageview_id']
                ?? null,
                'Pageview ID'
            );

        if (
            $pageviewId !== null
        ) {
            $this->assertPageviewIdentity(
                $pageviewId,
                $visitorId,
                $sessionId
            );
        }

        $occurredAt =
            isset(
                $data['occurred_at']
            )
                ? trim(
                    (string) $data['occurred_at']
                )
                : $now;

        $this->validateDateTime(
            $occurredAt
        );

        $payload =
            $this->normalizePayload(
                $data['payload']
                ?? null
            );

        $schemaVersion =
            isset(
                $data['schema_version']
            )
                ? trim(
                    (string) $data['schema_version']
                )
                : self::SCHEMA_VERSION;

        $this->validateSchemaVersion(
            $schemaVersion
        );

        $record = [
            'event_id' =>
                $eventId,

            'visitor_id' =>
                $visitorId,

            'session_id' =>
                $sessionId,

            'pageview_id' =>
                $pageviewId,

            'event_type' =>
                $eventType,

            'schema_version' =>
                $schemaVersion,

            'occurred_at' =>
                $occurredAt,

            'payload' =>
                $payload,

            'created_at' =>
                $now,
        ];

        return $this->insertRecord(
            $record,
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        );
    }

    /**
     * Persist a batch of immutable events.
     *
     * Duplicate event IDs are treated as idempotent only when
     * their complete immutable identity matches.
     *
     * @param array<int, array<string, mixed>> $events
     * @return array{
     *     inserted: int,
     *     skipped: int,
     *     ids: array<int, int>
     * }
     */
    public function createBatch(
        array $events
    ): array {
        $count =
            count(
                $events
            );

        if (
            $count > self::MAX_BATCH_SIZE
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Event batch cannot contain more than %d events.',
                    self::MAX_BATCH_SIZE
                )
            );
        }

        $inserted = 0;

        $skipped = 0;

        $ids = [];

        foreach (
            $events as $event
        ) {
            if (
                !is_array(
                    $event
                )
            ) {
                throw new \InvalidArgumentException(
                    'Each event in a batch must be an array.'
                );
            }

            $identity =
                $this->extractIdentity(
                    $event
                );

            $existing =
                $this->findByIdentifier(
                    $identity['event_id']
                );

            if (
                $existing !== null
            ) {
                $this->assertIdentityMatch(
                    $existing,
                    $identity
                );

                $skipped++;

                continue;
            }

            $data =
                $event;

            unset(
                $data['event_id'],
                $data['visitor_id'],
                $data['session_id'],
                $data['event_type']
            );

            try {
                $id =
                    $this->create(
                        $identity['event_id'],
                        $identity['visitor_id'],
                        $identity['session_id'],
                        $identity['event_type'],
                        $data
                    );

                $inserted++;

                $ids[] =
                    $id;
            } catch (
                \RuntimeException $exception
            ) {
                $existing =
                    $this->findByIdentifier(
                        $identity['event_id']
                    );

                if (
                    $existing === null
                ) {
                    throw $exception;
                }

                $this->assertIdentityMatch(
                    $existing,
                    $identity
                );

                $skipped++;
            }
        }

        return [
            'inserted' =>
                $inserted,

            'skipped' =>
                $skipped,

            'ids' =>
                $ids,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findBySession(
        string $sessionId,
        int $limit = self::MAX_QUERY_LIMIT
    ): array {
        $sessionId =
            trim(
                $sessionId
            );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        return $this->findMany(
            [
                'session_id' =>
                    $sessionId,
            ],
            [
                '%s',
            ],
            'occurred_at',
            'ASC',
            $this->normalizeLimit(
                $limit
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByPageview(
        string $pageviewId,
        int $limit = self::MAX_QUERY_LIMIT
    ): array {
        $pageviewId =
            trim(
                $pageviewId
            );

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        return $this->findMany(
            [
                'pageview_id' =>
                    $pageviewId,
            ],
            [
                '%s',
            ],
            'occurred_at',
            'ASC',
            $this->normalizeLimit(
                $limit
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByVisitor(
        string $visitorId,
        int $limit = self::MAX_QUERY_LIMIT
    ): array {
        $visitorId =
            trim(
                $visitorId
            );

        $this->assertUuid(
            $visitorId,
            'Visitor ID'
        );

        return $this->findMany(
            [
                'visitor_id' =>
                    $visitorId,
            ],
            [
                '%s',
            ],
            'occurred_at',
            'ASC',
            $this->normalizeLimit(
                $limit
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByType(
        string $eventType,
        int $limit = self::MAX_QUERY_LIMIT
    ): array {
        $eventType =
            trim(
                $eventType
            );

        $this->validateEventType(
            $eventType
        );

        return $this->findMany(
            [
                'event_type' =>
                    $eventType,
            ],
            [
                '%s',
            ],
            'occurred_at',
            'ASC',
            $this->normalizeLimit(
                $limit
            )
        );
    }

    public function countBySession(
        string $sessionId
    ): int {
        $sessionId =
            trim(
                $sessionId
            );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        return $this->count(
            [
                'session_id' =>
                    $sessionId,
            ],
            [
                '%s',
            ]
        );
    }

    /**
     * Delete events older than a UTC timestamp.
     */
    public function deleteOlderThan(
        string $timestamp,
        int $limit = 5000
    ): int {
        $timestamp =
            trim(
                $timestamp
            );

        $this->validateDateTime(
            $timestamp
        );

        if (
            $limit < 1
        ) {
            throw new \InvalidArgumentException(
                'Delete limit must be greater than zero.'
            );
        }

        $limit =
            min(
                $limit,
                self::MAX_DELETE_LIMIT
            );

        $table =
            $this->table();

        return $this->database->execute(
            "DELETE FROM {$table}
             WHERE occurred_at < %s
             LIMIT {$limit}",
            $timestamp
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{
     *     event_id: string,
     *     visitor_id: string,
     *     session_id: string,
     *     event_type: string,
     *     pageview_id: ?string,
     *     schema_version: string,
     *     occurred_at: string,
     *     payload: ?string
     * }
     */
    private function extractIdentity(
        array $data
    ): array {
        $eventId =
            isset(
                $data['event_id']
            )
                ? trim(
                    (string) $data['event_id']
                )
                : '';

        $visitorId =
            isset(
                $data['visitor_id']
            )
                ? trim(
                    (string) $data['visitor_id']
                )
                : '';

        $sessionId =
            isset(
                $data['session_id']
            )
                ? trim(
                    (string) $data['session_id']
                )
                : '';

        $eventType =
            isset(
                $data['event_type']
            )
                ? trim(
                    (string) $data['event_type']
                )
                : '';

        $pageviewId =
            $this->normalizeNullableUuid(
                $data['pageview_id']
                ?? null,
                'Pageview ID'
            );

        $schemaVersion =
            isset(
                $data['schema_version']
            )
                ? trim(
                    (string) $data['schema_version']
                )
                : self::SCHEMA_VERSION;

        $occurredAt =
            isset(
                $data['occurred_at']
            )
                ? trim(
                    (string) $data['occurred_at']
                )
                : $this->nowUtc();

        $payload =
            $this->normalizePayload(
                $data['payload']
                ?? null
            );

        $this->assertUuid(
            $eventId,
            'Event ID'
        );

        $this->assertUuid(
            $visitorId,
            'Visitor ID'
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $this->validateEventType(
            $eventType
        );

        $this->validateSchemaVersion(
            $schemaVersion
        );

        $this->validateDateTime(
            $occurredAt
        );

        return [
            'event_id' =>
                $eventId,

            'visitor_id' =>
                $visitorId,

            'session_id' =>
                $sessionId,

            'event_type' =>
                $eventType,

            'pageview_id' =>
                $pageviewId,

            'schema_version' =>
                $schemaVersion,

            'occurred_at' =>
                $occurredAt,

            'payload' =>
                $payload,
        ];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array{
     *     event_id: string,
     *     visitor_id: string,
     *     session_id: string,
     *     event_type: string,
     *     pageview_id: ?string,
     *     schema_version: string,
     *     occurred_at: string,
     *     payload: ?string
     * } $identity
     */
    private function assertIdentityMatch(
        array $existing,
        array $identity
    ): void {
        $existingVisitorId =
            (string) (
                $existing['visitor_id']
                ?? ''
            );

        $existingSessionId =
            (string) (
                $existing['session_id']
                ?? ''
            );

        $existingEventType =
            (string) (
                $existing['event_type']
                ?? ''
            );

        $existingPageviewId =
            array_key_exists(
                'pageview_id',
                $existing
            )
                ? (
                    $existing['pageview_id'] !== null
                        ? trim(
                            (string) $existing['pageview_id']
                        )
                        : null
                )
                : null;

        $existingSchemaVersion =
            (string) (
                $existing['schema_version']
                ?? ''
            );

        $existingOccurredAt =
            trim(
                (string) (
                    $existing['occurred_at']
                    ?? ''
                )
            );

        $existingPayload =
            $existing['payload'] !== null
                ? (string) $existing['payload']
                : null;

        if (
            $existingVisitorId
                !== $identity['visitor_id']
            || $existingSessionId
                !== $identity['session_id']
            || $existingEventType
                !== $identity['event_type']
            || $existingPageviewId
                !== $identity['pageview_id']
            || $existingSchemaVersion
                !== $identity['schema_version']
            || $existingOccurredAt
                !== $identity['occurred_at']
            || $existingPayload
                !== $identity['payload']
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Event identity conflict: %s',
                    $identity['event_id']
                )
            );
        }
    }

    private function assertRelatedIdentity(
        string $visitorId,
        string $sessionId
    ): void {
        $table =
            $this->database->table(
                'sessions'
            );

        $ownerVisitorId =
            $this->database->getVar(
                "SELECT visitor_id
                 FROM {$table}
                 WHERE session_id = %s
                 LIMIT 1",
                $sessionId
            );

        if (
            $ownerVisitorId === null
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Session does not exist: %s',
                    $sessionId
                )
            );
        }

        if (
            (string) $ownerVisitorId
            !== $visitorId
        ) {
            throw new \RuntimeException(
                'Session belongs to another visitor.'
            );
        }
    }

    private function assertPageviewIdentity(
        string $pageviewId,
        string $visitorId,
        string $sessionId
    ): void {
        $table =
            $this->database->table(
                'pageviews'
            );

        $pageview =
            $this->database->getRow(
                "SELECT visitor_id, session_id
                 FROM {$table}
                 WHERE pageview_id = %s
                 LIMIT 1",
                $pageviewId
            );

        if (
            !is_array(
                $pageview
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Pageview does not exist: %s',
                    $pageviewId
                )
            );
        }

        if (
            (string) (
                $pageview['visitor_id']
                ?? ''
            ) !== $visitorId
            || (string) (
                $pageview['session_id']
                ?? ''
            ) !== $sessionId
        ) {
            throw new \RuntimeException(
                'Pageview belongs to another visitor or session.'
            );
        }
    }

    private function normalizeNullableUuid(
        mixed $value,
        string $label
    ): ?string {
        if (
            $value === null
        ) {
            return null;
        }

        if (
            !is_string(
                $value
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s must be a UUID or null.',
                    $label
                )
            );
        }

        $value =
            trim(
                $value
            );

        if (
            $value === ''
        ) {
            return null;
        }

        $this->assertUuid(
            $value,
            $label
        );

        return $value;
    }

    private function normalizePayload(
        mixed $payload
    ): ?string {
        if (
            $payload === null
        ) {
            return null;
        }

        if (
            is_string(
                $payload
            )
        ) {
            if (
                strlen(
                    $payload
                ) > self::MAX_PAYLOAD_BYTES
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Event payload cannot exceed %d bytes.',
                        self::MAX_PAYLOAD_BYTES
                    )
                );
            }

            return $payload;
        }

        if (
            !is_array(
                $payload
            )
        ) {
            throw new \InvalidArgumentException(
                'Event payload must be an array, string, or null.'
            );
        }

        $encoded =
            $this->encodePayload(
                $payload
            );

        if (
            strlen(
                $encoded
            ) > self::MAX_PAYLOAD_BYTES
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Event payload cannot exceed %d bytes.',
                    self::MAX_PAYLOAD_BYTES
                )
            );
        }

        return $encoded;
    }

    /**
     * @param mixed $payload
     */
    protected function encodePayload(
        mixed $payload
    ): string {
        try {
            $encoded =
                wp_json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR
                );
        } catch (
            \JsonException $exception
        ) {
            throw new \InvalidArgumentException(
                'Event payload cannot be encoded as JSON.',
                0,
                $exception
            );
        }

        if (
            !is_string(
                $encoded
            )
        ) {
            throw new \RuntimeException(
                'Event payload encoding returned an invalid value.'
            );
        }

        return $encoded;
    }

    private function validateEventType(
        string $eventType
    ): void {
        if (
            $eventType === ''
        ) {
            throw new \InvalidArgumentException(
                'Event type cannot be empty.'
            );
        }

        if (
            strlen(
                $eventType
            ) > self::MAX_EVENT_TYPE_LENGTH
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Event type cannot exceed %d characters.',
                    self::MAX_EVENT_TYPE_LENGTH
                )
            );
        }
    }

    private function validateSchemaVersion(
        string $schemaVersion
    ): void {
        if (
            strlen(
                $schemaVersion
            ) > self::MAX_SCHEMA_VERSION_LENGTH
            || preg_match(
                '/^v[0-9]+$/',
                $schemaVersion
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Event schema version must use the format vN and contain at most %d characters.',
                    self::MAX_SCHEMA_VERSION_LENGTH
                )
            );
        }
    }

    private function validateDateTime(
        string $value
    ): void {
        $value =
            trim(
                $value
            );

        $date =
            \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $value,
                new \DateTimeZone(
                    'UTC'
                )
            );

        $errors =
            \DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || (
                is_array(
                    $errors
                )
                && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                )
            )
            || $date->format(
                'Y-m-d H:i:s'
            ) !== $value
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid UTC datetime: %s',
                    $value
                )
            );
        }
    }

    private function normalizeLimit(
        int $limit
    ): int {
        if (
            $limit < 1
        ) {
            throw new \InvalidArgumentException(
                'Query limit must be greater than zero.'
            );
        }

        return min(
            $limit,
            self::MAX_QUERY_LIMIT
        );
    }

    private function assertUuid(
        string $value,
        string $label
    ): void {
        $value =
            trim(
                $value
            );

        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $value
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s must be a valid UUID.',
                    $label
                )
            );
        }
    }

    protected function nowUtc(): string
    {
        return current_time(
            'mysql',
            true
        );
    }
}