<?php

declare(strict_types=1);

namespace VisitorIntelligence\Database\Repositories;

use VisitorIntelligence\Core\Contracts\VisitorRepositoryInterface;

defined('ABSPATH') || exit;

final class VisitorRepository extends AbstractRepository implements VisitorRepositoryInterface
{
    private const BOT_CLASSIFICATIONS = [
        'human',
        'suspicious',
        'bot',
        'unknown',
    ];

    private const MAX_LIST_LIMIT = 1000;

    private const DEFAULT_PAGE = 1;

    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 100;

    private const SORT_FIELDS = [
        'id' => 'id',
        'visitor_id' => 'visitor_id',
        'first_seen' => 'first_seen',
        'last_seen' => 'last_seen',
        'sessions_count' => 'sessions_count',
        'pageviews_count' => 'pageviews_count',
        'active_seconds' => 'active_seconds',
        'country' => 'country_name',
        'country_name' => 'country_name',
        'region' => 'region_name',
        'region_name' => 'region_name',
        'city' => 'city',
        'device_type' => 'device_type',
        'browser' => 'browser',
        'browser_version' => 'browser_version',
        'os' => 'os',
        'os_version' => 'os_version',
        'bot_score' => 'bot_score',
        'bot_classification' => 'bot_classification',
    ];

    private const LIST_COLUMNS = [
        'id',
        'visitor_id',
        'first_seen',
        'last_seen',
        'sessions_count',
        'pageviews_count',
        'active_seconds',
        'country_code',
        'region_code',
        'city',
        'country_name',
        'region_name',
        'device_type',
        'browser',
        'browser_version',
        'os',
        'os_version',
        'bot_score',
        'bot_classification',
    ];

    protected function table(): string
    {
        return $this->database->table('visitors');
    }

    protected function identifierColumn(): string
    {
        return 'visitor_id';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $visitorId): ?array
    {
        $visitorId = trim($visitorId);

        $this->assertVisitorId(
            $visitorId
        );

        return $this->findByIdentifier(
            $visitorId
        );
    }

