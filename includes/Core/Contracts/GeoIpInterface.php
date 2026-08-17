<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core\Contracts;

defined('ABSPATH') || exit;

interface GeoIpInterface
{
    /**
     * Resolve geographic information for an IP address.
     *
     * Implementations must never throw for malformed, private,
     * local, unresolved, or otherwise unusable IP addresses.
     *
     * @return array{
     *     country_code: ?string,
     *     country_name: ?string,
     *     region_code: ?string,
     *     region_name: ?string,
     *     city: ?string,
     *     latitude: ?float,
     *     longitude: ?float,
     *     source: ?string,
     *     database_version: ?string
     * }
     */
    public function lookup(string $ip): array;

    /**
     * Determine whether the configured GeoIP database is available.
     */
    public function isAvailable(): bool;

    /**
     * Return the currently loaded database version.
     */
    public function getVersion(): ?string;

    /**
     * Return the provider identifier.
     */
    public function getProvider(): string;
}