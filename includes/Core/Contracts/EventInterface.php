<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core\Contracts;

defined('ABSPATH') || exit;

interface EventInterface
{
    /**
     * Return the canonical event type.
     */
    public function getType(): string;

    /**
     * Return the event payload.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array;

    /**
     * Return the event creation timestamp in UTC.
     */
    public function getOccurredAt(): string;
}