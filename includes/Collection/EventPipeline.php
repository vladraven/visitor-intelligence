<?php

declare(strict_types=1);

namespace VisitorIntelligence\Collection;

use VisitorIntelligence\Database\Database;
use VisitorIntelligence\Database\Repositories\EventRepository;
use VisitorIntelligence\Database\Repositories\PageviewRepository;
use VisitorIntelligence\Database\Repositories\SessionRepository;
use VisitorIntelligence\Database\Repositories\VisitorRepository;

defined('ABSPATH') || exit;

final class EventPipeline
{
    private const EVENT_PAGE_VISIBLE = 'page_visible';

    private const EVENT_PAGE_HIDDEN = 'page_hidden';

    private const EVENT_PAGE_LEAVE = 'page_leave';

    private const EVENT_HEARTBEAT = 'heartbeat';

    private const ACTIVE_STATE_TTL = 60;

    private const HEARTBEAT_INTERVAL = 10;

    private const HEARTBEAT_MAX_DELTA = 15;

    private const EVENT_TYPE_MAX_LENGTH = 64;

    private const SCHEMA_VERSION_MAX_LENGTH = 8;

    private const MAX_BATCH_SIZE = 50;

    private Database $database;

    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly PageviewRepository $pageviewRepository,
        private readonly SessionRepository $sessionRepository,
        private readonly VisitorRepository $visitorRepository,
        ?Database $database = null
    ) {
        $this->database =
            $database
            ?? new Database();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{processed:int,failed:int}
     */
    public function processBatch(
        array $payload
    ): array {
        $visitorId =
            trim(
                (string) (
                    $payload['visitor_id']
                    ?? ''
                )
            );

        $sessionId =
            trim(
                (string) (
                    $payload['session_id']
                    ?? ''
                )
            );

        $trackingMode =
            trim(
                (string) (
                    $payload['tracking_mode']
                    ?? 'full'
                )
            );

        $events =
            $payload['events']
            ?? null;

        if (
            $visitorId === ''
            || $sessionId === ''
            || !is_array($events)
            || $events === []
        ) {
            throw new \InvalidArgumentException(
                'Invalid event pipeline payload.'
            );
        }

        if (
            count($events) > self::MAX_BATCH_SIZE
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Event batch cannot contain more than %d events.',
                    self::MAX_BATCH_SIZE
                )
            );
        }

        $this->assertUuid(
            $visitorId,
            'Visitor ID'
        );

        $this->assertUuid(
            $sessionId,
            'Session ID'
        );

        if (
            !in_array(
                $trackingMode,
                [
                    'full',
                    'server_only',
                ],
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'Invalid tracking mode.'
            );
        }

        $this->assertVisitorExists(
            $visitorId
        );

        $this->assertSessionOwnership(
            $sessionId,
            $visitorId
        );

        if (
            $trackingMode === 'full'
        ) {
            $this->sessionRepository->updateTrackingMode(
                $sessionId,
                'full'
            );
        }

        $processed = 0;

        $failed = 0;

        foreach (
            $events as $event
        ) {
            if (
                !is_array($event)
            ) {
                $failed++;

                do_action(
                    'vi_event_pipeline_error',
                    new \InvalidArgumentException(
                        'Event payload must be an array.'
                    ),
                    $event,
                    $visitorId,
                    $sessionId
                );

                continue;
            }

            try {
                $this->processEvent(
                    $visitorId,
                    $sessionId,
                    $event
                );

                $processed++;
            } catch (
                \Throwable $exception
            ) {
                $failed++;

                do_action(
                    'vi_event_pipeline_error',
                    $exception,
                    $event,
                    $visitorId,
                    $sessionId
                );
            }
        }

        return [
            'processed' =>
                $processed,

            'failed' =>
                $failed,
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function processEvent(
        string $visitorId,
        string $sessionId,
        array $event
    ): void {
        $eventId =
            trim(
                (string) (
                    $event['event_id']
                    ?? ''
                )
            );

        $eventType =
            trim(
                (string) (
                    $event['event_type']
                    ?? ''
                )
            );

        if (
            $eventId === ''
            || $eventType === ''
        ) {
            throw new \InvalidArgumentException(
                'Event ID and event type are required.'
            );
        }

        if (
            strlen($eventType)
            > self::EVENT_TYPE_MAX_LENGTH
        ) {
            throw new \InvalidArgumentException(
                'Event type is too long.'
            );
        }

        $this->assertUuid(
            $eventId,
            'Event ID'
        );

        $schemaVersion =
            $this->normalizeSchemaVersion(
                $event['schema_version']
                ?? 'v1'
            );

        $occurredAt =
            $this->parseOccurredAt(
                $event['occurred_at']
                ?? null
            );

        $pageviewId =
            $this->normalizePageviewId(
                $event['pageview_id']
                ?? null
            );

        $eventPayload =
            $this->normalizeEventPayload(
                $event['payload']
                ?? []
            );

        if (
            $pageviewId !== null
        ) {
            $this->assertPageviewOwnership(
                $pageviewId,
                $visitorId,
                $sessionId
            );
        }

        $existingEvent =
            $this->eventRepository->findById(
                $eventId
            );

        if (
            $existingEvent !== null
        ) {
            $this->assertExistingEventIdentity(
                $existingEvent,
                $visitorId,
                $sessionId,
                $eventType,
                $pageviewId,
                $schemaVersion,
                $occurredAt,
                $eventPayload
            );

            return;
        }

        $this->database->beginTransaction();

        $committed = false;

        $postCommitAction = null;

        try {
            $session =
                $this->sessionRepository->findById(
                    $sessionId
                );

            if (
                $session === null
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Session does not exist: %s',
                        $sessionId
                    )
                );
            }

            if (
                (string) (
                    $session['visitor_id']
                    ?? ''
                ) !== $visitorId
            ) {
                throw new \InvalidArgumentException(
                    'Session does not belong to the specified visitor.'
                );
            }

            $activeState =
                $this->getActiveState(
                    $sessionId
                );

            switch (
                $eventType
            ) {
                case self::EVENT_PAGE_VISIBLE:
                    $postCommitAction =
                        $this->preparePageVisible(
                            $sessionId,
                            $pageviewId,
                            $occurredAt,
                            $activeState
                        );

                    break;

                case self::EVENT_HEARTBEAT:
                    $postCommitAction =
                        $this->prepareHeartbeat(
                            $visitorId,
                            $sessionId,
                            $pageviewId,
                            $occurredAt,
                            $activeState
                        );

                    break;

                case self::EVENT_PAGE_HIDDEN:
                case self::EVENT_PAGE_LEAVE:
                    $postCommitAction =
                        $this->prepareActivityStop(
                            $visitorId,
                            $sessionId,
                            $pageviewId,
                            $occurredAt,
                            $activeState
                        );

                    break;

                default:
                    $this->updateSessionClock(
                        $session,
                        $occurredAt
                    );

                    $this->sessionRepository->touch(
                        $sessionId,
                        $occurredAt
                    );

                    break;
            }

            $this->eventRepository->persist(
                [
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
                        $eventPayload,
                ]
            );

            $storedEvent =
                $this->eventRepository->findById(
                    $eventId
                );

            if (
                $storedEvent === null
            ) {
                throw new \RuntimeException(
                    sprintf(
                        'Event was not persisted: %s',
                        $eventId
                    )
                );
            }

            $this->assertExistingEventIdentity(
                $storedEvent,
                $visitorId,
                $sessionId,
                $eventType,
                $pageviewId,
                $schemaVersion,
                $occurredAt,
                $eventPayload
            );

            $this->database->commit();

            $committed = true;
        } catch (
            \Throwable $exception
        ) {
            if (
                !$committed
            ) {
                try {
                    $this->database->rollback();
                } catch (
                    \Throwable $rollbackException
                ) {
                    do_action(
                        'vi_event_pipeline_rollback_error',
                        $rollbackException,
                        $event,
                        $visitorId,
                        $sessionId
                    );
                }
            }

            throw $exception;
        }

        if (
            $postCommitAction !== null
        ) {
            $this->executePostCommitAction(
                $postCommitAction,
                $sessionId
            );
        }
    }

    /**
     * @param array<string, mixed>|null $state
     * @return array{
     *     type:string,
     *     pageview_id:?string,
     *     occurred_at:string
     * }
     */
    private function preparePageVisible(
        string $sessionId,
        ?string $pageviewId,
        string $occurredAt,
        ?array $state
    ): array {
        if (
            $state !== null
        ) {
            $this->validateStateTimestamp(
                $state['last_signal_at'],
                $occurredAt
            );

            if (
                $state['pageview_id'] !== null
                && $pageviewId !== null
                && $state['pageview_id']
                    !== $pageviewId
            ) {
                return [
                    'type' =>
                        'replace_state',

                    'pageview_id' =>
                        $pageviewId,

                    'occurred_at' =>
                        $occurredAt,
                ];
            }
        }

        return [
            'type' =>
                'store_state',

            'pageview_id' =>
                $pageviewId,

            'occurred_at' =>
                $occurredAt,
        ];
    }

    /**
     * @param array<string, mixed>|null $state
     * @return array{
     *     type:string,
     *     pageview_id:?string,
     *     occurred_at:string
     * }
     */
    private function prepareHeartbeat(
        string $visitorId,
        string $sessionId,
        ?string $pageviewId,
        string $occurredAt,
        ?array $state
    ): array {
        if (
            $state === null
        ) {
            $session =
                $this->sessionRepository->findById(
                    $sessionId
                );

            if (
                $session === null
            ) {
                throw new \RuntimeException(
                    sprintf(
                        'Session does not exist: %s',
                        $sessionId
                    )
                );
            }

            $this->updateSessionClock(
                $session,
                $occurredAt
            );

            $this->sessionRepository->touch(
                $sessionId,
                $occurredAt
            );

            return [
                'type' =>
                    'store_state',

                'pageview_id' =>
                    $pageviewId,

                'occurred_at' =>
                    $occurredAt,
            ];
        }

        $this->validateStateTimestamp(
            $state['last_signal_at'],
            $occurredAt
        );

        $activePageviewId =
            $state['pageview_id'];

        if (
            $pageviewId !== null
            && $activePageviewId !== null
            && $pageviewId !== $activePageviewId
        ) {
            throw new \InvalidArgumentException(
                'Heartbeat pageview does not match active pageview.'
            );
        }

        if (
            $activePageviewId === null
            && $pageviewId !== null
        ) {
            $activePageviewId =
                $pageviewId;
        }

        if (
            $activePageviewId !== null
        ) {
            $this->assertPageviewOwnership(
                $activePageviewId,
                $visitorId,
                $sessionId
            );
        }

        $session =
            $this->sessionRepository->findById(
                $sessionId
            );

        if (
            $session === null
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Session does not exist: %s',
                    $sessionId
                )
            );
        }

        $this->updateSessionClock(
            $session,
            $occurredAt
        );

        $this->sessionRepository->touch(
            $sessionId,
            $occurredAt
        );

        $delta =
            $this->calculateHeartbeatDelta(
                $state,
                $occurredAt
            );

        if (
            $delta > 0
        ) {
            $this->applyActivityDelta(
                $visitorId,
                $sessionId,
                $activePageviewId,
                $delta
            );
        }

        return [
            'type' =>
                'store_state',

            'pageview_id' =>
                $activePageviewId,

            'occurred_at' =>
                $occurredAt,
        ];
    }

    /**
     * @param array<string, mixed>|null $state
     * @return array{
     *     type:string,
     *     pageview_id:?string,
     *     occurred_at:string
     * }
     */
    private function prepareActivityStop(
        string $visitorId,
        string $sessionId,
        ?string $pageviewId,
        string $occurredAt,
        ?array $state
    ): array {
        $session =
            $this->sessionRepository->findById(
                $sessionId
            );

        if (
            $session === null
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Session does not exist: %s',
                    $sessionId
                )
            );
        }

        if (
            $state === null
        ) {
            $this->updateSessionClock(
                $session,
                $occurredAt
            );

            $this->sessionRepository->touch(
                $sessionId,
                $occurredAt
            );

            return [
                'type' =>
                    'clear_state',

                'pageview_id' =>
                    null,

                'occurred_at' =>
                    $occurredAt,
            ];
        }

        $this->validateStateTimestamp(
            $state['last_signal_at'],
            $occurredAt
        );

        $activePageviewId =
            $state['pageview_id'];

        if (
            $activePageviewId !== null
            && $pageviewId !== null
            && $activePageviewId !== $pageviewId
        ) {
            throw new \InvalidArgumentException(
                'Activity stop pageview does not match active pageview.'
            );
        }

        if (
            $activePageviewId === null
            && $pageviewId !== null
        ) {
            $activePageviewId =
                $pageviewId;
        }

        if (
            $activePageviewId !== null
        ) {
            $this->assertPageviewOwnership(
                $activePageviewId,
                $visitorId,
                $sessionId
            );
        }

        $this->updateSessionClock(
            $session,
            $occurredAt
        );

        $this->sessionRepository->touch(
            $sessionId,
            $occurredAt
        );

        $delta =
            $this->calculateBoundedActivityDelta(
                $state['last_signal_at'],
                $occurredAt
            );

        if (
            $delta > 0
        ) {
            $this->applyActivityDelta(
                $visitorId,
                $sessionId,
                $activePageviewId,
                $delta
            );
        }

        return [
            'type' =>
                'clear_state',

            'pageview_id' =>
                null,

            'occurred_at' =>
                $occurredAt,
        ];
    }

    /**
     * @param array<string, mixed> $session
     */
    private function updateSessionClock(
        array $session,
        string $occurredAt
    ): void {
        $startedAt =
            trim(
                (string) (
                    $session['started_at']
                    ?? ''
                )
            );

        if (
            $startedAt === ''
        ) {
            throw new \RuntimeException(
                'Session start timestamp is missing.'
            );
        }

        $duration =
            $this->calculateActivityDelta(
                $startedAt,
                $occurredAt
            );

        $this->sessionRepository->updateDuration(
            (string) $session['session_id'],
            max(
                $duration,
                (int) (
                    $session['duration_seconds']
                    ?? 0
                )
            ),
            $occurredAt
        );
    }

    private function executePostCommitAction(
        array $action,
        string $sessionId
    ): void {
        switch (
            $action['type']
        ) {
            case 'store_state':
            case 'replace_state':
                $this->storeActiveState(
                    $sessionId,
                    $action['pageview_id'],
                    $action['occurred_at']
                );

                break;

            case 'clear_state':
                $this->clearActiveState(
                    $sessionId
                );

                break;

            default:
                throw new \RuntimeException(
                    sprintf(
                        'Unknown post-commit action: %s',
                        $action['type']
                    )
                );
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function calculateHeartbeatDelta(
        array $state,
        string $occurredAt
    ): int {
        $delta =
            $this->calculateActivityDelta(
                (string) $state['last_signal_at'],
                $occurredAt
            );

        if (
            $delta <= 0
        ) {
            return 0;
        }

        if (
            $delta > self::HEARTBEAT_MAX_DELTA
        ) {
            return self::HEARTBEAT_INTERVAL;
        }

        return min(
            $delta,
            self::HEARTBEAT_MAX_DELTA
        );
    }

    private function calculateBoundedActivityDelta(
        string $from,
        string $to
    ): int {
        $delta =
            $this->calculateActivityDelta(
                $from,
                $to
            );

        if (
            $delta <= 0
        ) {
            return 0;
        }

        if (
            $delta > self::HEARTBEAT_MAX_DELTA
        ) {
            return self::HEARTBEAT_INTERVAL;
        }

        return min(
            $delta,
            self::HEARTBEAT_MAX_DELTA
        );
    }

    private function calculateActivityDelta(
        string $from,
        string $to
    ): int {
        $fromDate =
            $this->createDateTime(
                $from
            );

        $toDate =
            $this->createDateTime(
                $to
            );

        $delta =
            $toDate->getTimestamp()
            - $fromDate->getTimestamp();

        if (
            $delta <= 0
        ) {
            return 0;
        }

        return $delta;
    }

    private function applyActivityDelta(
        string $visitorId,
        string $sessionId,
        ?string $pageviewId,
        int $delta
    ): void {
        if (
            $delta <= 0
        ) {
            return;
        }

        if (
            $pageviewId !== null
        ) {
            if (
                !$this->pageviewRepository->addActiveSeconds(
                    $pageviewId,
                    $delta
                )
            ) {
                throw new \RuntimeException(
                    sprintf(
                        'Unable to update pageview activity: %s',
                        $pageviewId
                    )
                );
            }
        }

        if (
            !$this->sessionRepository->addActiveSeconds(
                $sessionId,
                $delta
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to update session activity: %s',
                    $sessionId
                )
            );
        }

        if (
            !$this->visitorRepository->addActiveSeconds(
                $visitorId,
                $delta
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to update visitor activity: %s',
                    $visitorId
                )
            );
        }
    }

    private function assertVisitorExists(
        string $visitorId
    ): void {
        if (
            !$this->visitorRepository->existsById(
                $visitorId
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor does not exist: %s',
                    $visitorId
                )
            );
        }
    }

    private function assertSessionOwnership(
        string $sessionId,
        string $visitorId
    ): void {
        $session =
            $this->sessionRepository->findById(
                $sessionId
            );

        if (
            $session === null
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Session does not exist: %s',
                    $sessionId
                )
            );
        }

        if (
            (string) (
                $session['visitor_id']
                ?? ''
            ) !== $visitorId
        ) {
            throw new \InvalidArgumentException(
                'Session does not belong to the specified visitor.'
            );
        }
    }

    private function assertPageviewOwnership(
        string $pageviewId,
        string $visitorId,
        string $sessionId
    ): void {
        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        $pageview =
            $this->pageviewRepository->findById(
                $pageviewId
            );

        if (
            $pageview === null
        ) {
            throw new \InvalidArgumentException(
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
            throw new \InvalidArgumentException(
                'Pageview does not belong to the specified visitor and session.'
            );
        }
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $payload
     */
    private function assertExistingEventIdentity(
        array $event,
        string $visitorId,
        string $sessionId,
        string $eventType,
        ?string $pageviewId,
        string $schemaVersion,
        string $occurredAt,
        array $payload
    ): void {
        if (
            (string) (
                $event['visitor_id']
                ?? ''
            ) !== $visitorId
            || (string) (
                $event['session_id']
                ?? ''
            ) !== $sessionId
            || (string) (
                $event['event_type']
                ?? ''
            ) !== $eventType
        ) {
            throw new \RuntimeException(
                'Event ID is already assigned to another visitor, session, or event type.'
            );
        }

        $storedPageviewId =
            array_key_exists(
                'pageview_id',
                $event
            )
                ? (
                    $event['pageview_id'] !== null
                        ? (string) $event['pageview_id']
                        : null
                )
                : null;

        if (
            $storedPageviewId !==
            $pageviewId
        ) {
            throw new \RuntimeException(
                'Event ID is already assigned to another pageview.'
            );
        }

        $storedSchemaVersion =
            trim(
                (string) (
                    $event['schema_version']
                    ?? ''
                )
            );

        if (
            $storedSchemaVersion !==
            $schemaVersion
        ) {
            throw new \RuntimeException(
                'Event ID is already assigned to another schema version.'
            );
        }

        $storedOccurredAt =
            trim(
                (string) (
                    $event['occurred_at']
                    ?? ''
                )
            );

        if (
            $storedOccurredAt !==
            $occurredAt
        ) {
            throw new \RuntimeException(
                'Event ID is already assigned to another occurrence timestamp.'
            );
        }

        $storedPayload =
            $this->decodeStoredPayload(
                $event['payload']
                ?? null
            );

        if (
            $this->canonicalizePayload(
                $storedPayload
            )
            !==
            $this->canonicalizePayload(
                $payload
            )
        ) {
            throw new \RuntimeException(
                'Event ID is already assigned to another payload.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStoredPayload(
        mixed $payload
    ): array {
        if (
            $payload === null
            || $payload === ''
        ) {
            return [];
        }

        if (
            is_array($payload)
        ) {
            return $payload;
        }

        if (
            !is_string($payload)
        ) {
            throw new \RuntimeException(
                'Stored event payload has an invalid type.'
            );
        }

        try {
            $decoded =
                json_decode(
                    $payload,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
        } catch (
            \JsonException $exception
        ) {
            throw new \RuntimeException(
                'Stored event payload contains invalid JSON.',
                0,
                $exception
            );
        }

        if (
            !is_array($decoded)
        ) {
            throw new \RuntimeException(
                'Stored event payload must decode to an object.'
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function canonicalizePayload(
        array $payload
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
            !is_string($encoded)
        ) {
            throw new \RuntimeException(
                'Event payload encoding failed.'
            );
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getActiveState(
        string $sessionId
    ): ?array {
        $state =
            get_transient(
                $this->activeStateKey(
                    $sessionId
                )
            );

        if (
            !is_array($state)
        ) {
            return null;
        }

        if (
            !isset(
                $state['last_signal_at']
            )
            || !is_string(
                $state['last_signal_at']
            )
            || trim(
                $state['last_signal_at']
            ) === ''
        ) {
            return null;
        }

        if (
            !isset(
                $state['started_at']
            )
            || !is_string(
                $state['started_at']
            )
            || trim(
                $state['started_at']
            ) === ''
        ) {
            return null;
        }

        $lastSignalAt =
            trim(
                $state['last_signal_at']
            );

        $startedAt =
            trim(
                $state['started_at']
            );

        $this->createDateTime(
            $lastSignalAt
        );

        $this->createDateTime(
            $startedAt
        );

        if (
            $this->compareDateTimes(
                $startedAt,
                $lastSignalAt
            ) > 0
        ) {
            return null;
        }

        $pageviewId = null;

        if (
            array_key_exists(
                'pageview_id',
                $state
            )
            && $state['pageview_id'] !== null
        ) {
            if (
                !is_string(
                    $state['pageview_id']
                )
            ) {
                return null;
            }

            $pageviewId =
                trim(
                    $state['pageview_id']
                );

            if (
                $pageviewId === ''
            ) {
                $pageviewId = null;
            } else {
                $this->assertUuid(
                    $pageviewId,
                    'Active pageview ID'
                );
            }
        }

        return [
            'last_signal_at' =>
                $lastSignalAt,

            'started_at' =>
                $startedAt,

            'pageview_id' =>
                $pageviewId,
        ];
    }

    private function storeActiveState(
        string $sessionId,
        ?string $pageviewId,
        string $occurredAt
    ): void {
        $this->createDateTime(
            $occurredAt
        );

        if (
            $pageviewId !== null
        ) {
            $this->assertUuid(
                $pageviewId,
                'Active pageview ID'
            );
        }

        $existing =
            $this->getActiveState(
                $sessionId
            );

        $startedAt =
            $existing !== null
                ? $existing['started_at']
                : $occurredAt;

        if (
            $existing !== null
            && $this->compareDateTimes(
                $existing['last_signal_at'],
                $occurredAt
            ) > 0
        ) {
            throw new \InvalidArgumentException(
                'Active state timestamp cannot move backwards.'
            );
        }

        $result =
            set_transient(
                $this->activeStateKey(
                    $sessionId
                ),
                [
                    'last_signal_at' =>
                        $occurredAt,

                    'started_at' =>
                        $startedAt,

                    'pageview_id' =>
                        $pageviewId,
                ],
                self::ACTIVE_STATE_TTL
            );

        if (
            $result === false
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to persist active state for session: %s',
                    $sessionId
                )
            );
        }
    }

    private function clearActiveState(
        string $sessionId
    ): void {
        delete_transient(
            $this->activeStateKey(
                $sessionId
            )
        );
    }

    private function activeStateKey(
        string $sessionId
    ): string {
        return 'vi_active_' . md5(
            $sessionId
        );
    }

    private function parseOccurredAt(
        mixed $raw
    ): string {
        if (
            !is_string($raw)
        ) {
            throw new \InvalidArgumentException(
                'Event occurred_at is required.'
            );
        }

        $value =
            trim(
                $raw
            );

        if (
            $value === ''
        ) {
            throw new \InvalidArgumentException(
                'Event occurred_at is required.'
            );
        }

        $date =
            $this->createDateTime(
                $value
            );

        return $date->format(
            'Y-m-d H:i:s'
        );
    }

    private function normalizePageviewId(
        mixed $value
    ): ?string {
        if (
            $value === null
        ) {
            return null;
        }

        if (
            !is_string($value)
        ) {
            throw new \InvalidArgumentException(
                'Pageview ID must be a string or null.'
            );
        }

        $pageviewId =
            trim(
                $value
            );

        if (
            $pageviewId === ''
        ) {
            return null;
        }

        $this->assertUuid(
            $pageviewId,
            'Pageview ID'
        );

        return $pageviewId;
    }

    private function normalizeSchemaVersion(
        mixed $value
    ): string {
        if (
            !is_string($value)
        ) {
            throw new \InvalidArgumentException(
                'Schema version must be a string.'
            );
        }

        $value =
            trim(
                $value
            );

        if (
            $value === ''
        ) {
            throw new \InvalidArgumentException(
                'Schema version cannot be empty.'
            );
        }

        if (
            strlen($value)
            > self::SCHEMA_VERSION_MAX_LENGTH
        ) {
            throw new \InvalidArgumentException(
                'Schema version is too long.'
            );
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeEventPayload(
        mixed $value
    ): array {
        if (
            $value === null
        ) {
            return [];
        }

        if (
            !is_array($value)
        ) {
            throw new \InvalidArgumentException(
                'Event payload must be an object.'
            );
        }

        return $value;
    }

    private function validateStateTimestamp(
        string $previous,
        string $current
    ): void {
        if (
            $this->compareDateTimes(
                $current,
                $previous
            ) < 0
        ) {
            throw new \InvalidArgumentException(
                'Event timestamp cannot move active state backwards.'
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

        return $date;
    }

    private function assertUuid(
        string $value,
        string $label
    ): void {
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                trim($value)
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
}