<?php

declare(strict_types=1);

namespace VisitorIntelligence\Sessions;

use VisitorIntelligence\Database\Repositories\SessionRepository;

defined('ABSPATH') || exit;

final class SessionManager
{
    public function __construct(
        private readonly SessionRepository $repository,
        private readonly Sessionizer $sessionizer
    ) {
    }

    public function resolveSessionId(
        string $visitorId
    ): string {
        $visitorId = trim(
            $visitorId
        );

        $this->assertUuid(
            $visitorId,
            'Visitor ID'
        );

        $existingSessionId =
            $this->sessionizer->getSessionId();

        if ($existingSessionId !== null) {
            $existingSession =
                $this->repository->findById(
                    $existingSessionId
                );

            if (
                $existingSession !== null
                && $this->belongsToVisitor(
                    $existingSession,
                    $visitorId
                )
                && !$this->isEnded(
                    $existingSession
                )
            ) {
                $this->sessionizer->refresh(
                    $existingSessionId
                );

                return $existingSessionId;
            }
        }

        return $this->sessionizer->issueSessionId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function ensureSession(
        string $sessionId,
        string $visitorId,
        array $data = []
    ): void {
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

        $existing =
            $this->repository->findById(
                $sessionId
            );

        if ($existing !== null) {
            if (
                !$this->belongsToVisitor(
                    $existing,
                    $visitorId
                )
            ) {
                throw new \RuntimeException(
                    'Session belongs to another visitor.'
                );
            }

            if (
                $this->isEnded(
                    $existing
                )
            ) {
                throw new \RuntimeException(
                    'Cannot continue an ended session.'
                );
            }
        }

        $payload = $data;

        $payload['session_id'] =
            $sessionId;

        $payload['visitor_id'] =
            $visitorId;

        $this->repository->persist(
            $payload
        );

        if (
            !$this->repository->touch(
                $sessionId
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to touch session: %s',
                    $sessionId
                )
            );
        }

        $this->sessionizer->refresh(
            $sessionId
        );
    }

    /**
     * @param array<string, mixed> $session
     */
    private function belongsToVisitor(
        array $session,
        string $visitorId
    ): bool {
        return isset(
            $session['visitor_id']
        )
        && hash_equals(
            (string) $session['visitor_id'],
            $visitorId
        );
    }

    /**
     * @param array<string, mixed> $session
     */
    private function isEnded(
        array $session
    ): bool {
        return isset(
            $session['ended_at']
        )
        && $session['ended_at'] !== null
        && trim(
            (string) $session['ended_at']
        ) !== '';
    }

    private function assertUuid(
        string $value,
        string $label
    ): void {
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
}