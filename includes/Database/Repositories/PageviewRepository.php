<?php

declare(strict_types=1);

namespace VisitorIntelligence\Database\Repositories;

use VisitorIntelligence\Core\Contracts\RepositoryInterface;

defined('ABSPATH') || exit;

final class PageviewRepository extends AbstractRepository implements RepositoryInterface
{
    private const BOT_CLASSIFICATIONS = [
        'human',
        'suspicious',
        'bot',
        'unknown',
    ];

    private const MAX_SEQUENCE_RETRIES = 5;

    private const MAX_QUERY_LIMIT = 1000;

    protected function table(): string
    {
        return $this->database->table('pageviews');
    }

    protected function identifierColumn(): string
    {
        return 'pageview_id';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $pageviewId): ?array
    {
        $pageviewId = trim($pageviewId);

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        return $this->findByIdentifier(
            $pageviewId
        );
    }

    public function existsById(string $pageviewId): bool
    {
        $pageviewId = trim($pageviewId);

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        return $this->existsByIdentifier(
            $pageviewId
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function persist(array $data): string
    {
        $identity = $this->extractIdentity(
            $data
        );

        $existing = $this->findByIdentifier(
            $identity['pageview_id']
        );

        if ($existing !== null) {
            $this->assertOwnership(
                $existing,
                $identity['visitor_id'],
                $identity['session_id']
            );

            $update = $this->filterMutableData(
                $data
            );

            if ($update !== []) {
                $this->validateUpdateData(
                    $update,
                    $existing
                );

                $this->updateByIdentifier(
                    $identity['pageview_id'],
                    $update,
                    $this->buildFormats(
                        $update
                    )
                );
            }

            return $identity['pageview_id'];
        }

        $record = $this->buildRecord(
            $identity['pageview_id'],
            $identity['visitor_id'],
            $identity['session_id'],
            $data
        );

        try {
            $this->insertRecord(
                $record,
                $this->buildFormats(
                    $record
                )
            );
        } catch (\RuntimeException $exception) {
            $existing = $this->findByIdentifier(
                $identity['pageview_id']
            );

            if ($existing === null) {
                throw $exception;
            }

            $this->assertOwnership(
                $existing,
                $identity['visitor_id'],
                $identity['session_id']
            );
        }

        return $identity['pageview_id'];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(
        string $pageviewId,
        string $visitorId,
        string $sessionId,
        array $data = []
    ): int {
        $pageviewId = trim(
            $pageviewId
        );

        $visitorId = trim(
            $visitorId
        );

        $sessionId = trim(
            $sessionId
        );

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        $this->assertUuid(
            $visitorId,
            'Visitor ID'
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $this->assertRelatedIdentity(
            $visitorId,
            $sessionId
        );

        if ($this->existsById(
            $pageviewId
        )) {
            throw new \RuntimeException(
                sprintf(
                    'Pageview already exists: %s',
                    $pageviewId
                )
            );
        }

        $record = $this->buildRecord(
            $pageviewId,
            $visitorId,
            $sessionId,
            $data
        );

        return $this->insertRecord(
            $record,
            $this->buildFormats(
                $record
            )
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function getNextSequenceNumber(
        string $sessionId
    ): int {
        $sessionId = trim(
            $sessionId
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $table = $this->table();

        $result = $this->database->getVar(
            "SELECT COALESCE(MAX(sequence_number), 0) + 1
             FROM {$table}
             WHERE session_id = %s",
            $sessionId
        );

        if ($result === null) {
            return 1;
        }

        if (
            !is_int($result)
            && !(
                is_string($result)
                && ctype_digit($result)
            )
        ) {
            throw new \RuntimeException(
                'Invalid pageview sequence value returned by database.'
            );
        }

        return max(
            1,
            (int) $result
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createForSession(
        string $pageviewId,
        string $visitorId,
        string $sessionId,
        array $data = []
    ): int {
        $pageviewId = trim(
            $pageviewId
        );

        $visitorId = trim(
            $visitorId
        );

        $sessionId = trim(
            $sessionId
        );

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        $this->assertUuid(
            $visitorId,
            'Visitor ID'
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $this->assertRelatedIdentity(
            $visitorId,
            $sessionId
        );

        if (
            array_key_exists(
                'sequence_number',
                $data
            )
        ) {
            return $this->create(
                $pageviewId,
                $visitorId,
                $sessionId,
                $data
            );
        }

        for (
            $attempt = 1;
            $attempt <= self::MAX_SEQUENCE_RETRIES;
            $attempt++
        ) {
            $data['sequence_number'] =
                $this->getNextSequenceNumber(
                    $sessionId
                );

            try {
                return $this->create(
                    $pageviewId,
                    $visitorId,
                    $sessionId,
                    $data
                );
            } catch (\RuntimeException $exception) {
                /*
                 * A pageview with our own identity means the insert
                 * completed and the exception came from a later operation.
                 * Never silently retry it.
                 */
                if (
                    $this->existsById(
                        $pageviewId
                    )
                ) {
                    throw $exception;
                }

                if (
                    $attempt >=
                    self::MAX_SEQUENCE_RETRIES
                ) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException(
            sprintf(
                'Unable to allocate pageview sequence for session %s.',
                $sessionId
            )
        );
    }

    public function updateActivity(
        string $pageviewId,
        int $activeSeconds,
        int $visibleSeconds
    ): bool {
        $pageviewId = trim(
            $pageviewId
        );

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        $this->validateSeconds(
            $activeSeconds,
            'Active seconds'
        );

        $this->validateSeconds(
            $visibleSeconds,
            'Visible seconds'
        );

        $existing = $this->findByIdentifier(
            $pageviewId
        );

        if ($existing === null) {
            return false;
        }

        $currentActive =
            (int) (
                $existing['active_seconds']
                ?? 0
            );

        $currentVisible =
            (int) (
                $existing['visible_seconds']
                ?? 0
            );

        if (
            $activeSeconds < $currentActive
            || $visibleSeconds < $currentVisible
        ) {
            throw new \InvalidArgumentException(
                'Pageview activity counters cannot decrease.'
            );
        }

        $table = $this->table();

        $affected =
            $this->database->execute(
                "UPDATE {$table}
                 SET
                     active_seconds = GREATEST(
                         active_seconds,
                         %d
                     ),
                     visible_seconds = GREATEST(
                         visible_seconds,
                         %d
                     )
                 WHERE pageview_id = %s",
                $activeSeconds,
                $visibleSeconds,
                $pageviewId
            );

        return $affected > 0;
    }

    public function addActiveSeconds(
        string $pageviewId,
        int $seconds
    ): bool {
        $pageviewId = trim(
            $pageviewId
        );

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        if ($seconds < 1) {
            throw new \InvalidArgumentException(
                'Active seconds must be greater than zero.'
            );
        }

        return $this->increment(
            $pageviewId,
            'active_seconds',
            $seconds
        );
    }

    public function addVisibleSeconds(
        string $pageviewId,
        int $seconds
    ): bool {
        $pageviewId = trim(
            $pageviewId
        );

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        if ($seconds < 1) {
            throw new \InvalidArgumentException(
                'Visible seconds must be greater than zero.'
            );
        }

        return $this->increment(
            $pageviewId,
            'visible_seconds',
            $seconds
        );
    }

    public function markLanding(
        string $pageviewId
    ): bool {
        $pageviewId = trim(
            $pageviewId
        );

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        return $this->updateByIdentifier(
            $pageviewId,
            [
                'is_landing' => 1,
            ],
            [
                '%d',
            ]
        );
    }

    public function markExit(
        string $pageviewId
    ): bool {
        $pageviewId = trim(
            $pageviewId
        );

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        return $this->updateByIdentifier(
            $pageviewId,
            [
                'is_exit' => 1,
            ],
            [
                '%d',
            ]
        );
    }

    public function updateBot(
        string $pageviewId,
        int $score,
        string $classification
    ): bool {
        $pageviewId = trim(
            $pageviewId
        );

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        $this->validateBotScore(
            $score
        );

        $classification = trim(
            $classification
        );

        $this->validateBotClassification(
            $classification
        );

        return $this->updateByIdentifier(
            $pageviewId,
            [
                'bot_score' =>
                    $score,

                'bot_classification' =>
                    $classification,
            ],
            [
                '%d',
                '%s',
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findBySession(
        string $sessionId,
        int $limit = self::MAX_QUERY_LIMIT
    ): array {
        $sessionId = trim(
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
            'sequence_number',
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
        $visitorId = trim(
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
     * @return array<string, mixed>|null
     */
    public function getSummary(
        string $pageviewId
    ): ?array {
        $pageview = $this->findById(
            $pageviewId
        );

        if ($pageview === null) {
            return null;
        }

        return [
            'pageview_id' =>
                $pageview['pageview_id'],

            'visitor_id' =>
                $pageview['visitor_id'],

            'session_id' =>
                $pageview['session_id'],

            'occurred_at' =>
                $pageview['occurred_at'],

            'url' =>
                $pageview['url'],

            'url_hash' =>
                $pageview['url_hash'],

            'post_id' =>
                $pageview['post_id'] !== null
                    ? (int) $pageview['post_id']
                    : null,

            'sequence_number' =>
                (int) $pageview['sequence_number'],

            'active_seconds' =>
                (int) $pageview['active_seconds'],

            'visible_seconds' =>
                (int) $pageview['visible_seconds'],

            'is_landing' =>
                (bool) $pageview['is_landing'],

            'is_exit' =>
                (bool) $pageview['is_exit'],

            'bot_score' =>
                (int) $pageview['bot_score'],

            'bot_classification' =>
                $pageview['bot_classification'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function filterMutableData(
        array $data
    ): array {
        $allowed = [
            'active_seconds',
            'visible_seconds',
            'is_landing',
            'is_exit',
            'bot_score',
            'bot_classification',
        ];

        $result = [];

        foreach ($allowed as $field) {
            if (
                array_key_exists(
                    $field,
                    $data
                )
            ) {
                $result[$field] =
                    $data[$field];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $existing
     */
    private function validateUpdateData(
        array $data,
        array $existing
    ): void {
        foreach (
            [
                'active_seconds' =>
                    'Active seconds',

                'visible_seconds' =>
                    'Visible seconds',
            ] as $field => $label
        ) {
            if (
                !array_key_exists(
                    $field,
                    $data
                )
            ) {
                continue;
            }

            $value =
                $this->normalizeInteger(
                    $data[$field],
                    $label
                );

            $this->validateSeconds(
                $value,
                $label
            );

            $current =
                (int) (
                    $existing[$field]
                    ?? 0
                );

            if ($value < $current) {
                throw new \InvalidArgumentException(
                    sprintf(
                        '%s cannot decrease.',
                        $label
                    )
                );
            }
        }

        foreach (
            [
                'is_landing',
                'is_exit',
            ] as $field
        ) {
            if (
                !array_key_exists(
                    $field,
                    $data
                )
            ) {
                continue;
            }

            $value =
                $this->normalizeInteger(
                    $data[$field],
                    sprintf(
                        'Pageview field %s',
                        $field
                    )
                );

            if (
                !in_array(
                    $value,
                    [0, 1],
                    true
                )
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Pageview field %s must be 0 or 1.',
                        $field
                    )
                );
            }

            $current =
                (int) (
                    $existing[$field]
                    ?? 0
                );

            if (
                $current === 1
                && $value === 0
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Pageview field %s cannot be unset.',
                        $field
                    )
                );
            }
        }

        if (
            array_key_exists(
                'bot_score',
                $data
            )
        ) {
            $score =
                $this->normalizeInteger(
                    $data['bot_score'],
                    'Bot score'
                );

            $this->validateBotScore(
                $score
            );
        }

        if (
            array_key_exists(
                'bot_classification',
                $data
            )
        ) {
            $this->validateBotClassification(
                trim(
                    (string) $data[
                        'bot_classification'
                    ]
                )
            );
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function validateRecord(
        array $record
    ): void {
        $this->assertUuid(
            (string) $record[
                'pageview_id'
            ],
            'Pageview ID'
        );

        $this->assertUuid(
            (string) $record[
                'visitor_id'
            ],
            'Visitor ID'
        );

        $this->assertUuid(
            (string) $record[
                'session_id'
            ],
            'Session ID'
        );

        $this->validateDateTime(
            (string) $record[
                'occurred_at'
            ]
        );

        $url =
            trim(
                (string) $record['url']
            );

        if ($url === '') {
            throw new \InvalidArgumentException(
                'Pageview URL cannot be empty.'
            );
        }

        $urlHash =
            strtolower(
                trim(
                    (string) $record[
                        'url_hash'
                    ]
                )
            );

        if (
            preg_match(
                '/^[a-f0-9]{64}$/',
                $urlHash
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Pageview URL hash must be a valid SHA-256 hash.'
            );
        }

        $expectedHash =
            hash(
                'sha256',
                $url
            );

        if (
            !hash_equals(
                $expectedHash,
                $urlHash
            )
        ) {
            throw new \InvalidArgumentException(
                'Pageview URL hash does not match URL.'
            );
        }

        $this->validateSequenceNumber(
            (int) $record[
                'sequence_number'
            ]
        );

        $this->validateSeconds(
            (int) $record[
                'active_seconds'
            ],
            'Active seconds'
        );

        $this->validateSeconds(
            (int) $record[
                'visible_seconds'
            ],
            'Visible seconds'
        );

        foreach (
            [
                'is_landing',
                'is_exit',
            ] as $field
        ) {
            if (
                !in_array(
                    (int) $record[$field],
                    [0, 1],
                    true
                )
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Pageview %s must be 0 or 1.',
                        $field
                    )
                );
            }
        }

        $this->validateBotScore(
            (int) $record[
                'bot_score'
            ]
        );

        $this->validateBotClassification(
            (string) $record[
                'bot_classification'
            ]
        );

        $this->validateNullableString(
            $record[
                'previous_url'
            ] ?? null,
            'previous_url'
        );

        $this->validateNullableString(
            $record[
                'referrer_url'
            ] ?? null,
            'referrer_url'
        );

        $this->validateDateTime(
            (string) $record[
                'created_at'
            ]
        );

        if (
            $record['post_id'] !== null
            && !is_int(
                $record['post_id']
            )
            && !(
                is_string(
                    $record['post_id']
                )
                && ctype_digit(
                    $record['post_id']
                )
            )
        ) {
            throw new \InvalidArgumentException(
                'Post ID must be an integer or null.'
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildRecord(
        string $pageviewId,
        string $visitorId,
        string $sessionId,
        array $data
    ): array {
        $now =
            $this->nowUtc();

        $url =
            trim(
                (string) (
                    $data['url']
                    ?? ''
                )
            );

        if ($url === '') {
            throw new \InvalidArgumentException(
                'Pageview URL cannot be empty.'
            );
        }

        $record = [
            'pageview_id' =>
                $pageviewId,

            'visitor_id' =>
                $visitorId,

            'session_id' =>
                $sessionId,

            'occurred_at' =>
                $data['occurred_at']
                ?? $now,

            'url' =>
                $url,

            'url_hash' =>
                hash(
                    'sha256',
                    $url
                ),

            'post_id' =>
                $data['post_id']
                ?? null,

            'previous_url' =>
                $data['previous_url']
                ?? null,

            'referrer_url' =>
                $data['referrer_url']
                ?? null,

            'sequence_number' =>
                $data['sequence_number']
                ?? 1,

            'active_seconds' =>
                $data['active_seconds']
                ?? 0,

            'visible_seconds' =>
                $data['visible_seconds']
                ?? 0,

            'is_landing' =>
                $data['is_landing']
                ?? 0,

            'is_exit' =>
                $data['is_exit']
                ?? 0,

            'bot_score' =>
                $data['bot_score']
                ?? 0,

            'bot_classification' =>
                $data[
                    'bot_classification'
                ]
                ?? 'unknown',

            'created_at' =>
                $data['created_at']
                ?? $now,
        ];

        $this->validateRecord(
            $record
        );

        return $record;
    }

    /**
     * @return array{
     *     pageview_id: string,
     *     visitor_id: string,
     *     session_id: string
     * }
     */
    private function extractIdentity(
        array $data
    ): array {
        $pageviewId =
            trim(
                (string) (
                    $data[
                        'pageview_id'
                    ] ?? ''
                )
            );

        $visitorId =
            trim(
                (string) (
                    $data[
                        'visitor_id'
                    ] ?? ''
                )
            );

        $sessionId =
            trim(
                (string) (
                    $data[
                        'session_id'
                    ] ?? ''
                )
            );

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        $this->assertUuid(
            $visitorId,
            'Visitor ID'
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $this->assertRelatedIdentity(
            $visitorId,
            $sessionId
        );

        return [
            'pageview_id' =>
                $pageviewId,

            'visitor_id' =>
                $visitorId,

            'session_id' =>
                $sessionId,
        ];
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function assertOwnership(
        array $existing,
        string $visitorId,
        string $sessionId
    ): void {
        $existingVisitorId =
            (string) (
                $existing[
                    'visitor_id'
                ] ?? ''
            );

        $existingSessionId =
            (string) (
                $existing[
                    'session_id'
                ] ?? ''
            );

        if (
            $existingVisitorId !==
                $visitorId
            || $existingSessionId !==
                $sessionId
        ) {
            throw new \RuntimeException(
                'Existing pageview belongs to another visitor or session.'
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

        if ($ownerVisitorId === null) {
            throw new \RuntimeException(
                sprintf(
                    'Session does not exist: %s',
                    $sessionId
                )
            );
        }

        if (
            (string) $ownerVisitorId !==
            $visitorId
        ) {
            throw new \RuntimeException(
                'Session belongs to another visitor.'
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function buildFormats(
        array $data
    ): array {
        $integerFields = [
            'post_id',
            'sequence_number',
            'active_seconds',
            'visible_seconds',
            'is_landing',
            'is_exit',
            'bot_score',
        ];

        $formats = [];

        foreach (
            $data as $field => $value
        ) {
            $formats[] =
                in_array(
                    $field,
                    $integerFields,
                    true
                )
                    ? '%d'
                    : '%s';
        }

        return $formats;
    }

    private function increment(
        string $pageviewId,
        string $column,
        int $amount
    ): bool {
        $pageviewId =
            trim(
                $pageviewId
            );

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        if (
            !in_array(
                $column,
                [
                    'active_seconds',
                    'visible_seconds',
                ],
                true
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Unsupported Pageview counter: %s',
                    $column
                )
            );
        }

        if ($amount < 1) {
            throw new \InvalidArgumentException(
                'Pageview counter increment must be greater than zero.'
            );
        }

        $existing =
            $this->findByIdentifier(
                $pageviewId
            );

        if ($existing === null) {
            return false;
        }

        $table =
            $this->table();

        $affected =
            $this->database->execute(
                "UPDATE {$table}
                 SET
                     {$column} =
                         {$column} + %d
                 WHERE pageview_id = %s",
                $amount,
                $pageviewId
            );

        return $affected > 0;
    }

    private function validateSequenceNumber(
        int $sequence
    ): void {
        if ($sequence < 1) {
            throw new \InvalidArgumentException(
                'Pageview sequence number must be greater than zero.'
            );
        }
    }

    private function validateSeconds(
        int $seconds,
        string $label
    ): void {
        if ($seconds < 0) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s cannot be negative.',
                    $label
                )
            );
        }
    }

    private function validateBotScore(
        int $score
    ): void {
        if (
            $score < 0
            || $score > 100
        ) {
            throw new \InvalidArgumentException(
                'Bot score must be between 0 and 100.'
            );
        }
    }

    private function validateBotClassification(
        string $classification
    ): void {
        if (
            !in_array(
                $classification,
                self::BOT_CLASSIFICATIONS,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid bot classification: %s',
                    $classification
                )
            );
        }
    }

    private function validateNullableString(
        mixed $value,
        string $field
    ): void {
        if ($value === null) {
            return;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Pageview field %s must be a string or null.',
                    $field
                )
            );
        }
    }

    private function normalizeInteger(
        mixed $value,
        string $label
    ): int {
        if (is_int($value)) {
            return $value;
        }

        if (
            is_string($value)
            && preg_match(
                '/^\d+$/',
                $value
            ) === 1
        ) {
            return (int) $value;
        }

        throw new \InvalidArgumentException(
            sprintf(
                '%s must be an integer.',
                $label
            )
        );
    }

    private function normalizeLimit(
        int $limit
    ): int {
        if ($limit < 1) {
            throw new \InvalidArgumentException(
                'Query limit must be greater than zero.'
            );
        }

        return min(
            $limit,
            self::MAX_QUERY_LIMIT
        );
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

        if (
            $date === false
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