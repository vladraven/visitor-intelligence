<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core\Contracts;

defined('ABSPATH') || exit;

interface VisitorRepositoryInterface extends RepositoryInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(
        string $visitorId
    ): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function persist(
        array $data
    ): string;

    public function create(
        string $visitorId,
        array $data = []
    ): int;

    /**
     * @param array<string, mixed> $data
     */
    public function findOrCreate(
        string $visitorId,
        array $data = []
    ): int;

    public function touch(
        string $visitorId,
        ?string $timestamp = null
    ): bool;

    public function incrementSessions(
        string $visitorId,
        int $amount = 1
    ): bool;

    public function incrementPageviews(
        string $visitorId,
        int $amount = 1
    ): bool;

    public function addActiveSeconds(
        string $visitorId,
        int $seconds
    ): bool;

    /**
     * @param array<string, mixed> $geo
     */
    public function updateGeo(
        string $visitorId,
        array $geo
    ): bool;

    /**
     * @param array<string, mixed> $device
     */
    public function updateDevice(
        string $visitorId,
        array $device
    ): bool;

    /**
     * @param array<string, mixed> $bot
     */
    public function updateBot(
        string $visitorId,
        array $bot
    ): bool;

    public function updateSeenRange(
        string $visitorId,
        string $firstSeen,
        string $lastSeen
    ): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findRecentlyActive(
        int $limit = 100
    ): array;

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function findFiltered(
        array $filters = [],
        string $sort = 'last_seen',
        string $direction = 'DESC',
        int $page = 1,
        int $perPage = 50
    ): array;

    /**
     * @param array<string, mixed> $filters
     */
    public function countFiltered(
        array $filters = []
    ): int;

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     total_pages: int
     * }
     */
    public function paginate(
        array $filters = [],
        string $sort = 'last_seen',
        string $direction = 'DESC',
        int $page = 1,
        int $perPage = 50
    ): array;

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     countries: array<int, array<string, mixed>>,
     *     regions: array<int, array<string, mixed>>,
     *     cities: array<int, string>,
     *     device_types: array<int, string>,
     *     browsers: array<int, string>,
     *     operating_systems: array<int, string>,
     *     bot_classifications: array<int, string>
     * }
     */
    public function getFilterOptions(
        array $filters = []
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function getSummary(
        string $visitorId
    ): ?array;
}