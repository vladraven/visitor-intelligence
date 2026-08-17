<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core\Contracts;

defined('ABSPATH') || exit;

interface CollectorInterface
{
    /**
     * Collect telemetry from an external source.
     *
     * The collector must validate and normalize the input,
     * but must not perform final persistence decisions.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function collect(array $payload): array;

    /**
     * Determine whether this collector can process the given source.
     */
    public function supports(string $source): bool;

    /**
     * Return the canonical collector source identifier.
     *
     * Examples:
     * - server
     * - browser
     */
    public function getSource(): string;
}