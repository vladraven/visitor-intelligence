<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core\Contracts;

defined('ABSPATH') || exit;

interface AggregatorInterface
{
    /**
     * Aggregate canonical telemetry for a single calendar date.
     *
     * @param string $dateKey UTC date in Y-m-d format.
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    public function aggregate(
        string $dateKey,
        array $telemetry
    ): array;

    /**
     * Determine whether the aggregator supports a dimension.
     */
    public function supports(string $dimensionType): bool;

    /**
     * Return all supported daily-stat dimensions.
     *
     * @return string[]
     */
    public function getSupportedDimensions(): array;

    /**
     * Return the canonical daily-stat dimension value.
     *
     * @param array<string, mixed> $telemetry
     */
    public function getDimensionValue(
        string $dimensionType,
        array $telemetry
    ): string;
}