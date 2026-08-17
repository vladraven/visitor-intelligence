<?php

declare(strict_types=1);

namespace VisitorIntelligence\GeoIP;

use MaxMind\Db\Reader;
use VisitorIntelligence\Core\Config;
use VisitorIntelligence\Core\Contracts\GeoIpInterface;

defined('ABSPATH') || exit;

final class GeoIpManager implements GeoIpInterface
{
    private const PROVIDER = 'dbip_city_lite';

    private const DEFAULT_RELATIVE_PATH = 'data/geoip/dbip-city-lite.mmdb';

    private ?Reader $reader = null;

    private bool $attemptedOpen = false;

    private ?string $version = null;

    public function lookup(string $ip): array
    {
        $empty = [
            'country_code' => null,
            'country_name' => null,
            'region_code' => null,
            'region_name' => null,
            'city' => null,
            'latitude' => null,
            'longitude' => null,
            'source' => null,
            'database_version' => null,
        ];

        $ip = trim($ip);

        if (
            $ip === ''
            || filter_var($ip, FILTER_VALIDATE_IP) === false
        ) {
            return $empty;
        }

        if (!$this->isPublicIp($ip)) {
            return $empty;
        }

        if (!(bool) Config::get('geoip_enabled', true)) {
            return $empty;
        }

        $reader = $this->getReader();

        if ($reader === null) {
            return $empty;
        }

        try {
            /** @var mixed $record */
            $record = $reader->get($ip);
        } catch (\Throwable $exception) {
            do_action('vi_geoip_error', $exception, $ip);

            return $empty;
        }

        if (!is_array($record)) {
            return $empty;
        }

        return [
            'country_code' => $this->extractCountryCode($record),
            'country_name' => $this->extractCountryName($record),
            'region_code' => $this->extractRegionCode($record),
            'region_name' => $this->extractRegionName($record),
            'city' => $this->extractCity($record),
            'latitude' => $this->extractLatitude($record),
            'longitude' => $this->extractLongitude($record),
            'source' => self::PROVIDER,
            'database_version' => $this->getVersion(),
        ];
    }

    public function isAvailable(): bool
    {
        return $this->getReader() !== null;
    }

    public function getVersion(): ?string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $reader = $this->getReader();

        if ($reader === null) {
            return null;
        }

        try {
            $epoch = $reader->metadata()->buildEpoch;
        } catch (\Throwable $exception) {
            return null;
        }

        if (!is_int($epoch) && !is_float($epoch) && !is_numeric($epoch)) {
            return null;
        }

        $epoch = (int) $epoch;

        if ($epoch <= 0) {
            return null;
        }

        $this->version = gmdate('Y-m-d', $epoch);

        return $this->version;
    }

    public function getProvider(): string
    {
        return self::PROVIDER;
    }

    public function getDatabasePath(): string
    {
        if (
            defined('VI_GEOIP_DB_PATH')
            && is_string(VI_GEOIP_DB_PATH)
            && VI_GEOIP_DB_PATH !== ''
        ) {
            return VI_GEOIP_DB_PATH;
        }

        return VI_DIR . self::DEFAULT_RELATIVE_PATH;
    }

    private function getReader(): ?Reader
    {
        if ($this->reader !== null) {
            return $this->reader;
        }

        if ($this->attemptedOpen) {
            return null;
        }

        $this->attemptedOpen = true;

        $path = $this->getDatabasePath();

        if (!is_readable($path)) {
            return null;
        }

        try {
            $reader = new Reader($path);

            $metadata = $reader->metadata();

            if (
                !is_string($metadata->databaseType)
                || $metadata->databaseType === ''
            ) {
                $reader->close();

                return null;
            }

            $this->reader = $reader;
        } catch (\Throwable $exception) {
            do_action('vi_geoip_error', $exception, $path);

            $this->reader = null;
        }

        return $this->reader;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractCountryCode(array $record): ?string
    {
        $country = $record['country'] ?? null;

        if (!is_array($country)) {
            return null;
        }

        $isoCode = $country['iso_code'] ?? null;

        if (!is_string($isoCode)) {
            return null;
        }

        $isoCode = strtoupper(trim($isoCode));

        if ($isoCode === '') {
            return null;
        }

        return $isoCode;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractCountryName(array $record): ?string
    {
        $country = $record['country'] ?? null;

        if (!is_array($country)) {
            return null;
        }

        return $this->extractLocalizedName($country);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractRegionCode(array $record): ?string
    {
        $subdivisions = $record['subdivisions'] ?? null;

        if (!is_array($subdivisions) || $subdivisions === []) {
            return null;
        }

        $first = $subdivisions[0] ?? null;

        if (!is_array($first)) {
            return null;
        }

        $isoCode = $first['iso_code'] ?? null;

        if (!is_string($isoCode)) {
            return null;
        }

        $isoCode = strtoupper(trim($isoCode));

        if ($isoCode === '') {
            return null;
        }

        return $isoCode;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractRegionName(array $record): ?string
    {
        $subdivisions = $record['subdivisions'] ?? null;

        if (!is_array($subdivisions) || $subdivisions === []) {
            return null;
        }

        $first = $subdivisions[0] ?? null;

        if (!is_array($first)) {
            return null;
        }

        return $this->extractLocalizedName($first);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractCity(array $record): ?string
    {
        $city = $record['city'] ?? null;

        if (!is_array($city)) {
            return null;
        }

        return $this->extractLocalizedName($city);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractLatitude(array $record): ?float
    {
        $location = $record['location'] ?? null;

        if (!is_array($location)) {
            return null;
        }

        $latitude = $location['latitude'] ?? null;

        if (!is_int($latitude) && !is_float($latitude) && !is_numeric($latitude)) {
            return null;
        }

        $latitude = (float) $latitude;

        if ($latitude < -90.0 || $latitude > 90.0) {
            return null;
        }

        return $latitude;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractLongitude(array $record): ?float
    {
        $location = $record['location'] ?? null;

        if (!is_array($location)) {
            return null;
        }

        $longitude = $location['longitude'] ?? null;

        if (!is_int($longitude) && !is_float($longitude) && !is_numeric($longitude)) {
            return null;
        }

        $longitude = (float) $longitude;

        if ($longitude < -180.0 || $longitude > 180.0) {
            return null;
        }

        return $longitude;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractLocalizedName(array $record): ?string
    {
        $names = $record['names'] ?? null;

        if (!is_array($names)) {
            return null;
        }

        $name = $names['en'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            foreach ($names as $candidate) {
                if (
                    is_string($candidate)
                    && trim($candidate) !== ''
                ) {
                    $name = $candidate;

                    break;
                }
            }
        }

        if (!is_string($name)) {
            return null;
        }

        $name = trim($name);

        if ($name === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($name, 0, 128);
        }

        return substr($name, 0, 128);
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}