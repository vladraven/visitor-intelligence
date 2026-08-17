<?php

declare(strict_types=1);

namespace VisitorIntelligence\Database\Migrations;

use VisitorIntelligence\Database\Database;
use VisitorIntelligence\Database\MigrationInterface;
use VisitorIntelligence\Database\Schema;

defined('ABSPATH') || exit;

final class Migration_002_GeoIp implements MigrationInterface
{
    private const VERSION = 2;

    private const ID = 'vi_002_geoip';

    /**
     * @var array<string, array<string, string>>
     */
    private const COLUMNS = [
        'visitors' => [
            'country_name' =>
                'VARCHAR(128) DEFAULT NULL',

            'region_name' =>
                'VARCHAR(128) DEFAULT NULL',

            'latitude' =>
                'DECIMAL(10,7) DEFAULT NULL',

            'longitude' =>
                'DECIMAL(10,7) DEFAULT NULL',

            'geo_source' =>
                'VARCHAR(64) DEFAULT NULL',

            'geo_database_version' =>
                'VARCHAR(32) DEFAULT NULL',
        ],

        'sessions' => [
            'country_name' =>
                'VARCHAR(128) DEFAULT NULL',

            'region_name' =>
                'VARCHAR(128) DEFAULT NULL',

            'latitude' =>
                'DECIMAL(10,7) DEFAULT NULL',

            'longitude' =>
                'DECIMAL(10,7) DEFAULT NULL',

            'geo_source' =>
                'VARCHAR(64) DEFAULT NULL',

            'geo_database_version' =>
                'VARCHAR(32) DEFAULT NULL',
        ],
    ];

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
        foreach (
            self::COLUMNS as $logicalTable => $columns
        ) {
            $table = $this->resolveTable(
                $database,
                $logicalTable
            );

            foreach (
                $columns as $column => $definition
            ) {
                if (
                    $this->columnExists(
                        $database,
                        $table,
                        $column
                    )
                ) {
                    continue;
                }

                $database->execute(
                    "ALTER TABLE {$table}
                     ADD COLUMN {$column} {$definition}"
                );

                if (
                    !$this->columnExists(
                        $database,
                        $table,
                        $column
                    )
                ) {
                    throw new \RuntimeException(
                        sprintf(
                            'GeoIP migration failed to create required column: %s.%s',
                            $table,
                            $column
                        )
                    );
                }
            }
        }
    }

    public function down(Database $database): void
    {
        foreach (
            self::COLUMNS as $logicalTable => $columns
        ) {
            $table = $this->resolveTable(
                $database,
                $logicalTable
            );

            foreach (
                array_keys($columns) as $column
            ) {
                if (
                    !$this->columnExists(
                        $database,
                        $table,
                        $column
                    )
                ) {
                    continue;
                }

                $database->execute(
                    "ALTER TABLE {$table}
                     DROP COLUMN {$column}"
                );

                if (
                    $this->columnExists(
                        $database,
                        $table,
                        $column
                    )
                ) {
                    throw new \RuntimeException(
                        sprintf(
                            'GeoIP migration rollback failed to remove column: %s.%s',
                            $table,
                            $column
                        )
                    );
                }
            }
        }
    }

    private function resolveTable(
        Database $database,
        string $logicalTable
    ): string {
        return match ($logicalTable) {
            'visitors' =>
                Schema::visitors(
                    $database
                ),

            'sessions' =>
                Schema::sessions(
                    $database
                ),

            default =>
                throw new \InvalidArgumentException(
                    sprintf(
                        'Unsupported GeoIP migration table: %s',
                        $logicalTable
                    )
                ),
        };
    }

    private function columnExists(
        Database $database,
        string $table,
        string $column
    ): bool {
        $result =
            $database->getVar(
                "SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND COLUMN_NAME = %s",
                $table,
                $column
            );

        return (int) $result > 0;
    }
}