    public function existsById(string $visitorId): bool
    {
        $visitorId = trim($visitorId);

        $this->assertVisitorId(
            $visitorId
        );

        return $this->existsByIdentifier(
            $visitorId
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function persist(array $data): string
    {
        $visitorId = isset($data['visitor_id'])
            ? trim((string) $data['visitor_id'])
            : '';

        $this->assertVisitorId(
            $visitorId
        );

        $existing = $this->findByIdentifier(
            $visitorId
        );

        if ($existing !== null) {
            $update = $this->filterUpdateData(
                $data
            );

            if ($update !== []) {
                $this->validateUpdateData(
                    $update,
                    $existing
                );

                $update['updated_at'] =
                    $this->nowUtc();

                $this->updateByIdentifier(
                    $visitorId,
                    $update,
                    $this->buildFormats(
                        $update
                    )
                );
            }

            return $visitorId;
        }

        $record = $this->buildRecord(
            $visitorId,
            $data
        );

        try {
            $this->insertRecord(
                $record,
                $this->buildFormats(
                    $record
                )
            );
        } catch (\RuntimeException $exception) {
            $existing = $this->findByIdentifier(
                $visitorId
            );

            if ($existing === null) {
                throw $exception;
            }

            return $visitorId;
        }

        return $visitorId;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(
        string $visitorId,
        array $data = []
    ): int {
        $visitorId = trim(
            $visitorId
        );

        $this->assertVisitorId(
            $visitorId
        );

        if ($this->existsById(
            $visitorId
        )) {
            throw new \RuntimeException(
                sprintf(
                    'Visitor already exists: %s',
                    $visitorId
                )
            );
        }

        $record = $this->buildRecord(
            $visitorId,
            $data
        );

        return $this->insertRecord(
            $record,
            $this->buildFormats(
                $record
            )
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function findOrCreate(
        string $visitorId,
        array $data = []
    ): int {
        $visitorId = trim(
            $visitorId
        );

        $this->assertVisitorId(
            $visitorId
        );

        $existing = $this->findByIdentifier(
            $visitorId
        );

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        try {
            return $this->create(
                $visitorId,
                $data
            );
        } catch (\RuntimeException $exception) {
            $existing = $this->findByIdentifier(
                $visitorId
            );

            if ($existing === null) {
                throw $exception;
            }

            return (int) $existing['id'];
        }
    }

    public function touch(
        string $visitorId,
        ?string $timestamp = null
    ): bool {
        $visitorId = trim(
            $visitorId
        );

        $this->assertVisitorId(
            $visitorId
        );

        $timestamp ??=
            $this->nowUtc();

        $this->validateDateTime(
            $timestamp
        );

        $existing = $this->findByIdentifier(
            $visitorId
        );

        if ($existing === null) {
            return false;
        }

        $firstSeen = (string) (
            $existing['first_seen']
            ?? ''
        );

        if (
            $this->compareDateTimes(
                $timestamp,
                $firstSeen
            ) < 0
        ) {
            throw new \InvalidArgumentException(
                'Visitor last_seen cannot precede first_seen.'
            );
        }

        $table = $this->table();

        $affected =
            $this->database->execute(
                "UPDATE {$table}
                 SET
                     last_seen = GREATEST(
                         last_seen,
                         %s
                     ),
                     updated_at = %s
                 WHERE visitor_id = %s",
                $timestamp,
                $this->nowUtc(),
                $visitorId
            );

        return $affected > 0;
    }

    public function incrementSessions(
        string $visitorId,
        int $amount = 1
    ): bool {
        return $this->incrementCounter(
            $visitorId,
            'sessions_count',
            $amount
        );
    }

    public function incrementPageviews(
        string $visitorId,
        int $amount = 1
    ): bool {
        return $this->incrementCounter(
            $visitorId,
            'pageviews_count',
            $amount
        );
    }

    public function addActiveSeconds(
        string $visitorId,
        int $seconds
    ): bool {
        if ($seconds < 0) {
            throw new \InvalidArgumentException(
                'Active seconds cannot be negative.'
            );
        }

        if ($seconds === 0) {
            return false;
        }

        return $this->incrementCounter(
            $visitorId,
            'active_seconds',
            $seconds
        );
    }

    /**
     * @param array{
     *     country_code?: ?string,
     *     region_code?: ?string,
     *     city?: ?string,
     *     country_name?: ?string,
     *     region_name?: ?string,
     *     latitude?: ?string,
     *     longitude?: ?string,
     *     geo_source?: ?string,
     *     geo_database_version?: ?string
     * } $geo
     */
    public function updateGeo(
        string $visitorId,
        array $geo
    ): bool {
        $visitorId = trim(
            $visitorId
        );

        $this->assertVisitorId(
            $visitorId
        );

        $data = [];

        if (array_key_exists(
            'country_code',
            $geo
        )) {
            $data['country_code'] =
                $this->normalizeNullableString(
                    $geo['country_code'],
                    2,
                    'country_code'
                );
        }

        if (array_key_exists(
            'region_code',
            $geo
        )) {
            $data['region_code'] =
                $this->normalizeNullableString(
                    $geo['region_code'],
                    16,
                    'region_code'
                );
        }

        if (array_key_exists(
            'city',
            $geo
        )) {
            $data['city'] =
                $this->normalizeNullableString(
                    $geo['city'],
                    128,
                    'city'
                );
        }

        if (array_key_exists(
            'country_name',
            $geo
        )) {
            $data['country_name'] =
                $this->normalizeNullableString(
                    $geo['country_name'],
                    128,
                    'country_name'
                );
        }

        if (array_key_exists(
            'region_name',
            $geo
        )) {
            $data['region_name'] =
                $this->normalizeNullableString(
                    $geo['region_name'],
                    128,
                    'region_name'
                );
        }

        if (array_key_exists(
            'latitude',
            $geo
        )) {
            $data['latitude'] =
                $this->normalizeCoordinate(
                    $geo['latitude'],
                    'latitude',
                    -90,
                    90
                );
        }

        if (array_key_exists(
            'longitude',
            $geo
        )) {
            $data['longitude'] =
                $this->normalizeCoordinate(
                    $geo['longitude'],
                    'longitude',
                    -180,
                    180
                );
        }

        if (array_key_exists(
            'geo_source',
            $geo
        )) {
            $data['geo_source'] =
                $this->normalizeNullableString(
                    $geo['geo_source'],
                    64,
                    'geo_source'
                );
        }

        if (array_key_exists(
            'geo_database_version',
            $geo
        )) {
            $data['geo_database_version'] =
                $this->normalizeNullableString(
                    $geo['geo_database_version'],
                    32,
                    'geo_database_version'
                );
        }

        if ($data === []) {
            return false;
        }

        $data['updated_at'] =
            $this->nowUtc();

        return $this->updateByIdentifier(
            $visitorId,
            $data,
            $this->buildFormats(
                $data
            )
        );
    }

    /**
     * @param array{
     *     device_type?: ?string,
     *     browser?: ?string,
     *     browser_version?: ?string,
     *     os?: ?string,
     *     os_version?: ?string
     * } $device
     */
    public function updateDevice(
        string $visitorId,
        array $device
    ): bool {
        $visitorId = trim(
            $visitorId
        );

        $this->assertVisitorId(
            $visitorId
        );

        $allowed = [
            'device_type' =>
                32,

            'browser' =>
                64,

            'browser_version' =>
                32,

            'os' =>
                64,

            'os_version' =>
                32,
        ];

        $data = [];

        foreach (
            $allowed as $field => $maxLength
        ) {
            if (
                array_key_exists(
                    $field,
                    $device
                )
            ) {
                $data[$field] =
                    $this->normalizeNullableString(
                        $device[$field],
                        $maxLength,
                        $field
                    );
            }
        }

        if ($data === []) {
            return false;
        }

        $data['updated_at'] =
            $this->nowUtc();

        return $this->updateByIdentifier(
            $visitorId,
            $data,
            $this->buildFormats(
                $data
            )
        );
    }

    /**
     * @param array{
     *     bot_score?: int,
     *     bot_classification?: string
     * } $bot
     */
    public function updateBot(
        string $visitorId,
        array $bot
    ): bool {
        $visitorId = trim(
            $visitorId
        );

        $this->assertVisitorId(
            $visitorId
        );

        $data = [];

        if (
            array_key_exists(
                'bot_score',
                $bot
            )
        ) {
            if (
                !is_int($bot['bot_score'])
                && !(
                    is_string(
                        $bot['bot_score']
                    )
                    && ctype_digit(
                        $bot['bot_score']
                    )
                )
            ) {
                throw new \InvalidArgumentException(
                    'Bot score must be an integer.'
                );
            }

            $score =
                (int) $bot['bot_score'];

            $this->validateBotScore(
                $score
            );

            $data['bot_score'] =
                $score;
        }

        if (
            array_key_exists(
                'bot_classification',
                $bot
            )
        ) {
            $classification =
                trim(
                    (string) $bot[
                        'bot_classification'
                    ]
                );

            $this->validateBotClassification(
                $classification
            );

            $data['bot_classification'] =
                $classification;
        }

        if ($data === []) {
            return false;
        }

        $data['updated_at'] =
            $this->nowUtc();

        return $this->updateByIdentifier(
            $visitorId,
            $data,
            $this->buildFormats(
                $data
            )
        );
    }

    public function updateSeenRange(
        string $visitorId,
        string $firstSeen,
        string $lastSeen
    ): bool {
        $visitorId = trim(
            $visitorId
        );

        $this->assertVisitorId(
            $visitorId
        );

        $this->validateDateTime(
            $firstSeen
        );

        $this->validateDateTime(
            $lastSeen
        );

        if (
            $this->compareDateTimes(
                $firstSeen,
                $lastSeen
            ) > 0
        ) {
            throw new \InvalidArgumentException(
                'Visitor first_seen cannot be later than last_seen.'
            );
        }

        $existing = $this->findByIdentifier(
            $visitorId
        );

        if ($existing === null) {
            return false;
        }

        $currentFirstSeen =
            (string) (
                $existing['first_seen']
                ?? ''
            );

        $currentLastSeen =
            (string) (
                $existing['last_seen']
                ?? ''
            );

        if (
            $currentFirstSeen === ''
            || $currentLastSeen === ''
        ) {
            throw new \RuntimeException(
                'Visitor has invalid persisted seen range.'
            );
        }

        $table = $this->table();

        $affected =
            $this->database->execute(
                "UPDATE {$table}
                 SET
                     first_seen = LEAST(
                         first_seen,
                         %s
                     ),
                     last_seen = GREATEST(
                         last_seen,
                         %s
                     ),
                     updated_at = %s
                 WHERE visitor_id = %s",
                $firstSeen,
                $lastSeen,
                $this->nowUtc(),
                $visitorId
            );

        return $affected > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findRecentlyActive(
        int $limit = 100
    ): array {
        if ($limit < 1) {
            throw new \InvalidArgumentException(
                'Visitor list limit must be greater than zero.'
            );
        }

        $limit = min(
            $limit,
            self::MAX_LIST_LIMIT
        );

        return $this->findMany(
            [],
            [],
            'last_seen',
            'DESC',
            $limit
        );
    }

    /**
     * Returns the complete filtered visitor set used by
     * external sorting such as Gravity Forms submission count.
     *
     * This method intentionally does not know anything about Gravity Forms.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function findAllForExternalSort(
        array $filters = []
    ): array {
        [$sql, $values] =
            $this->buildVisitorListQuery(
                $filters,
                'last_seen',
                'DESC',
                self::MAX_LIST_LIMIT,
                0
            );

        return $this->database->getResults(
            $sql,
            ...$values
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function findFiltered(
        array $filters = [],
        string $sort = 'last_seen',
        string $direction = 'DESC',
        int $page = self::DEFAULT_PAGE,
        int $perPage = self::DEFAULT_PER_PAGE
    ): array {
        $page = max(
            1,
            $page
        );

        $perPage = min(
            max(
                1,
                $perPage
            ),
            self::MAX_PER_PAGE
        );

        $offset =
            ($page - 1)
            * $perPage;

        [$sql, $values] =
            $this->buildVisitorListQuery(
                $filters,
                $sort,
                $direction,
                $perPage,
                $offset
            );

        return $this->database->getResults(
            $sql,
            ...$values
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countFiltered(
        array $filters = []
    ): int {
        [$where, $values] =
            $this->buildVisitorWhere(
                $filters
            );

        $table = $this->table();

        $sql =
            "SELECT COUNT(*)
             FROM {$table}";

        if ($where !== '') {
            $sql .=
                ' WHERE '
                . $where;
        }

        $result =
            $this->database->getVar(
                $sql,
                ...$values
            );

        if (
            $result === null
            || $result === false
        ) {
            return 0;
        }

        if (
            !is_int($result)
            && !(
                is_string($result)
                && preg_match(
                    '/^\d+$/',
                    $result
                ) === 1
            )
        ) {
            throw new \RuntimeException(
                'Visitor count query returned an invalid value.'
            );
        }

        return max(
            0,
            (int) $result
        );
    }

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
        int $page = self::DEFAULT_PAGE,
        int $perPage = self::DEFAULT_PER_PAGE
    ): array {
        $page = max(
            1,
            $page
        );

        $perPage = min(
            max(
                1,
                $perPage
            ),
            self::MAX_PER_PAGE
        );

        $total =
            $this->countFiltered(
                $filters
            );

        $totalPages =
            $total > 0
                ? (int) ceil(
                    $total / $perPage
                )
                : 0;

        if (
            $totalPages > 0
            && $page > $totalPages
        ) {
            $page =
                $totalPages;
        }

        $items =
            $this->findFiltered(
                $filters,
                $sort,
                $direction,
                $page,
                $perPage
            );

        return [
            'items' =>
                $items,

            'total' =>
                $total,

            'page' =>
                $page,

            'per_page' =>
                $perPage,

            'total_pages' =>
                $totalPages,
        ];
    }

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
    ): array {
        return [
            'countries' =>
                $this->getCountryOptions(
                    $filters
                ),

            'regions' =>
                $this->getRegionOptions(
                    $filters
                ),

            'cities' =>
                $this->getDistinctStringValues(
                    'city',
                    $filters,
                    'city'
                ),

            'device_types' =>
                $this->getDistinctStringValues(
                    'device_type',
                    $filters,
                    'device_type'
                ),

            'browsers' =>
                $this->getDistinctStringValues(
                    'browser',
                    $filters,
                    'browser'
                ),

            'operating_systems' =>
                $this->getDistinctStringValues(
                    'os',
                    $filters,
                    'os'
                ),

            'bot_classifications' =>
                self::BOT_CLASSIFICATIONS,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSummary(
        string $visitorId
    ): ?array {
        $visitor =
            $this->findById(
                $visitorId
            );

        if ($visitor === null) {
            return null;
        }

        return [
            'visitor_id' =>
                $visitor['visitor_id'],

            'first_seen' =>
                $visitor['first_seen'],

            'last_seen' =>
                $visitor['last_seen'],

            'sessions_count' =>
                (int) $visitor[
                    'sessions_count'
                ],

            'pageviews_count' =>
                (int) $visitor[
                    'pageviews_count'
                ],

            'active_seconds' =>
                (int) $visitor[
                    'active_seconds'
                ],

            'country_code' =>
                $visitor['country_code'],

            'region_code' =>
                $visitor['region_code'],

            'city' =>
                $visitor['city'],

            'country_name' =>
                $visitor['country_name'],

            'region_name' =>
                $visitor['region_name'],

            'latitude' =>
                $visitor['latitude'],

            'longitude' =>
                $visitor['longitude'],

            'geo_source' =>
                $visitor['geo_source'],

            'geo_database_version' =>
                $visitor['geo_database_version'],

            'device_type' =>
                $visitor['device_type'],

            'browser' =>
                $visitor['browser'],

            'browser_version' =>
                $visitor['browser_version'],

            'os' =>
                $visitor['os'],

            'os_version' =>
                $visitor['os_version'],

            'bot_score' =>
                (int) $visitor[
                    'bot_score'
                ],

            'bot_classification' =>
                $visitor[
                    'bot_classification'
                ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildVisitorWhere(
        array $filters
    ): array {
        $conditions = [];

        $values = [];

        $search =
            $this->nullableFilterString(
                $filters,
                'search'
            );

        if ($search !== null) {
            $like =
                '%' . $this->escapeLikeValue(
                    $search
                ) . '%';

            $conditions[] =
                '('
                . 'visitor_id LIKE %s '
                . 'OR country_name LIKE %s '
                . 'OR region_name LIKE %s '
                . 'OR city LIKE %s '
                . 'OR device_type LIKE %s '
                . 'OR browser LIKE %s '
                . 'OR os LIKE %s'
                . ')';

            for ($i = 0; $i < 7; $i++) {
                $values[] =
                    $like;
            }
        }

        $visitorId =
            $this->nullableFilterString(
                $filters,
                'visitor_id'
            );

        if ($visitorId !== null) {
            $conditions[] =
                'visitor_id = %s';

            $values[] =
                $visitorId;
        }

        $this->appendExactFilter(
            $conditions,
            $values,
            $filters,
            'country_code'
        );

        $this->appendExactFilter(
            $conditions,
            $values,
            $filters,
            'country_name'
        );

        $this->appendExactFilter(
            $conditions,
            $values,
            $filters,
            'region_code'
        );

        $this->appendExactFilter(
            $conditions,
            $values,
            $filters,
            'region_name'
        );

        $this->appendExactFilter(
            $conditions,
            $values,
            $filters,
            'city'
        );

        $this->appendExactFilter(
            $conditions,
            $values,
            $filters,
            'device_type'
        );

        $this->appendExactFilter(
            $conditions,
            $values,
            $filters,
            'browser'
        );

        $this->appendExactFilter(
            $conditions,
            $values,
            $filters,
            'os'
        );

        $this->appendExactFilter(
            $conditions,
            $values,
            $filters,
            'bot_classification'
        );

        if (
            array_key_exists(
                'geo_resolved',
                $filters
            )
        ) {
            $resolved =
                $filters['geo_resolved'];

            if (is_bool($resolved)) {
                $resolved =
                    $resolved
                        ? '1'
                        : '0';
            }

            $resolved =
                strtolower(
                    trim(
                        (string) $resolved
                    )
                );

            if (
                in_array(
                    $resolved,
                    [
                        '1',
                        'true',
                        'yes',
                    ],
                    true
                )
            ) {
                $conditions[] =
                    '('
                    . 'country_name IS NOT NULL '
                    . 'OR region_name IS NOT NULL '
                    . 'OR city IS NOT NULL'
                    . ')';
            } elseif (
                in_array(
                    $resolved,
                    [
                        '0',
                        'false',
                        'no',
                    ],
                    true
                )
            ) {
                $conditions[] =
                    '('
                    . 'country_name IS NULL '
                    . 'AND region_name IS NULL '
                    . 'AND city IS NULL'
                    . ')';
            }
        }

        $this->appendDateFilter(
            $conditions,
            $values,
            $filters,
            'first_seen',
            'first_seen_from',
            '>='
        );

        $this->appendDateFilter(
            $conditions,
            $values,
            $filters,
            'first_seen',
            'first_seen_to',
            '<='
        );

        $this->appendDateFilter(
            $conditions,
            $values,
            $filters,
            'last_seen',
            'last_seen_from',
            '>='
        );

        $this->appendDateFilter(
            $conditions,
            $values,
            $filters,
            'last_seen',
            'last_seen_to',
            '<='
        );

        return [
            implode(
                ' AND ',
                $conditions
            ),
            $values,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildVisitorListQuery(
        array $filters,
        string $sort,
        string $direction,
        int $limit,
        int $offset
    ): array {
        $sort =
            strtolower(
                trim($sort)
            );

        if (
            !isset(
                self::SORT_FIELDS[$sort]
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Unsupported Visitor sort field: %s',
                    $sort
                )
            );
        }

        $direction =
            strtoupper(
                trim($direction)
            );

        if (
            !in_array(
                $direction,
                [
                    'ASC',
                    'DESC',
                ],
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'Visitor sort direction must be ASC or DESC.'
            );
        }

        $limit =
            min(
                max(
                    1,
                    $limit
                ),
                self::MAX_LIST_LIMIT
            );

        $offset =
            max(
                0,
                $offset
            );

        [$where, $values] =
            $this->buildVisitorWhere(
                $filters
            );

        $table =
            $this->table();

        $columns =
            implode(
                ', ',
                self::LIST_COLUMNS
            );

        $orderColumn =
            self::SORT_FIELDS[$sort];

        $sql =
            "SELECT {$columns}
             FROM {$table}";

        if ($where !== '') {
            $sql .=
                ' WHERE '
                . $where;
        }

        $sql .=
            " ORDER BY {$orderColumn} {$direction}, id DESC"
            . " LIMIT {$limit} OFFSET {$offset}";

        return [
            $sql,
            $values,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function getCountryOptions(
        array $filters
    ): array {
        $countryFilters =
            $this->removeFilter(
                $filters,
                [
                    'country_code',
                    'country_name',
                    'region_code',
                    'region_name',
                    'city',
                ]
            );

        [$where, $values] =
            $this->buildVisitorWhere(
                $countryFilters
            );

        $table =
            $this->table();

        $conditions = [];

        if ($where !== '') {
            $conditions[] =
                $where;
        }

        $conditions[] =
            'country_name IS NOT NULL';

        $sql =
            "SELECT
                 country_code,
                 country_name,
                 COUNT(*) AS visitors_count
             FROM {$table}
             WHERE "
            . implode(
                ' AND ',
                $conditions
            )
            . " GROUP BY
                 country_code,
                 country_name
               ORDER BY
                 country_name ASC";

        $results =
            $this->database->getResults(
                $sql,
                ...$values
            );

        return array_map(
            static function (
                array $row
            ): array {
                return [
                    'code' =>
                        $row['country_code']
                        ?? null,

                    'name' =>
                        $row['country_name']
                        ?? null,

                    'count' =>
                        (int) (
                            $row['visitors_count']
                            ?? 0
                        ),
                ];
            },
            $results
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function getRegionOptions(
        array $filters
    ): array {
        $regionFilters =
            $this->removeFilter(
                $filters,
                [
                    'region_code',
                    'region_name',
                    'city',
                ]
            );

        [$where, $values] =
            $this->buildVisitorWhere(
                $regionFilters
            );

        $table =
            $this->table();

        $conditions = [];

        if ($where !== '') {
            $conditions[] =
                $where;
        }

        $conditions[] =
            'region_name IS NOT NULL';

        $sql =
            "SELECT
                 region_code,
                 region_name,
                 country_code,
                 country_name,
                 COUNT(*) AS visitors_count
             FROM {$table}
             WHERE "
            . implode(
                ' AND ',
                $conditions
            )
            . " GROUP BY
                 region_code,
                 region_name,
                 country_code,
                 country_name
               ORDER BY
                 country_name ASC,
                 region_name ASC";

        $results =
            $this->database->getResults(
                $sql,
                ...$values
            );

        return array_map(
            static function (
                array $row
            ): array {
                return [
                    'code' =>
                        $row['region_code']
                        ?? null,

                    'name' =>
                        $row['region_name']
                        ?? null,

                    'country_code' =>
                        $row['country_code']
                        ?? null,

                    'country_name' =>
                        $row['country_name']
                        ?? null,

                    'count' =>
                        (int) (
                            $row['visitors_count']
                            ?? 0
                        ),
                ];
            },
            $results
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, string>
     */
    private function getDistinctStringValues(
        string $column,
        array $filters,
        string $filterName
    ): array {
        $allowed = [
            'city',
            'device_type',
            'browser',
            'os',
        ];

        if (
            !in_array(
                $column,
                $allowed,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Unsupported Visitor filter option column: %s',
                    $column
                )
            );
        }

        $filtered =
            $this->removeFilter(
                $filters,
                [
                    $filterName,
                ]
            );

        [$where, $values] =
            $this->buildVisitorWhere(
                $filtered
            );

        $table =
            $this->table();

        $conditions = [];

        if ($where !== '') {
            $conditions[] =
                $where;
        }

        $conditions[] =
            "{$column} IS NOT NULL";

        $sql =
            "SELECT DISTINCT {$column}
             FROM {$table}
             WHERE "
            . implode(
                ' AND ',
                $conditions
            )
            . " ORDER BY {$column} ASC";

        $results =
            $this->database->getResults(
                $sql,
                ...$values
            );

        $result = [];

        foreach ($results as $row) {
            $value =
                trim(
                    (string) (
                        $row[$column]
                        ?? ''
                    )
                );

            if ($value === '') {
                continue;
            }

            $result[] =
                $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, string> $remove
     * @return array<string, mixed>
     */
    private function removeFilter(
        array $filters,
        array $remove
    ): array {
        foreach ($remove as $key) {
            unset(
                $filters[$key]
            );
        }

        return $filters;
    }

    /**
     * @param array<int, string> $conditions
     * @param array<int, mixed> $values
     * @param array<string, mixed> $filters
     */
    private function appendExactFilter(
        array &$conditions,
        array &$values,
        array $filters,
        string $field
    ): void {
        if (
            !array_key_exists(
                $field,
                $filters
            )
        ) {
            return;
        }

        $value =
            $filters[$field];

        if (
            $value === null
            || $value === ''
        ) {
            return;
        }

        if (
            !is_scalar($value)
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor filter %s must be scalar.',
                    $field
                )
            );
        }

        $conditions[] =
            "{$field} = %s";

        $values[] =
            (string) $value;
    }

    /**
     * @param array<int, string> $conditions
     * @param array<int, mixed> $values
     * @param array<string, mixed> $filters
     */
    private function appendDateFilter(
        array &$conditions,
        array &$values,
        array $filters,
        string $column,
        string $filter,
        string $operator
    ): void {
        if (
            !array_key_exists(
                $filter,
                $filters
            )
        ) {
            return;
        }

        $value =
            trim(
                (string) $filters[$filter]
            );

        if ($value === '') {
            return;
        }

        $this->validateDateTime(
            $value
        );

        $conditions[] =
            "{$column} {$operator} %s";

        $values[] =
            $value;
    }

    private function nullableFilterString(
        array $filters,
        string $key
    ): ?string {
        if (
            !array_key_exists(
                $key,
                $filters
            )
        ) {
            return null;
        }

        $value =
            trim(
                (string) $filters[$key]
            );

        return $value === ''
            ? null
            : $value;
    }

    private function escapeLikeValue(
        string $value
    ): string {
        return addcslashes(
            $value,
            "\\%_"
        );
    }

    private function incrementCounter(
        string $visitorId,
        string $column,
        int $amount
    ): bool {
        $visitorId = trim(
            $visitorId
        );

        $this->assertVisitorId(
            $visitorId
        );

        if ($amount < 1) {
            throw new \InvalidArgumentException(
                'Visitor counter increment must be greater than zero.'
            );
        }

        $allowed = [
            'sessions_count',
            'pageviews_count',
            'active_seconds',
        ];

        if (
            !in_array(
                $column,
                $allowed,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Unsupported Visitor counter: %s',
                    $column
                )
            );
        }

        $table = $this->table();

        $affected =
            $this->database->execute(
                "UPDATE {$table}
                 SET
                     {$column} =
                         {$column} + %d,
                     updated_at = %s
                 WHERE visitor_id = %s",
                $amount,
                $this->nowUtc(),
                $visitorId
            );

        return $affected > 0;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function filterUpdateData(
        array $data
    ): array {
        $allowed = [
            'first_seen',
            'last_seen',
            'sessions_count',
            'pageviews_count',
            'active_seconds',
            'country_code',
            'region_code',
            'city',
            'country_name',
            'region_name',
            'latitude',
            'longitude',
            'geo_source',
            'geo_database_version',
            'device_type',
            'browser',
            'browser_version',
            'os',
            'os_version',
            'bot_score',
            'bot_classification',
        ];

        $result = [];

        foreach ($allowed as $field) {
            if (
                array_key_exists(
                    $field,
                    $data
                )
            ) {
                $result[$field] =
                    $data[$field];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $existing
     */
    private function validateUpdateData(
        array $data,
        array $existing
    ): void {
        foreach (
            [
                'sessions_count',
                'pageviews_count',
                'active_seconds',
            ] as $field
        ) {
            if (
                !array_key_exists(
                    $field,
                    $data
                )
            ) {
                continue;
            }

            $this->validateNonNegativeInteger(
                $data[$field],
                $field
            );

            if (
                isset($existing[$field])
                && (int) $data[$field]
                    < (int) $existing[$field]
            ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Visitor field %s cannot decrease.',
                        $field
                    )
                );
            }
        }

        if (
            array_key_exists(
                'bot_score',
                $data
            )
        ) {
            $this->validateBotScore(
                $data['bot_score']
            );
        }

        if (
            array_key_exists(
                'bot_classification',
                $data
            )
        ) {
            $this->validateBotClassification(
                (string) $data[
                    'bot_classification'
                ]
            );
        }

        foreach (
            [
                'first_seen',
                'last_seen',
            ] as $field
        ) {
            if (
                array_key_exists(
                    $field,
                    $data
                )
            ) {
                $this->validateDateTime(
                    (string) $data[$field]
                );
            }
        }

        foreach (
            [
                'country_code' =>
                    2,

                'region_code' =>
                    16,

                'city' =>
                    128,

                'country_name' =>
                    128,

                'region_name' =>
                    128,

                'geo_source' =>
                    64,

                'geo_database_version' =>
                    32,

                'device_type' =>
                    32,

                'browser' =>
                    64,

                'browser_version' =>
                    32,

                'os' =>
                    64,

                'os_version' =>
                    32,
            ] as $field => $maxLength
        ) {
            if (
                array_key_exists(
                    $field,
                    $data
                )
            ) {
                $this->normalizeNullableString(
                    $data[$field],
                    $maxLength,
                    $field
                );
            }
        }

        foreach (
            [
                'latitude' => [
                    -90,
                    90,
                ],

                'longitude' => [
                    -180,
                    180,
                ],
            ] as $field => $range
        ) {
            if (
                array_key_exists(
                    $field,
                    $data
                )
            ) {
                $this->normalizeCoordinate(
                    $data[$field],
                    $field,
                    $range[0],
                    $range[1]
                );
            }
        }

        $currentFirstSeen =
            (string) (
                $existing['first_seen']
                ?? ''
            );

        $currentLastSeen =
            (string) (
                $existing['last_seen']
                ?? ''
            );

        if (
            array_key_exists(
                'first_seen',
                $data
            )
            && $currentFirstSeen !== ''
            && $this->compareDateTimes(
                (string) $data['first_seen'],
                $currentFirstSeen
            ) > 0
        ) {
            throw new \InvalidArgumentException(
                'Visitor first_seen cannot move forward.'
            );
        }

        if (
            array_key_exists(
                'last_seen',
                $data
            )
            && $currentLastSeen !== ''
            && $this->compareDateTimes(
                (string) $data['last_seen'],
                $currentLastSeen
            ) < 0
        ) {
            throw new \InvalidArgumentException(
                'Visitor last_seen cannot move backwards.'
            );
        }

        $effectiveFirstSeen =
            array_key_exists(
                'first_seen',
                $data
            )
                ? (string) $data['first_seen']
                : $currentFirstSeen;

        $effectiveLastSeen =
            array_key_exists(
                'last_seen',
                $data
            )
                ? (string) $data['last_seen']
                : $currentLastSeen;

        if (
            $effectiveFirstSeen !== ''
            && $effectiveLastSeen !== ''
            && $this->compareDateTimes(
                $effectiveFirstSeen,
                $effectiveLastSeen
            ) > 0
        ) {
            throw new \InvalidArgumentException(
                'Visitor first_seen cannot be later than last_seen.'
            );
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function validateRecord(
        array $record
    ): void {
        foreach (
            [
                'sessions_count',
                'pageviews_count',
                'active_seconds',
                'bot_score',
            ] as $field
        ) {
            $this->validateNonNegativeInteger(
                $record[$field] ?? 0,
                $field
            );
        }

        $this->validateBotScore(
            $record['bot_score']
        );

        $this->validateBotClassification(
            (string) $record[
                'bot_classification'
            ]
        );

        $this->validateDateTime(
            (string) $record[
                'first_seen'
            ]
        );

        $this->validateDateTime(
            (string) $record[
                'last_seen'
            ]
        );

        if (
            $this->compareDateTimes(
                (string) $record[
                    'first_seen'
                ],
                (string) $record[
                    'last_seen'
                ]
            ) > 0
        ) {
            throw new \InvalidArgumentException(
                'Visitor first_seen cannot be later than last_seen.'
            );
        }

        $this->validateDateTime(
            (string) $record[
                'created_at'
            ]
        );

        $this->validateDateTime(
            (string) $record[
                'updated_at'
            ]
        );

        foreach (
            [
                'country_code' => [
                    2,
                ],

                'region_code' => [
                    16,
                ],

                'city' => [
                    128,
                ],

                'country_name' => [
                    128,
                ],

                'region_name' => [
                    128,
                ],

                'geo_source' => [
                    64,
                ],

                'geo_database_version' => [
                    32,
                ],

                'device_type' => [
                    32,
                ],

                'browser' => [
                    64,
                ],

                'browser_version' => [
                    32,
                ],

                'os' => [
                    64,
                ],

                'os_version' => [
                    32,
                ],
            ] as $field => $definition
        ) {
            $this->normalizeNullableString(
                $record[$field] ?? null,
                $definition[0],
                $field
            );
        }

        $this->normalizeCoordinate(
            $record['latitude'] ?? null,
            'latitude',
            -90,
            90
        );

        $this->normalizeCoordinate(
            $record['longitude'] ?? null,
            'longitude',
            -180,
            180
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function buildFormats(
        array $data
    ): array {
        $integerFields = [
            'sessions_count',
            'pageviews_count',
            'active_seconds',
            'bot_score',
        ];

        $formats = [];

        foreach (
            $data as $field => $value
        ) {
            $formats[] =
                in_array(
                    $field,
                    $integerFields,
                    true
                )
                    ? '%d'
                    : '%s';
        }

        return $formats;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildRecord(
        string $visitorId,
        array $data
    ): array {
        $now =
            $this->nowUtc();

        $record = [
            'visitor_id' =>
                $visitorId,

            'first_seen' =>
                $data['first_seen']
                ?? $now,

            'last_seen' =>
                $data['last_seen']
                ?? $now,

            'sessions_count' =>
                $data['sessions_count']
                ?? 1,

            'pageviews_count' =>
                $data['pageviews_count']
                ?? 0,

            'active_seconds' =>
                $data['active_seconds']
                ?? 0,

            'country_code' =>
                $this->normalizeNullableString(
                    $data['country_code']
                    ?? null,
                    2,
                    'country_code'
                ),

            'region_code' =>
                $this->normalizeNullableString(
                    $data['region_code']
                    ?? null,
                    16,
                    'region_code'
                ),

            'city' =>
                $this->normalizeNullableString(
                    $data['city']
                    ?? null,
                    128,
                    'city'
                ),

            'country_name' =>
                $this->normalizeNullableString(
                    $data['country_name']
                    ?? null,
                    128,
                    'country_name'
                ),

            'region_name' =>
                $this->normalizeNullableString(
                    $data['region_name']
                    ?? null,
                    128,
                    'region_name'
                ),

            'latitude' =>
                $this->normalizeCoordinate(
                    $data['latitude']
                    ?? null,
                    'latitude',
                    -90,
                    90
                ),

            'longitude' =>
                $this->normalizeCoordinate(
                    $data['longitude']
                    ?? null,
                    'longitude',
                    -180,
                    180
                ),

            'geo_source' =>
                $this->normalizeNullableString(
                    $data['geo_source']
                    ?? null,
                    64,
                    'geo_source'
                ),

            'geo_database_version' =>
                $this->normalizeNullableString(
                    $data['geo_database_version']
                    ?? null,
                    32,
                    'geo_database_version'
                ),

            'device_type' =>
                $this->normalizeNullableString(
                    $data['device_type']
                    ?? null,
                    32,
                    'device_type'
                ),

            'browser' =>
                $this->normalizeNullableString(
                    $data['browser']
                    ?? null,
                    64,
                    'browser'
                ),

            'browser_version' =>
                $this->normalizeNullableString(
                    $data['browser_version']
                    ?? null,
                    32,
                    'browser_version'
                ),

            'os' =>
                $this->normalizeNullableString(
                    $data['os']
                    ?? null,
                    64,
                    'os'
                ),

            'os_version' =>
                $this->normalizeNullableString(
                    $data['os_version']
                    ?? null,
                    32,
                    'os_version'
                ),

            'bot_score' =>
                $data['bot_score']
                ?? 0,

            'bot_classification' =>
                trim(
                    (string) (
                        $data[
                            'bot_classification'
                        ]
                        ?? 'unknown'
                    )
                ),

            'created_at' =>
                $data['created_at']
                ?? $now,

            'updated_at' =>
                $data['updated_at']
                ?? $now,
        ];

        $this->validateRecord(
            $record
        );

        return $record;
    }

    private function validateNonNegativeInteger(
        mixed $value,
        string $field
    ): void {
        if (
            !is_int($value)
            && !(
                is_string($value)
                && ctype_digit($value)
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor field %s must be an integer.',
                    $field
                )
            );
        }

        if ((int) $value < 0) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor field %s cannot be negative.',
                    $field
                )
            );
        }
    }

    private function validateBotScore(
        mixed $score
    ): void {
        $this->validateNonNegativeInteger(
            $score,
            'bot_score'
        );

        if ((int) $score > 100) {
            throw new \InvalidArgumentException(
                'Bot score must be between 0 and 100.'
            );
        }
    }

    private function validateBotClassification(
        string $classification
    ): void {
        if (
            !in_array(
                $classification,
                self::BOT_CLASSIFICATIONS,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid bot classification: %s',
                    $classification
                )
            );
        }
    }

    private function normalizeNullableString(
        mixed $value,
        int $maxLength,
        string $field
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor field %s must be a string or null.',
                    $field
                )
            );
        }

        $value = trim(
            $value
        );

        if ($value === '') {
            return null;
        }

        $length =
            function_exists(
                'mb_strlen'
            )
                ? mb_strlen(
                    $value,
                    'UTF-8'
                )
                : strlen($value);

        if ($length > $maxLength) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor field %s exceeds maximum length of %d.',
                    $field,
                    $maxLength
                )
            );
        }

        return $value;
    }

