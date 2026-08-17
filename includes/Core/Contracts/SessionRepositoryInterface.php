<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core\Contracts;

defined('ABSPATH') || exit;

interface SessionRepositoryInterface extends RepositoryInterface
{
    public function findById(string $sessionId): ?array;

    public function persist(array $data): string;

    public function touch(
        string $sessionId,
        ?string $timestamp = null
    ): bool;

    public function updateDuration(
        string $sessionId,
        int $durationSeconds,
        string $timestamp
    ): bool;

    public function addActiveSeconds(
        string $sessionId,
        int $seconds
    ): bool;

    public function close(
        string $sessionId,
        string $endedAt,
        int $durationSeconds,
        int $activeSeconds
    ): bool;
}