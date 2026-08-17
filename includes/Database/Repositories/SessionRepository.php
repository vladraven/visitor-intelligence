<?php

declare(strict_types=1);

namespace VisitorIntelligence\Database\Repositories;

use VisitorIntelligence\Core\Contracts\SessionRepositoryInterface;

defined('ABSPATH') || exit;

final class SessionRepository extends AbstractRepository implements SessionRepositoryInterface
{
    private const TRACKING_MODES = [
        'full',
        'server_only',
    ];

    private const BOT_CLASSIFICATIONS = [
        'human',
        'suspicious',
        'bot',
        'unknown',
    ];

    protected function table(): string
    {
        return $this->database->table('sessions');
    }

    protected function identifierColumn(): string
    {
        return 'session_id';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $sessionId): ?array
    {
        $sessionId = trim($sessionId);

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        return $this->findByIdentifier(
            $sessionId
        );
    }

    public function existsById(string $sessionId): bool
    {
        $sessionId = trim($sessionId);

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        return $this->existsByIdentifier(
            $sessionId
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function persist(array $data): string
    {
        $sessionId = trim(
            (string) ($data['session_id'] ?? '')
        );

        $visitorId = trim(
            (string) ($data['visitor_id'] ?? '')
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $this->assertUuid(
            $visitorId,
            'Visitor ID'
        );

        $existing = $this->findByIdentifier(
            $sessionId
        );

        if ($existing !== null) {
            $this->assertOwnership(
                $existing,
                $visitorId
            );

            $update = $this->filterMutableUpdateData(
                $data
            );

            if ($update !== []) {
                $this->validateUpdateData(
                    $update,
                    $existing
                );

                $update['updated_at'] =
                    $this->nowUtc();

                $this->updateByIdentifier(
                    $sessionId,
                    $update,
                    $this->buildFormats(
                        $update
                    )
                );
            }

            return $sessionId;
        }

        $record = $this->buildRecord(
            $sessionId,
            $visitorId,
            $data
        );

        try {
            $this->insertRecord(
                $record,
                $this->buildFormats(
                    $record
                )
            );

            return $sessionId;
        } catch (\RuntimeException $exception) {
            /*
             * Only a concurrent insert of the same session may be
             * converted into idempotent success.
             */
            $existing = $this->findByIdentifier(
                $sessionId
            );

            if ($existing === null) {
                throw $exception;
            }

            $this->assertOwnership(
                $existing,
                $visitorId
            );

            return $sessionId;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(
        string $sessionId,
        string $visitorId,
        array $data = []
    ): int {
        $sessionId = trim(
            $sessionId
        );

        $visitorId = trim(
            $visitorId
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $this->assertUuid(
            $visitorId,
            'Visitor ID'
        );

        if ($this->existsById($sessionId)) {
            throw new \RuntimeException(
                sprintf(
                    'Session already exists: %s',
                    $sessionId
                )
            );
        }

        $record = $this->buildRecord(
            $sessionId,
            $visitorId,
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
    public function findOrCreate(
        string $sessionId,
        string $visitorId,
        array $data = []
    ): int {
        $sessionId = trim(
            $sessionId
        );

        $visitorId = trim(
            $visitorId
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $this->assertUuid(
            $visitorId,
            'Visitor ID'
        );

        $existing = $this->findByIdentifier(
            $sessionId
        );

        if ($existing !== null) {
            $this->assertOwnership(
                $existing,
                $visitorId
            );

            return (int) $existing['id'];
        }

        try {
            return $this->create(
                $sessionId,
                $visitorId,
                $data
            );
        } catch (\RuntimeException $exception) {
            $existing = $this->findByIdentifier(
                $sessionId
            );

            if ($existing === null) {
                throw $exception;
            }

            $this->assertOwnership(
                $existing,
                $visitorId
            );

            return (int) $existing['id'];
        }
    }

    public function touch(
        string $sessionId,
        ?string $timestamp = null
    ): bool {
        $sessionId = trim(
            $sessionId
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $timestamp ??=
            $this->nowUtc();

        $this->validateDateTime(
            $timestamp
        );

        $existing = $this->findByIdentifier(
            $sessionId
        );

        if ($existing === null) {
            return false;
        }

        $startedAt = (string) (
            $existing['started_at']
            ?? ''
        );

        $currentLastActivity =
            (string) (
                $existing['last_activity_at']
                ?? $startedAt
            );

        if (
            $this->compareDateTimes(
                $timestamp,
                $startedAt
            ) < 0
        ) {
            throw new \InvalidArgumentException(
                'Session activity cannot precede session start.'
            );
        }

        if (
            $this->compareDateTimes(
                $timestamp,
                $currentLastActivity
            ) < 0
        ) {
            throw new \InvalidArgumentException(
                'Session last_activity_at cannot move backwards.'
            );
        }

        if (
            $existing['ended_at'] !== null
            && $this->compareDateTimes(
                $timestamp,
                (string) $existing['ended_at']
            ) > 0
        ) {
            throw new \InvalidArgumentException(
                'Session activity cannot occur after session end.'
            );
        }

        if (
            $timestamp ===
            $currentLastActivity
        ) {
            return true;
        }

        $table = $this->table();

        $affected =
            $this->database->execute(
                "UPDATE {$table}
                 SET
                     last_activity_at = GREATEST(
                         last_activity_at,
                         %s
                     ),
                     updated_at = %s
                 WHERE session_id = %s",
                $timestamp,
                $this->nowUtc(),
                $sessionId
            );

        return $affected > 0;
    }

    public function updateDuration(
        string $sessionId,
        int $durationSeconds,
        string $lastActivityAt
    ): bool {
        $sessionId = trim(
            $sessionId
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $this->validateNonNegativeInteger(
            $durationSeconds,
            'Session duration'
        );

        $this->validateDateTime(
            $lastActivityAt
        );

        $existing = $this->findByIdentifier(
            $sessionId
        );

        if ($existing === null) {
            return false;
        }

        if (
            $existing['ended_at'] !== null
            && trim(
                (string) $existing['ended_at']
            ) !== ''
        ) {
            throw new \InvalidArgumentException(
                'Cannot update duration of an ended session.'
            );
        }

        $startedAt =
            (string) (
                $existing['started_at']
                ?? ''
            );

        if (
            $this->compareDateTimes(
                $lastActivityAt,
                $startedAt
            ) < 0
        ) {
            throw new \InvalidArgumentException(
                'Session activity cannot precede session start.'
            );
        }

        $currentLastActivity =
            (string) (
                $existing['last_activity_at']
                ?? $startedAt
            );

        if (
            $this->compareDateTimes(
                $lastActivityAt,
                $currentLastActivity
            ) < 0
        ) {
            throw new \InvalidArgumentException(
                'Session last_activity_at cannot move backwards.'
            );
        }

        $currentDuration =
            (int) (
                $existing['duration_seconds']
                ?? 0
            );

        $currentActive =
            (int) (
                $existing['active_seconds']
                ?? 0
            );

        if (
            $durationSeconds <
            $currentDuration
        ) {
            throw new \InvalidArgumentException(
                'Session duration cannot decrease.'
            );
        }

        if (
            $durationSeconds <
            $currentActive
        ) {
            throw new \InvalidArgumentException(
                'Session duration cannot be less than active seconds.'
            );
        }

        if (
            $durationSeconds ===
                $currentDuration
            && $lastActivityAt ===
                $currentLastActivity
        ) {
            return true;
        }

        $table = $this->table();

        $affected =
            $this->database->execute(
                "UPDATE {$table}
                 SET
                     duration_seconds =
                         GREATEST(
                             duration_seconds,
                             %d
                         ),
                     last_activity_at =
                         GREATEST(
                             last_activity_at,
                             %s
                         ),
                     updated_at = %s
                 WHERE session_id = %s",
                $durationSeconds,
                $lastActivityAt,
                $this->nowUtc(),
                $sessionId
            );

        return $affected > 0;
    }

    public function addActiveSeconds(
        string $sessionId,
        int $seconds
    ): bool {
        $sessionId = trim(
            $sessionId
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        if ($seconds < 1) {
            throw new \InvalidArgumentException(
                'Session active seconds increment must be greater than zero.'
            );
        }

        $existing = $this->findByIdentifier(
            $sessionId
        );

        if ($existing === null) {
            return false;
        }

        if (
            $existing['ended_at'] !== null
            && trim(
                (string) $existing['ended_at']
            ) !== ''
        ) {
            throw new \InvalidArgumentException(
                'Cannot add active seconds to an ended session.'
            );
        }

        $currentDuration =
            (int) (
                $existing['duration_seconds']
                ?? 0
            );

        $currentActive =
            (int) (
                $existing['active_seconds']
                ?? 0
            );

        if (
            $currentActive + $seconds >
            $currentDuration
        ) {
            throw new \InvalidArgumentException(
                'Session active seconds cannot exceed session duration.'
            );
        }

        $table = $this->table();

        $affected =
            $this->database->execute(
                "UPDATE {$table}
                 SET
                     active_seconds =
                         active_seconds + %d,
                     updated_at = %s
                 WHERE session_id = %s",
                $seconds,
                $this->nowUtc(),
                $sessionId
            );

        return $affected > 0;
    }

    public function close(
        string $sessionId,
        string $endedAt,
        int $durationSeconds,
        int $activeSeconds
    ): bool {
        $sessionId = trim(
            $sessionId
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $this->validateDateTime(
            $endedAt
        );

        $this->validateNonNegativeInteger(
            $durationSeconds,
            'Session duration'
        );

        $this->validateNonNegativeInteger(
            $activeSeconds,
            'Session active seconds'
        );

        if (
            $activeSeconds >
            $durationSeconds
        ) {
            throw new \InvalidArgumentException(
                'Session active seconds cannot exceed session duration.'
            );
        }

        $existing = $this->findByIdentifier(
            $sessionId
        );

        if ($existing === null) {
            return false;
        }

        $startedAt =
            (string) $existing['started_at'];

        if (
            $this->compareDateTimes(
                $endedAt,
                $startedAt
            ) < 0
        ) {
            throw new \InvalidArgumentException(
                'Session cannot end before it starts.'
            );
        }

        $lastActivity =
            (string) (
                $existing['last_activity_at']
                ?? $startedAt
            );

        if (
            $this->compareDateTimes(
                $lastActivity,
                $endedAt
            ) > 0
        ) {
            throw new \InvalidArgumentException(
                'Session cannot end before its last activity.'
            );
        }

        $currentDuration =
            (int) (
                $existing['duration_seconds']
                ?? 0
            );

        $currentActive =
            (int) (
                $existing['active_seconds']
                ?? 0
            );

        if (
            $durationSeconds <
            $currentDuration
        ) {
            throw new \InvalidArgumentException(
                'Session duration cannot decrease.'
            );
        }

        if (
            $activeSeconds <
            $currentActive
        ) {
            throw new \InvalidArgumentException(
                'Session active seconds cannot decrease.'
            );
        }

        $currentEndedAt =
            $existing['ended_at'] ?? null;

        if (
            $currentEndedAt !== null
            && $this->compareDateTimes(
                $endedAt,
                (string) $currentEndedAt
            ) < 0
        ) {
            throw new \InvalidArgumentException(
                'Session ended_at cannot move backwards.'
            );
        }

        return $this->updateByIdentifier(
            $sessionId,
            [
                'ended_at' =>
                    $endedAt,

                'duration_seconds' =>
                    $durationSeconds,

                'active_seconds' =>
                    $activeSeconds,

                'updated_at' =>
                    $this->nowUtc(),
            ],
            [
                '%s',
                '%d',
                '%d',
                '%s',
            ]
        );
    }

    public function updateLandingPage(
        string $sessionId,
        ?int $pageId,
        string $url
    ): bool {
        return $this->updatePage(
            $sessionId,
            $pageId,
            $url,
            true
        );
    }

    public function updateExitPage(
        string $sessionId,
        ?int $pageId,
        string $url
    ): bool {
        return $this->updatePage(
            $sessionId,
            $pageId,
            $url,
            false
        );
    }

    /**
     * @param array<string, mixed> $source
     */
    public function updateSource(
        string $sessionId,
        array $source
    ): bool {
        $sessionId = trim(
            $sessionId
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $data = [];

        if (
            array_key_exists(
                'source_type',
                $source
            )
        ) {
            $sourceType = trim(
                (string) $source['source_type']
            );

            if (
                $sourceType === ''
                || strlen($sourceType) > 32
            ) {
                throw new \InvalidArgumentException(
                    'Session source type is invalid.'
                );
            }

            $data['source_type'] =
                $sourceType;
        }

        if (
            array_key_exists(
                'source_name',
                $source
            )
        ) {
            $data['source_name'] =
                $this->normalizeNullableString(
                    $source['source_name'],
                    128
                );
        }

        if (
            array_key_exists(
                'source_domain',
                $source
            )
        ) {
            $data['source_domain'] =
                $this->normalizeNullableString(
                    $source['source_domain'],
                    255
                );
        }

        if (
            array_key_exists(
                'referrer_url',
                $source
            )
        ) {
            $data['referrer_url'] =
                $this->normalizeNullableString(
                    $source['referrer_url'],
                    null
                );
        }

        if ($data === []) {
            return false;
        }

        $data['updated_at'] =
            $this->nowUtc();

        return $this->updateByIdentifier(
            $sessionId,
            $data,
            $this->buildFormats(
                $data
            )
        );
    }

    /**
     * @param array<string, mixed> $geo
     */
    public function updateGeo(
        string $sessionId,
        array $geo
    ): bool {
        $sessionId = trim(
            $sessionId
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $data = [];

        foreach (
            [
                'country_code' => 2,
                'region_code' => 16,
                'city' => 128,
                'country_name' => 128,
                'region_name' => 128,
                'geo_source' => 64,
                'geo_database_version' => 32,
            ] as $field => $maxLength
        ) {
            if (
                array_key_exists(
                    $field,
                    $geo
                )
            ) {
                $data[$field] =
                    $this->normalizeNullableString(
                        $geo[$field],
                        $maxLength
                    );
            }
        }

        foreach (
            [
                'latitude' => [
                    -90,
                    90,
                ],
                'longitude' => [
                    -180,
                    180,
                ],
            ] as $field => $range
        ) {
            if (
                array_key_exists(
                    $field,
                    $geo
                )
            ) {
                $data[$field] =
                    $this->normalizeCoordinate(
                        $geo[$field],
                        $field,
                        $range[0],
                        $range[1]
                    );
            }
        }

        if ($data === []) {
            return false;
        }

        $data['updated_at'] =
            $this->nowUtc();

        return $this->updateByIdentifier(
            $sessionId,
            $data,
            $this->buildFormats(
                $data
            )
        );
    }

    /**
     * @param array<string, mixed> $device
     */
    public function updateDevice(
        string $sessionId,
        array $device
    ): bool {
        $sessionId = trim(
            $sessionId
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $data = [];

        foreach (
            [
                'device_type' => 32,
                'browser' => 64,
                'os' => 64,
            ] as $field => $maxLength
        ) {
            if (
                array_key_exists(
                    $field,
                    $device
                )
            ) {
                $data[$field] =
                    $this->normalizeNullableString(
                        $device[$field],
                        $maxLength
                    );
            }
        }

        if ($data === []) {
            return false;
        }

        $data['updated_at'] =
            $this->nowUtc();

        return $this->updateByIdentifier(
            $sessionId,
            $data,
            $this->buildFormats(
                $data
            )
        );
    }

    public function updateBot(
        string $sessionId,
        int $score,
        string $classification
    ): bool {
        $sessionId = trim(
            $sessionId
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
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
            $sessionId,
            [
                'bot_score' =>
                    $score,

                'bot_classification' =>
                    $classification,

                'updated_at' =>
                    $this->nowUtc(),
            ],
            [
                '%d',
                '%s',
                '%s',
            ]
        );
    }

    public function updateTrackingMode(
        string $sessionId,
        string $trackingMode
    ): bool {
        $sessionId = trim(
            $sessionId
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $trackingMode = trim(
            $trackingMode
        );

        $this->validateTrackingMode(
            $trackingMode
        );

        $session = $this->findByIdentifier(
            $sessionId
        );

        if ($session === null) {
            return false;
        }

        $currentMode =
            (string) (
                $session['tracking_mode']
                ?? 'full'
            );

        if (
            $currentMode === 'full'
            && $trackingMode === 'server_only'
        ) {
            return false;
        }

        if (
            $currentMode === $trackingMode
        ) {
            return true;
        }

        return $this->updateByIdentifier(
            $sessionId,
            [
                'tracking_mode' =>
                    $trackingMode,

                'updated_at' =>
                    $this->nowUtc(),
            ],
            [
                '%s',
                '%s',
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getContext(
        string $sessionId
    ): ?array {
        $session = $this->findById(
            $sessionId
        );

        if ($session === null) {
            return null;
        }

        return [
            'session_id' =>
                $session['session_id'],

            'visitor_id' =>
                $session['visitor_id'],

            'started_at' =>
                $session['started_at'],

            'last_activity_at' =>
                $session['last_activity_at'],

            'ended_at' =>
                $session['ended_at'],

            'duration_seconds' =>
                (int) $session['duration_seconds'],

            'active_seconds' =>
                (int) $session['active_seconds'],

            'landing_page_id' =>
                $session['landing_page_id'] !== null
                    ? (int) $session['landing_page_id']
                    : null,

            'landing_url' =>
                $session['landing_url'],

            'exit_page_id' =>
                $session['exit_page_id'] !== null
                    ? (int) $session['exit_page_id']
                    : null,

            'exit_url' =>
                $session['exit_url'],

            'source_type' =>
                $session['source_type'],

            'source_name' =>
                $session['source_name'],

            'source_domain' =>
                $session['source_domain'],

            'referrer_url' =>
                $session['referrer_url'],

            'country_code' =>
                $session['country_code'],

            'region_code' =>
                $session['region_code'],

            'city' =>
                $session['city'],

            'country_name' =>
                $session['country_name'],

            'region_name' =>
                $session['region_name'],

            'latitude' =>
                $session['latitude'],

            'longitude' =>
                $session['longitude'],

            'geo_source' =>
                $session['geo_source'],

            'geo_database_version' =>
                $session['geo_database_version'],

            'device_type' =>
                $session['device_type'],

            'browser' =>
                $session['browser'],

            'os' =>
                $session['os'],

            'bot_score' =>
                (int) $session['bot_score'],

            'bot_classification' =>
                $session['bot_classification'],

            'tracking_mode' =>
                $session['tracking_mode'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildRecord(
        string $sessionId,
        string $visitorId,
        array $data
    ): array {
        $now = $this->nowUtc();

        $startedAt = isset(
            $data['started_at']
        )
            ? trim(
                (string) $data['started_at']
            )
            : $now;

        $lastActivityAt =
            isset(
                $data['last_activity_at']
            )
                ? trim(
                    (string) $data['last_activity_at']
                )
                : $startedAt;

        $record = [
            'session_id' =>
                $sessionId,

            'visitor_id' =>
                $visitorId,

            'started_at' =>
                $startedAt,

            'last_activity_at' =>
                $lastActivityAt,

            'ended_at' =>
                $data['ended_at'] ?? null,

            'duration_seconds' =>
                $data['duration_seconds'] ?? 0,

            'active_seconds' =>
                $data['active_seconds'] ?? 0,

            'landing_page_id' =>
                $data['landing_page_id'] ?? null,

            'landing_url' =>
                trim(
                    (string) (
                        $data['landing_url'] ?? ''
                    )
                ),

            'exit_page_id' =>
                $data['exit_page_id'] ?? null,

            'exit_url' =>
                $this->normalizeNullableString(
                    $data['exit_url'] ?? null,
                    null
                ),

            'source_type' =>
                trim(
                    (string) (
                        $data['source_type']
                        ?? 'unknown'
                    )
                ),

            'source_name' =>
                $this->normalizeNullableString(
                    $data['source_name'] ?? null,
                    128
                ),

            'source_domain' =>
                $this->normalizeNullableString(
                    $data['source_domain'] ?? null,
                    255
                ),

            'referrer_url' =>
                $this->normalizeNullableString(
                    $data['referrer_url'] ?? null,
                    null
                ),

            'country_code' =>
                $this->normalizeNullableString(
                    $data['country_code'] ?? null,
                    2
                ),

            'region_code' =>
                $this->normalizeNullableString(
                    $data['region_code'] ?? null,
                    16
                ),

            'city' =>
                $this->normalizeNullableString(
                    $data['city'] ?? null,
                    128
                ),

            'country_name' =>
                $this->normalizeNullableString(
                    $data['country_name'] ?? null,
                    128
                ),

            'region_name' =>
                $this->normalizeNullableString(
                    $data['region_name'] ?? null,
                    128
                ),

            'latitude' =>
                $this->normalizeCoordinate(
                    $data['latitude'] ?? null,
                    'latitude',
                    -90,
                    90
                ),

            'longitude' =>
                $this->normalizeCoordinate(
                    $data['longitude'] ?? null,
                    'longitude',
                    -180,
                    180
                ),

            'geo_source' =>
                $this->normalizeNullableString(
                    $data['geo_source'] ?? null,
                    64
                ),

            'geo_database_version' =>
                $this->normalizeNullableString(
                    $data['geo_database_version'] ?? null,
                    32
                ),

            'device_type' =>
                $this->normalizeNullableString(
                    $data['device_type'] ?? null,
                    32
                ),

            'browser' =>
                $this->normalizeNullableString(
                    $data['browser'] ?? null,
                    64
                ),

            'os' =>
                $this->normalizeNullableString(
                    $data['os'] ?? null,
                    64
                ),

            'bot_score' =>
                $data['bot_score'] ?? 0,

            'bot_classification' =>
                trim(
                    (string) (
                        $data['bot_classification']
                        ?? 'unknown'
                    )
                ),

            'tracking_mode' =>
                trim(
                    (string) (
                        $data['tracking_mode']
                        ?? 'full'
                    )
                ),

            'created_at' =>
                $data['created_at'] ?? $now,

            'updated_at' =>
                $data['updated_at'] ?? $now,
        ];

        $this->validateRecord(
            $record
        );

        return $record;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function filterMutableUpdateData(
        array $data
    ): array {
        $allowed = [
            'last_activity_at',
            'ended_at',
            'duration_seconds',
            'active_seconds',
            'landing_page_id',
            'landing_url',
            'exit_page_id',
            'exit_url',
            'source_type',
            'source_name',
            'source_domain',
            'referrer_url',
            'country_code',
            'region_code',
            'city',
            'country_name',
            'region_name',
            'latitude',
            'longitude',
            'geo_source',
            'geo_database_version',
            'device_type',
            'browser',
            'os',
            'bot_score',
            'bot_classification',
            'tracking_mode',
        ];

        $result = [];

        foreach ($allowed as $field) {
            if (array_key_exists(
                $field,
                $data
            )) {
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
        if (array_key_exists(
            'last_activity_at',
            $data
        )) {
            $value =
                trim(
                    (string) $data[
                        'last_activity_at'
                    ]
                );

            $this->validateDateTime(
                $value
            );

            $current =
                (string) (
                    $existing['last_activity_at']
                    ?? $existing['started_at']
                );

            if (
                $this->compareDateTimes(
                    $value,
                    $current
                ) < 0
            ) {
                throw new \InvalidArgumentException(
                    'Session last_activity_at cannot move backwards.'
                );
            }

            if (
                $this->compareDateTimes(
                    $value,
                    (string) $existing['started_at']
                ) < 0
            ) {
                throw new \InvalidArgumentException(
                    'Session last_activity_at cannot precede session start.'
                );
            }

            if (
                $existing['ended_at'] !== null
                && $this->compareDateTimes(
                    $value,
                    (string) $existing['ended_at']
                ) > 0
            ) {
                throw new \InvalidArgumentException(
                    'Session activity cannot occur after session end.'
                );
            }
        }

        if (
            array_key_exists(
                'ended_at',
                $data
            )
            && $data['ended_at'] !== null
        ) {
            $endedAt =
                trim(
                    (string) $data[
                        'ended_at'
                    ]
                );

            $this->validateDateTime(
                $endedAt
            );

            if (
                $this->compareDateTimes(
                    $endedAt,
                    (string) $existing['started_at']
                ) < 0
            ) {
                throw new \InvalidArgumentException(
                    'Session cannot end before it starts.'
                );
            }

            if (
                $existing['ended_at'] !== null
                && $this->compareDateTimes(
                    $endedAt,
                    (string) $existing['ended_at']
                ) < 0
            ) {
                throw new \InvalidArgumentException(
                    'Session ended_at cannot move backwards.'
                );
            }

            $lastActivity =
                (string) (
                    $existing['last_activity_at']
                    ?? $existing['started_at']
                );

            if (
                $this->compareDateTimes(
                    $lastActivity,
                    $endedAt
                ) > 0
            ) {
                throw new \InvalidArgumentException(
                    'Session cannot end before its last activity.'
                );
            }
        }

        foreach (
            [
                'duration_seconds' =>
                    'Session duration',

                'active_seconds' =>
                    'Session active seconds',
            ] as $field => $label
        ) {
            if (!array_key_exists(
                $field,
                $data
            )) {
                continue;
            }

            $this->validateNonNegativeInteger(
                $data[$field],
                $label
            );

            $current =
                (int) (
                    $existing[$field] ?? 0
                );

            if (
                (int) $data[$field]
                < $current
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        '%s cannot decrease.',
                        $label
                    )
                );
            }
        }

        $effectiveDuration =
            array_key_exists(
                'duration_seconds',
                $data
            )
                ? (int) $data['duration_seconds']
                : (int) (
                    $existing['duration_seconds']
                    ?? 0
                );

        $effectiveActive =
            array_key_exists(
                'active_seconds',
                $data
            )
                ? (int) $data['active_seconds']
                : (int) (
                    $existing['active_seconds']
                    ?? 0
                );

        if (
            $effectiveActive >
            $effectiveDuration
        ) {
            throw new \InvalidArgumentException(
                'Session active seconds cannot exceed session duration.'
            );
        }

        foreach (
            [
                'landing_page_id' =>
                    'Landing page ID',

                'exit_page_id' =>
                    'Exit page ID',
            ] as $field => $label
        ) {
            if (
                array_key_exists(
                    $field,
                    $data
                )
                && $data[$field] !== null
            ) {
                $this->validateNonNegativeInteger(
                    $data[$field],
                    $label
                );
            }
        }

        if (array_key_exists(
            'landing_url',
            $data
        )) {
            $this->validateImmutablePageValue(
                $existing,
                'landing_url',
                'landing_page_id',
                $data['landing_url'],
                $data['landing_page_id']
                    ?? null,
                'Landing page'
            );
        }

        if (array_key_exists(
            'exit_url',
            $data
        )) {
            $this->validateImmutablePageValue(
                $existing,
                'exit_url',
                'exit_page_id',
                $data['exit_url'],
                $data['exit_page_id']
                    ?? null,
                'Exit page'
            );
        }

        if (array_key_exists(
            'bot_score',
            $data
        )) {
            $this->validateInteger(
                $data['bot_score'],
                'Bot score'
            );

            $this->validateBotScore(
                (int) $data['bot_score']
            );
        }

        if (array_key_exists(
            'bot_classification',
            $data
        )) {
            $this->validateBotClassification(
                trim(
                    (string) $data[
                        'bot_classification'
                    ]
                )
            );
        }

        if (array_key_exists(
            'tracking_mode',
            $data
        )) {
            $trackingMode =
                trim(
                    (string) $data[
                        'tracking_mode'
                    ]
                );

            $this->validateTrackingMode(
                $trackingMode
            );

            $currentMode =
                (string) (
                    $existing['tracking_mode']
                    ?? 'full'
                );

            if (
                $currentMode === 'full'
                && $trackingMode === 'server_only'
            ) {
                throw new \InvalidArgumentException(
                    'Session tracking mode cannot downgrade from full to server_only.'
                );
            }
        }

        if (array_key_exists(
            'source_type',
            $data
        )) {
            $sourceType =
                trim(
                    (string) $data[
                        'source_type'
                    ]
                );

            if (
                $sourceType === ''
                || strlen($sourceType) > 32
            ) {
                throw new \InvalidArgumentException(
                    'Session source type is invalid.'
                );
            }
        }

        foreach (
            [
                'source_name' => 128,
                'source_domain' => 255,
                'referrer_url' => null,
                'country_code' => 2,
                'region_code' => 16,
                'city' => 128,
                'country_name' => 128,
                'region_name' => 128,
                'geo_source' => 64,
                'geo_database_version' => 32,
                'device_type' => 32,
                'browser' => 64,
                'os' => 64,
            ] as $field => $maxLength
        ) {
            if (array_key_exists(
                $field,
                $data
            )) {
                $this->normalizeNullableString(
                    $data[$field],
                    $maxLength
                );
            }
        }

        foreach (
            [
                'latitude' => [
                    -90,
                    90,
                ],
                'longitude' => [
                    -180,
                    180,
                ],
            ] as $field => $range
        ) {
            if (
                array_key_exists(
                    $field,
                    $data
                )
            ) {
                $this->normalizeCoordinate(
                    $data[$field],
                    $field,
                    $range[0],
                    $range[1]
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function validateImmutablePageValue(
        array $existing,
        string $urlField,
        string $pageField,
        mixed $url,
        mixed $pageId,
        string $label
    ): void {
        $newUrl = trim(
            (string) $url
        );

        $currentUrl =
            trim(
                (string) (
                    $existing[$urlField]
                    ?? ''
                )
            );

        if (
            $currentUrl !== ''
            && $currentUrl !== $newUrl
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s URL cannot change once established.',
                    $label
                )
            );
        }

        $currentPageId =
            $existing[$pageField] ?? null;

        if (
            $currentPageId !== null
            && $pageId !== null
            && (int) $currentPageId
                !== (int) $pageId
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s page ID cannot change once established.',
                    $label
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
            (string) $record['session_id'],
            'Session ID'
        );

        $this->assertUuid(
            (string) $record['visitor_id'],
            'Visitor ID'
        );

        $this->validateDateTime(
            (string) $record['started_at']
        );

        $this->validateDateTime(
            (string) $record['last_activity_at']
        );

        if (
            $this->compareDateTimes(
                (string) $record['last_activity_at'],
                (string) $record['started_at']
            ) < 0
        ) {
            throw new \InvalidArgumentException(
                'Last activity cannot precede session start.'
            );
        }

        if (
            $record['ended_at'] !== null
        ) {
            $this->validateDateTime(
                (string) $record['ended_at']
            );

            if (
                $this->compareDateTimes(
                    (string) $record['ended_at'],
                    (string) $record['started_at']
                ) < 0
            ) {
                throw new \InvalidArgumentException(
                    'Session cannot end before it starts.'
                );
            }

            if (
                $this->compareDateTimes(
                    (string) $record['last_activity_at'],
                    (string) $record['ended_at']
                ) > 0
            ) {
                throw new \InvalidArgumentException(
                    'Last activity cannot occur after session end.'
                );
            }
        }

        $this->validateNonNegativeInteger(
            $record['duration_seconds'],
            'Session duration'
        );

        $this->validateNonNegativeInteger(
            $record['active_seconds'],
            'Session active seconds'
        );

        if (
            (int) $record['active_seconds'] >
            (int) $record['duration_seconds']
        ) {
            throw new \InvalidArgumentException(
                'Session active seconds cannot exceed session duration.'
            );
        }

        if (
            $record['landing_page_id'] !== null
        ) {
            $this->validateNonNegativeInteger(
                $record['landing_page_id'],
                'Landing page ID'
            );
        }

        if (
            $record['exit_page_id'] !== null
        ) {
            $this->validateNonNegativeInteger(
                $record['exit_page_id'],
                'Exit page ID'
            );
        }

        if (!is_string(
            $record['landing_url']
        )) {
            throw new \InvalidArgumentException(
                'Session landing URL must be a string.'
            );
        }

        if (
            $record['exit_url'] !== null
            && !is_string(
                $record['exit_url']
            )
        ) {
            throw new \InvalidArgumentException(
                'Session exit URL must be a string or null.'
            );
        }

        $sourceType =
            trim(
                (string) $record['source_type']
            );

        if (
            $sourceType === ''
            || strlen($sourceType) > 32
        ) {
            throw new \InvalidArgumentException(
                'Session source type is invalid.'
            );
        }

        foreach (
            [
                'country_code' => 2,
                'region_code' => 16,
                'city' => 128,
                'country_name' => 128,
                'region_name' => 128,
                'geo_source' => 64,
                'geo_database_version' => 32,
            ] as $field => $maxLength
        ) {
            $this->normalizeNullableString(
                $record[$field] ?? null,
                $maxLength
            );
        }

        $this->normalizeCoordinate(
            $record['latitude'] ?? null,
            'latitude',
            -90,
            90
        );

        $this->normalizeCoordinate(
            $record['longitude'] ?? null,
            'longitude',
            -180,
            180
        );

        $this->validateBotScore(
            (int) $record['bot_score']
        );

        $this->validateBotClassification(
            (string) $record['bot_classification']
        );

        $this->validateTrackingMode(
            (string) $record['tracking_mode']
        );

        $this->validateDateTime(
            (string) $record['created_at']
        );

        $this->validateDateTime(
            (string) $record['updated_at']
        );
    }

    private function updatePage(
        string $sessionId,
        ?int $pageId,
        string $url,
        bool $landing
    ): bool {
        $sessionId = trim(
            $sessionId
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        $url = trim(
            $url
        );

        if ($url === '') {
            throw new \InvalidArgumentException(
                $landing
                    ? 'Landing URL cannot be empty.'
                    : 'Exit URL cannot be empty.'
            );
        }

        if ($pageId !== null) {
            $this->validateNonNegativeInteger(
                $pageId,
                $landing
                    ? 'Landing page ID'
                    : 'Exit page ID'
            );
        }

        $urlField =
            $landing
                ? 'landing_url'
                : 'exit_url';

        $pageField =
            $landing
                ? 'landing_page_id'
                : 'exit_page_id';

        $label =
            $landing
                ? 'Landing page'
                : 'Exit page';

        $existing = $this->findByIdentifier(
            $sessionId
        );

        if ($existing === null) {
            return false;
        }

        $currentUrl =
            trim(
                (string) (
                    $existing[$urlField]
                    ?? ''
                )
            );

        if ($currentUrl !== '') {
            $this->validateImmutablePageValue(
                $existing,
                $urlField,
                $pageField,
                $url,
                $pageId,
                $label
            );

            return true;
        }

        $data = [];

        if ($pageId !== null) {
            $data[$pageField] =
                $pageId;
        }

        $data[$urlField] =
            $url;

        $data['updated_at'] =
            $this->nowUtc();

        return $this->updateByIdentifier(
            $sessionId,
            $data,
            $this->buildFormats(
                $data
            )
        );
    }

    /**
     * @param array<string, mixed> $session
     */
    private function assertOwnership(
        array $session,
        string $visitorId
    ): void {
        if (
            (string) (
                $session['visitor_id']
                ?? ''
            ) !== $visitorId
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
            'duration_seconds',
            'active_seconds',
            'landing_page_id',
            'exit_page_id',
            'bot_score',
        ];

        $formats = [];

        foreach ($data as $field => $value) {
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

    private function validateTrackingMode(
        string $trackingMode
    ): void {
        if (!in_array(
            $trackingMode,
            self::TRACKING_MODES,
            true
        )) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid tracking mode: %s',
                    $trackingMode
                )
            );
        }
    }

    private function validateBotClassification(
        string $classification
    ): void {
        if (!in_array(
            $classification,
            self::BOT_CLASSIFICATIONS,
            true
        )) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid bot classification: %s',
                    $classification
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

    private function validateNonNegativeInteger(
        mixed $value,
        string $label
    ): void {
        $this->validateInteger(
            $value,
            $label
        );

        if ((int) $value < 0) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s cannot be negative.',
                    $label
                )
            );
        }
    }

    private function validateInteger(
        mixed $value,
        string $label
    ): void {
        if (
            !is_int($value)
            && !(
                is_string($value)
                && ctype_digit($value)
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s must be an integer.',
                    $label
                )
            );
        }
    }

    private function normalizeNullableString(
        mixed $value,
        ?int $maxLength
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'Expected a string or null.'
            );
        }

        $value = trim(
            $value
        );

        if ($value === '') {
            return null;
        }

        if (
            $maxLength !== null
            && (
                function_exists(
                    'mb_strlen'
                )
                    ? mb_strlen(
                        $value,
                        'UTF-8'
                    )
                    : strlen($value)
            ) > $maxLength
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'String value cannot exceed %d characters.',
                    $maxLength
                )
            );
        }

        return $value;
    }

    private function normalizeCoordinate(
        mixed $value,
        string $field,
        float $minimum,
        float $maximum
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (
            is_int($value)
            || is_float($value)
        ) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Session field %s must be a decimal string or null.',
                    $field
                )
            );
        }

        $value = trim(
            $value
        );

        if ($value === '') {
            return null;
        }

        if (
            preg_match(
                '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/',
                $value
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Session field %s must be a valid decimal value.',
                    $field
                )
            );
        }

        $numeric =
            (float) $value;

        if (
            !is_finite($numeric)
            || $numeric < $minimum
            || $numeric > $maximum
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Session field %s must be between %s and %s.',
                    $field,
                    $minimum,
                    $maximum
                )
            );
        }

        return $value;
    }

    private function validateDateTime(
        string $value
    ): void {
        $value = trim(
            $value
        );

        $date =
            \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $value,
                new \DateTimeZone('UTC')
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

    private function compareDateTimes(
        string $left,
        string $right
    ): int {
        $leftDate =
            $this->createDateTime(
                $left
            );

        $rightDate =
            $this->createDateTime(
                $right
            );

        return $leftDate->getTimestamp()
            <=>
            $rightDate->getTimestamp();
    }

    private function createDateTime(
        string $value
    ): \DateTimeImmutable {
        $this->validateDateTime(
            $value
        );

        $date =
            \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                trim($value),
                new \DateTimeZone('UTC')
            );

        if ($date === false) {
            throw new \RuntimeException(
                'Unable to create session datetime.'
            );
        }

        return $date;
    }

    private function assertUuid(
        string $value,
        string $label
    ): void {
        $value = trim(
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