    private function normalizeCoordinate(
        mixed $value,
        string $field,
        float $minimum,
        float $maximum
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (
            is_int($value)
            || is_float($value)
        ) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor field %s must be a decimal string or null.',
                    $field
                )
            );
        }

        $value = trim(
            $value
        );

        if ($value === '') {
            return null;
        }

        if (
            preg_match(
                '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/',
                $value
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor field %s must be a valid decimal value.',
                    $field
                )
            );
        }

        $numeric =
            (float) $value;

        if (
            !is_finite($numeric)
            || $numeric < $minimum
            || $numeric > $maximum
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor field %s must be between %s and %s.',
                    $field,
                    $minimum,
                    $maximum
                )
            );
        }

        return $value;
    }

    private function validateDateTime(
        string $value
    ): void {
        $value = trim(
            $value
        );

        $date =
            \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $value,
                new \DateTimeZone('UTC')
            );

        if (
            $date === false
            || $date->format(
                'Y-m-d H:i:s'
            ) !== $value
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid UTC datetime: %s',
                    $value
                )
            );
        }
    }

    private function compareDateTimes(
        string $left,
        string $right
    ): int {
        $leftDate =
            $this->createDateTime(
                $left
            );

        $rightDate =
            $this->createDateTime(
                $right
            );

        return $leftDate->getTimestamp()
            <=>
            $rightDate->getTimestamp();
    }

    private function createDateTime(
        string $value
    ): \DateTimeImmutable {
        $this->validateDateTime(
            $value
        );

        $date =
            \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                trim($value),
                new \DateTimeZone('UTC')
            );

        if ($date === false) {
            throw new \RuntimeException(
                'Unable to create visitor datetime.'
            );
        }

        return $date;
    }

    private function assertVisitorId(
        string $visitorId
    ): void {
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $visitorId
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Visitor ID must be a valid UUID.'
            );
        }
    }

    protected function nowUtc(): string
    {
        return current_time(
            'mysql',
            true
        );
    }
}