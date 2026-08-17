<?php

declare(strict_types=1);

namespace VisitorIntelligence\Database\Migrations;

use VisitorIntelligence\Database\Database;
use VisitorIntelligence\Database\MigrationInterface;
use VisitorIntelligence\Database\Schema;

defined('ABSPATH') || exit;

final class Migration_001_Initial implements MigrationInterface
{
    private const VERSION = 1;

    private const ID = 'vi_001_initial';

    public function getVersion(): int
    {
        return self::VERSION;
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Database $database): void
    {
        $upgradeFile =
            ABSPATH
            . 'wp-admin/includes/upgrade.php';

        if (
            !is_readable(
                $upgradeFile
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'WordPress database upgrade API is unavailable: %s',
                    $upgradeFile
                )
            );
        }

        require_once $upgradeFile;

        if (!function_exists('dbDelta')) {
            throw new \RuntimeException(
                'WordPress dbDelta() function is unavailable.'
            );
        }

        $queries =
            $this->getQueries(
                $database
            );

        foreach (
            $queries as $table => $query
        ) {
            $this->executeDelta(
                $database,
                $table,
                $query
            );
        }

        $this->validateTables(
            $database
        );
    }

    public function down(Database $database): void
    {
        $tables = [
            Schema::dailyStats(
                $database
            ),
            Schema::events(
                $database
            ),
            Schema::pageviews(
                $database
            ),
            Schema::sessions(
                $database
            ),
            Schema::visitors(
                $database
            ),
        ];

        foreach (
            $tables as $table
        ) {
            try {
                $database->execute(
                    "DROP TABLE IF EXISTS {$table}"
                );
            } catch (\Throwable $exception) {
                throw new \RuntimeException(
                    sprintf(
                        'Visitor Intelligence migration rollback failed for table %s: %s',
                        $table,
                        $exception->getMessage()
                    ),
                    0,
                    $exception
                );
            }
        }

        $this->validateTablesAbsent(
            $database
        );
    }

    /**
     * @return array<string, string>
     */
    private function getQueries(
        Database $database
    ): array {
        $tableOptions =
            'ENGINE=InnoDB '
            . 'DEFAULT CHARSET=utf8mb4 '
            . 'COLLATE=utf8mb4_unicode_ci '
            . 'ROW_FORMAT=DYNAMIC';

        $visitors =
            Schema::visitors(
                $database
            );

        $sessions =
            Schema::sessions(
                $database
            );

        $pageviews =
            Schema::pageviews(
                $database
            );

        $events =
            Schema::events(
                $database
            );

        $dailyStats =
            Schema::dailyStats(
                $database
            );

        return [
            $visitors => "
                CREATE TABLE {$visitors} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    visitor_id CHAR(36) NOT NULL,
                    first_seen DATETIME NOT NULL,
                    last_seen DATETIME NOT NULL,
                    sessions_count INT UNSIGNED NOT NULL DEFAULT 1,
                    pageviews_count INT UNSIGNED NOT NULL DEFAULT 0,
                    active_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    country_code CHAR(2) DEFAULT NULL,
                    region_code VARCHAR(16) DEFAULT NULL,
                    city VARCHAR(128) DEFAULT NULL,
                    device_type VARCHAR(32) DEFAULT NULL,
                    browser VARCHAR(64) DEFAULT NULL,
                    browser_version VARCHAR(32) DEFAULT NULL,
                    os VARCHAR(64) DEFAULT NULL,
                    os_version VARCHAR(32) DEFAULT NULL,
                    bot_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    bot_classification VARCHAR(16) NOT NULL DEFAULT 'unknown',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_visitor_id (visitor_id),
                    KEY idx_last_seen (last_seen),
                    KEY idx_first_seen (first_seen),
                    KEY idx_geo (country_code, region_code),
                    KEY idx_bot (bot_classification)
                ) {$tableOptions}
            ",

            $sessions => "
                CREATE TABLE {$sessions} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    session_id CHAR(36) NOT NULL,
                    visitor_id CHAR(36) NOT NULL,
                    started_at DATETIME NOT NULL,
                    last_activity_at DATETIME NOT NULL,
                    ended_at DATETIME DEFAULT NULL,
                    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                    active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                    landing_page_id BIGINT UNSIGNED DEFAULT NULL,
                    landing_url TEXT NOT NULL,
                    exit_page_id BIGINT UNSIGNED DEFAULT NULL,
                    exit_url TEXT DEFAULT NULL,
                    source_type VARCHAR(32) NOT NULL DEFAULT 'unknown',
                    source_name VARCHAR(128) DEFAULT NULL,
                    source_domain VARCHAR(255) DEFAULT NULL,
                    referrer_url TEXT DEFAULT NULL,
                    country_code CHAR(2) DEFAULT NULL,
                    region_code VARCHAR(16) DEFAULT NULL,
                    city VARCHAR(128) DEFAULT NULL,
                    device_type VARCHAR(32) DEFAULT NULL,
                    browser VARCHAR(64) DEFAULT NULL,
                    os VARCHAR(64) DEFAULT NULL,
                    bot_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    bot_classification VARCHAR(16) NOT NULL DEFAULT 'unknown',
                    tracking_mode VARCHAR(16) NOT NULL DEFAULT 'full',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_session_id (session_id),
                    KEY idx_visitor_id (visitor_id),
                    KEY idx_started_at (started_at),
                    KEY idx_last_activity (last_activity_at),
                    KEY idx_source (source_type, source_name(32)),
                    KEY idx_geo (country_code, region_code),
                    KEY idx_bot (bot_classification),
                    KEY idx_composite_lookup (visitor_id, started_at)
                ) {$tableOptions}
            ",

            $pageviews => "
                CREATE TABLE {$pageviews} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    pageview_id CHAR(36) NOT NULL,
                    visitor_id CHAR(36) NOT NULL,
                    session_id CHAR(36) NOT NULL,
                    occurred_at DATETIME NOT NULL,
                    url TEXT NOT NULL,
                    url_hash CHAR(64) NOT NULL,
                    post_id BIGINT UNSIGNED DEFAULT NULL,
                    previous_url TEXT DEFAULT NULL,
                    referrer_url TEXT DEFAULT NULL,
                    sequence_number INT UNSIGNED NOT NULL DEFAULT 1,
                    active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                    visible_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                    is_landing TINYINT(1) NOT NULL DEFAULT 0,
                    is_exit TINYINT(1) NOT NULL DEFAULT 0,
                    bot_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    bot_classification VARCHAR(16) NOT NULL DEFAULT 'unknown',
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_pageview_id (pageview_id),
                    KEY idx_visitor (visitor_id),
                    KEY idx_session (session_id),
                    KEY idx_occurred_at (occurred_at),
                    KEY idx_post_id (post_id),
                    KEY idx_url_hash (url_hash),
                    KEY idx_session_seq (session_id, sequence_number),
                    KEY idx_post_time (post_id, occurred_at)
                ) {$tableOptions}
            ",

            $events => "
                CREATE TABLE {$events} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    event_id CHAR(36) NOT NULL,
                    visitor_id CHAR(36) NOT NULL,
                    session_id CHAR(36) NOT NULL,
                    pageview_id CHAR(36) DEFAULT NULL,
                    event_type VARCHAR(64) NOT NULL,
                    schema_version VARCHAR(8) NOT NULL DEFAULT 'v1',
                    occurred_at DATETIME NOT NULL,
                    payload LONGTEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_event_id (event_id),
                    KEY idx_visitor (visitor_id),
                    KEY idx_session (session_id),
                    KEY idx_pageview (pageview_id),
                    KEY idx_type (event_type),
                    KEY idx_occurred (occurred_at),
                    KEY idx_composite_event (session_id, occurred_at)
                ) {$tableOptions}
            ",

            $dailyStats => "
                CREATE TABLE {$dailyStats} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    date_key DATE NOT NULL,
                    dimension_type VARCHAR(32) NOT NULL,
                    dimension_value VARCHAR(255) NOT NULL DEFAULT 'total',
                    visitors_count INT UNSIGNED NOT NULL DEFAULT 0,
                    sessions_count INT UNSIGNED NOT NULL DEFAULT 0,
                    pageviews_count INT UNSIGNED NOT NULL DEFAULT 0,
                    active_seconds_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    bounces_count INT UNSIGNED NOT NULL DEFAULT 0,
                    entries_count INT UNSIGNED NOT NULL DEFAULT 0,
                    exits_count INT UNSIGNED NOT NULL DEFAULT 0,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_date_dimension (
                        date_key,
                        dimension_type,
                        dimension_value
                    ),
                    KEY idx_date (date_key),
                    KEY idx_dimension (
                        dimension_type,
                        dimension_value(64)
                    )
                ) {$tableOptions}
            ",
        ];
    }

    private function executeDelta(
        Database $database,
        string $table,
        string $query
    ): void {
        $wpdb =
            $database->getWpdb();

        $wpdb->last_error = '';

        try {
            $result =
                dbDelta(
                    $query
                );
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                sprintf(
                    'Visitor Intelligence migration failed while creating or updating table %s: %s',
                    $table,
                    $exception->getMessage()
                ),
                0,
                $exception
            );
        }

        $error =
            trim(
                (string) $wpdb->last_error
            );

        if ($error !== '') {
            throw new \RuntimeException(
                sprintf(
                    'Visitor Intelligence migration failed for table %s: %s',
                    $table,
                    $error
                )
            );
        }

        if (!is_array($result)) {
            throw new \RuntimeException(
                sprintf(
                    'Visitor Intelligence migration returned an invalid dbDelta result for table %s.',
                    $table
                )
            );
        }

        if (!$database->tableExists(
            $this->logicalTableName(
                $database,
                $table
            )
        )) {
            throw new \RuntimeException(
                sprintf(
                    'Visitor Intelligence migration did not create required table: %s',
                    $table
                )
            );
        }
    }

    private function validateTables(
        Database $database
    ): void {
        $tables = [
            'visitors',
            'sessions',
            'pageviews',
            'events',
            'daily_stats',
        ];

        foreach (
            $tables as $table
        ) {
            if (
                !$database->tableExists(
                    $table
                )
            ) {
                throw new \RuntimeException(
                    sprintf(
                        'Visitor Intelligence migration completed without required table: %s',
                        $database->table(
                            $table
                        )
                    )
                );
            }
        }
    }

    private function validateTablesAbsent(
        Database $database
    ): void {
        $tables = [
            'visitors',
            'sessions',
            'pageviews',
            'events',
            'daily_stats',
        ];

        foreach (
            $tables as $table
        ) {
            if (
                $database->tableExists(
                    $table
                )
            ) {
                throw new \RuntimeException(
                    sprintf(
                        'Visitor Intelligence migration rollback failed. Table still exists: %s',
                        $database->table(
                            $table
                        )
                    )
                );
            }
        }
    }

    private function logicalTableName(
        Database $database,
        string $physicalTable
    ): string {
        foreach (
            [
                'visitors',
                'sessions',
                'pageviews',
                'events',
                'daily_stats',
            ] as $logicalName
        ) {
            if (
                $database->table(
                    $logicalName
                ) === $physicalTable
            ) {
                return $logicalName;
            }
        }

        throw new \RuntimeException(
            sprintf(
                'Unknown Visitor Intelligence physical table: %s',
                $physicalTable
            )
        );
    }
}