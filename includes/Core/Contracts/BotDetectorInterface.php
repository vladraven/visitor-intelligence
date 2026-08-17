<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core\Contracts;

defined('ABSPATH') || exit;

interface BotDetectorInterface
{
    /**
     * Analyze a request/session context and return the canonical
     * bot-detection result.
     *
     * @param array<string, mixed> $context
     * @return array{
     *     score: int,
     *     classification: string,
     *     signals: array<string, int>,
     *     js_detected: bool,
     *     analyzed_at: string
     * }
     */
    public function analyze(array $context): array;

    /**
     * Calculate the canonical bot score.
     *
     * Score range: 0-100.
     */
    public function getScore(array $context): int;

    /**
     * Convert a score and available evidence into the canonical
     * classification.
     *
     * Allowed classifications:
     * - human
     * - suspicious
     * - bot
     * - unknown
     */
    public function getClassification(array $context): string;

    /**
     * Return the individual scoring signals used by the detector.
     *
     * @param array<string, mixed> $context
     * @return array<string, int>
     */
    public function getSignals(array $context): array;
}