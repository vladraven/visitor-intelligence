<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core\Contracts;

defined('ABSPATH') || exit;

interface SourceDetectorInterface
{
    /**
     * Detect the canonical traffic source from request context.
     *
     * @param array<string, mixed> $context
     * @return array{
     *     source_type: string,
     *     source_name: ?string,
     *     source_domain: ?string,
     *     referrer_url: ?string,
     *     confidence: string,
     *     signals: array<string, mixed>
     * }
     */
    public function detect(array $context): array;

    /**
     * Determine whether this detector can process the supplied context.
     *
     * @param array<string, mixed> $context
     */
    public function supports(array $context): bool;

    /**
     * Return the canonical source type produced by this detector.
     *
     * Examples:
     * - paid
     * - organic
     * - social
     * - email
     * - referral
     * - direct
     * - unknown
     */
    public function getSourceType(): string;

    /**
     * Return the detector priority.
     *
     * Lower number means higher priority.
     */
    public function getPriority(): int;
}