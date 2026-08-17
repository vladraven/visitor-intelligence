<?php

declare(strict_types=1);

namespace VisitorIntelligence\Collection;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

final class CollectController
{
    private const MAX_EVENTS = 50;

    private const MAX_PAYLOAD_BYTES = 65536;

    private const TRACKING_MODES = [
        'full',
        'server_only',
    ];

    private const MAX_EVENT_TYPE_LENGTH = 64;

    private const MAX_SCHEMA_VERSION_LENGTH = 8;

    private const MAX_EVENT_PAYLOAD_BYTES = 32768;

    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly EventPipeline $pipeline
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'vi/v1',
            '/collect',
            [
                'methods' =>
                    'POST',

                'callback' =>
                    [$this, 'collect'],

                'permission_callback' =>
                    '__return_true',

                'args' => [],
            ]
        );
    }

    public function collect(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        if (
            !$this->rateLimiter->check(
                $request
            )
        ) {
            $retryAfter =
                $this->rateLimiter->retryAfter(
                    $request
                );

            if (
                $retryAfter < 1
            ) {
                $retryAfter = 60;
            }

            return $this->rateLimitError(
                $retryAfter
            );
        }

        $params =
            $request->get_json_params();

        $validationError =
            $this->validatePayload(
                $params
            );

        if (
            $validationError !== null
        ) {
            return $validationError;
        }

        try {
            $result =
                $this->pipeline->processBatch(
                    $params
                );
        } catch (
            \InvalidArgumentException $exception
        ) {
            return new WP_Error(
                'vi_invalid_payload',
                $exception->getMessage(),
                [
                    'status' => 400,
                ]
            );
        } catch (
            \Throwable $exception
        ) {
            do_action(
                'vi_collect_error',
                $exception,
                $params
            );

            return new WP_Error(
                'vi_collect_failed',
                'Unable to process telemetry payload.',
                [
                    'status' => 500,
                ]
            );
        }

        if (
            !is_array($result)
            || !array_key_exists(
                'processed',
                $result
            )
            || !array_key_exists(
                'failed',
                $result
            )
        ) {
            $exception =
                new \RuntimeException(
                    'EventPipeline returned an invalid result.'
                );

            do_action(
                'vi_collect_error',
                $exception,
                $params
            );

            return new WP_Error(
                'vi_collect_failed',
                'Unable to process telemetry payload.',
                [
                    'status' => 500,
                ]
            );
        }

        $processed =
            max(
                0,
                (int) $result['processed']
            );

        $failed =
            max(
                0,
                (int) $result['failed']
            );

        return new WP_REST_Response(
            [
                'status' =>
                    $failed > 0
                        ? 'partial'
                        : 'success',

                'processed' =>
                    $processed,

                'failed' =>
                    $failed,

                'server_time' =>
                    gmdate(
                        'Y-m-d H:i:s'
                    ),
            ],
            $failed > 0
                ? 207
                : 200
        );
    }

    private function validatePayload(
        mixed $params
    ): ?WP_Error {
        if (
            !is_array($params)
        ) {
            return $this->invalidPayload(
                'Request body must contain a JSON object.'
            );
        }

        if (
            !$this->hasNonEmptyString(
                $params,
                'visitor_id'
            )
        ) {
            return $this->invalidPayload(
                'visitor_id is required.'
            );
        }

        if (
            !$this->isUuid(
                (string) $params['visitor_id']
            )
        ) {
            return $this->invalidPayload(
                'visitor_id must be a valid UUID.'
            );
        }

        if (
            !$this->hasNonEmptyString(
                $params,
                'session_id'
            )
        ) {
            return $this->invalidPayload(
                'session_id is required.'
            );
        }

        if (
            !$this->isUuid(
                (string) $params['session_id']
            )
        ) {
            return $this->invalidPayload(
                'session_id must be a valid UUID.'
            );
        }

        if (
            !array_key_exists(
                'events',
                $params
            )
            || !is_array(
                $params['events']
            )
        ) {
            return $this->invalidPayload(
                'events must be an array.'
            );
        }

        $events =
            $params['events'];

        if (
            $events === []
        ) {
            return $this->invalidPayload(
                'events must contain at least one event.'
            );
        }

        if (
            count($events)
            > self::MAX_EVENTS
        ) {
            return $this->invalidPayload(
                sprintf(
                    'events cannot contain more than %d items.',
                    self::MAX_EVENTS
                )
            );
        }

        if (
            array_key_exists(
                'tracking_mode',
                $params
            )
        ) {
            $trackingMode =
                $params['tracking_mode'];

            if (
                !is_string(
                    $trackingMode
                )
                || !in_array(
                    $trackingMode,
                    self::TRACKING_MODES,
                    true
                )
            ) {
                return $this->invalidPayload(
                    'tracking_mode must be full or server_only.'
                );
            }
        }

        $json =
            wp_json_encode(
                $params,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );

        if (
            $json === false
        ) {
            return $this->invalidPayload(
                'Unable to encode request payload.'
            );
        }

        if (
            strlen($json)
            > self::MAX_PAYLOAD_BYTES
        ) {
            return $this->invalidPayload(
                sprintf(
                    'Request payload cannot exceed %d bytes.',
                    self::MAX_PAYLOAD_BYTES
                )
            );
        }

        foreach (
            $events as $index => $event
        ) {
            $error =
                $this->validateEvent(
                    $event,
                    (int) $index
                );

            if (
                $error !== null
            ) {
                return $error;
            }
        }

        return null;
    }

    private function validateEvent(
        mixed $event,
        int $index
    ): ?WP_Error {
        if (
            !is_array($event)
        ) {
            return $this->invalidPayload(
                sprintf(
                    'events[%d] must be an object.',
                    $index
                )
            );
        }

        if (
            !$this->hasNonEmptyString(
                $event,
                'event_id'
            )
        ) {
            return $this->invalidPayload(
                sprintf(
                    'events[%d].event_id is required.',
                    $index
                )
            );
        }

        if (
            !$this->isUuid(
                (string) $event['event_id']
            )
        ) {
            return $this->invalidPayload(
                sprintf(
                    'events[%d].event_id must be a valid UUID.',
                    $index
                )
            );
        }

        if (
            !$this->hasNonEmptyString(
                $event,
                'event_type'
            )
        ) {
            return $this->invalidPayload(
                sprintf(
                    'events[%d].event_type is required.',
                    $index
                )
            );
        }

        $eventType =
            trim(
                (string) $event['event_type']
            );

        if (
            strlen($eventType)
            > self::MAX_EVENT_TYPE_LENGTH
        ) {
            return $this->invalidPayload(
                sprintf(
                    'events[%d].event_type cannot exceed %d characters.',
                    $index,
                    self::MAX_EVENT_TYPE_LENGTH
                )
            );
        }

        if (
            !$this->hasNonEmptyString(
                $event,
                'occurred_at'
            )
        ) {
            return $this->invalidPayload(
                sprintf(
                    'events[%d].occurred_at is required.',
                    $index
                )
            );
        }

        $occurredAt =
            trim(
                (string) $event['occurred_at']
            );

        if (
            !$this->isUtcDateTime(
                $occurredAt
            )
        ) {
            return $this->invalidPayload(
                sprintf(
                    'events[%d].occurred_at must use UTC Y-m-d H:i:s format.',
                    $index
                )
            );
        }

        if (
            array_key_exists(
                'pageview_id',
                $event
            )
            && $event['pageview_id'] !== null
        ) {
            if (
                !is_string(
                    $event['pageview_id']
                )
                || !$this->isUuid(
                    $event['pageview_id']
                )
            ) {
                return $this->invalidPayload(
                    sprintf(
                        'events[%d].pageview_id must be a valid UUID or null.',
                        $index
                    )
                );
            }
        }

        if (
            array_key_exists(
                'schema_version',
                $event
            )
        ) {
            if (
                !is_string(
                    $event['schema_version']
                )
            ) {
                return $this->invalidPayload(
                    sprintf(
                        'events[%d].schema_version must be a string.',
                        $index
                    )
                );
            }

            $schemaVersion =
                trim(
                    $event['schema_version']
                );

            if (
                strlen($schemaVersion)
                > self::MAX_SCHEMA_VERSION_LENGTH
                || preg_match(
                    '/^v[0-9]+$/',
                    $schemaVersion
                ) !== 1
            ) {
                return $this->invalidPayload(
                    sprintf(
                        'events[%d].schema_version is invalid.',
                        $index
                    )
                );
            }
        }

        if (
            array_key_exists(
                'payload',
                $event
            )
        ) {
            $payload =
                $event['payload'];

            if (
                $payload !== null
                && !is_array($payload)
            ) {
                return $this->invalidPayload(
                    sprintf(
                        'events[%d].payload must be an object or null.',
                        $index
                    )
                );
            }

            if (
                is_array($payload)
            ) {
                $payloadJson =
                    wp_json_encode(
                        $payload,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    );

                if (
                    $payloadJson === false
                    || strlen($payloadJson)
                        > self::MAX_EVENT_PAYLOAD_BYTES
                ) {
                    return $this->invalidPayload(
                        sprintf(
                            'events[%d].payload is too large.',
                            $index
                        )
                    );
                }
            }
        }

        return null;
    }

    private function hasNonEmptyString(
        array $data,
        string $key
    ): bool {
        return array_key_exists(
            $key,
            $data
        )
        && is_string(
            $data[$key]
        )
        && trim(
            $data[$key]
        ) !== '';
    }

    private function isUuid(
        string $value
    ): bool {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            trim($value)
        ) === 1;
    }

    private function isUtcDateTime(
        string $value
    ): bool {
        $value =
            trim(
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
        ) {
            return false;
        }

        $errors =
            \DateTimeImmutable::getLastErrors();

        if (
            is_array($errors)
            && (
                $errors['warning_count'] > 0
                || $errors['error_count'] > 0
            )
        ) {
            return false;
        }

        return $date->format(
            'Y-m-d H:i:s'
        ) === $value;
    }

    private function invalidPayload(
        string $message
    ): WP_Error {
        return new WP_Error(
            'vi_invalid_payload',
            $message,
            [
                'status' => 400,
            ]
        );
    }

    private function rateLimitError(
        int $retryAfter
    ): WP_Error {
        $error =
            new WP_Error(
                'vi_rate_limit_exceeded',
                'Too many requests. Limit exceeded.',
                [
                    'status' =>
                        429,

                    'retry_after' =>
                        $retryAfter,
                ]
            );

        $error->add_data(
            [
                'status' =>
                    429,

                'retry_after' =>
                    $retryAfter,

                'headers' => [
                    'Retry-After' =>
                        (string) $retryAfter,
                ],
            ],
            'vi_rate_limit_exceeded'
        );

        return $error;
    }
}