<?php

declare(strict_types=1);

namespace VisitorIntelligence\Database;

defined('ABSPATH') || exit;

final class Migrator
{
    private const OPTION_NAME = 'vi_db_version';

    /**
     * @var array<int, MigrationInterface>
     */
    private array $migrations = [];

    /**
     * @var array<string, int>
     */
    private array $migrationIds = [];

    public function __construct(
        private readonly Database $database
    ) {
    }

    public function register(
        MigrationInterface $migration
    ): void {
        $version = $migration->getVersion();
        $id = trim($migration->getId());

        if ($version < 1) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor Intelligence migration version must be greater than zero: %d',
                    $version
                )
            );
        }

        if ($id === '') {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor Intelligence migration %d has an empty ID.',
                    $version
                )
            );
        }

        if (isset($this->migrations[$version])) {
            throw new \RuntimeException(
                sprintf(
                    'Duplicate Visitor Intelligence migration version: %d',
                    $version
                )
            );
        }

        if (isset($this->migrationIds[$id])) {
            throw new \RuntimeException(
                sprintf(
                    'Duplicate Visitor Intelligence migration ID: %s',
                    $id
                )
            );
        }

        $this->migrations[$version] = $migration;
        $this->migrationIds[$id] = $version;

        ksort($this->migrations);

        $this->validateSequence();
    }

    public function migrate(): void
    {
        if ($this->migrations === []) {
            return;
        }

        $this->validateSequence();

        $currentVersion = $this->getCurrentVersion();

        $this->validateCurrentVersion(
            $currentVersion
        );

        foreach (
            $this->migrations as $version => $migration
        ) {
            if ($version <= $currentVersion) {
                continue;
            }

            $this->executeUp(
                $migration
            );

            $this->setCurrentVersion(
                $version
            );

            $currentVersion = $version;
        }
    }

    public function rollback(
        ?int $targetVersion = null
    ): void {
        $this->validateSequence();

        $currentVersion = $this->getCurrentVersion();

        if ($currentVersion === 0) {
            return;
        }

        $targetVersion ??= 0;

        if ($targetVersion < 0) {
            throw new \InvalidArgumentException(
                'Visitor Intelligence target migration version cannot be negative.'
            );
        }

        if ($targetVersion >= $currentVersion) {
            return;
        }

        if (
            $targetVersion > 0
            && !$this->hasMigration($targetVersion)
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor Intelligence target migration version %d is not registered.',
                    $targetVersion
                )
            );
        }

        $this->validateCurrentVersion(
            $currentVersion
        );

        $versions = array_keys(
            $this->migrations
        );

        rsort($versions);

        foreach ($versions as $version) {
            if ($version > $currentVersion) {
                continue;
            }

            if ($version <= $targetVersion) {
                continue;
            }

            $migration = $this->migrations[$version];

            $this->executeDown(
                $migration
            );

            $this->setCurrentVersion(
                $this->getPreviousVersion(
                    $version
                )
            );
        }
    }

    public function getCurrentVersion(): int
    {
        return max(
            0,
            (int) get_option(
                self::OPTION_NAME,
                0
            )
        );
    }

    public function getLatestVersion(): int
    {
        if ($this->migrations === []) {
            return 0;
        }

        return (int) max(
            array_keys(
                $this->migrations
            )
        );
    }

    public function isUpToDate(): bool
    {
        return $this->getCurrentVersion()
            >= $this->getLatestVersion();
    }

    /**
     * @return int[]
     */
    public function getRegisteredVersions(): array
    {
        return array_keys(
            $this->migrations
        );
    }

    /**
     * @return array<int, string>
     */
    public function getRegisteredMigrations(): array
    {
        $result = [];

        foreach (
            $this->migrations as $version => $migration
        ) {
            $result[$version] = $migration->getId();
        }

        return $result;
    }

    public function hasMigration(
        int $version
    ): bool {
        return isset(
            $this->migrations[$version]
        );
    }

    public function hasMigrationId(
        string $id
    ): bool {
        return isset(
            $this->migrationIds[$id]
        );
    }

    private function executeUp(
        MigrationInterface $migration
    ): void {
        if (!$migration->isTransactional()) {
            $migration->up(
                $this->database
            );

            return;
        }

        $this->database->beginTransaction();

        try {
            $migration->up(
                $this->database
            );

            $this->database->commit();
        } catch (\Throwable $exception) {
            try {
                $this->database->rollback();
            } catch (\Throwable $rollbackException) {
                throw new \RuntimeException(
                    'Visitor Intelligence migration failed and database rollback also failed.',
                    0,
                    $exception
                );
            }

            throw $exception;
        }
    }

    private function executeDown(
        MigrationInterface $migration
    ): void {
        if (!$migration->isTransactional()) {
            $migration->down(
                $this->database
            );

            return;
        }

        $this->database->beginTransaction();

        try {
            $migration->down(
                $this->database
            );

            $this->database->commit();
        } catch (\Throwable $exception) {
            try {
                $this->database->rollback();
            } catch (\Throwable $rollbackException) {
                throw new \RuntimeException(
                    'Visitor Intelligence migration rollback failed and database rollback also failed.',
                    0,
                    $exception
                );
            }

            throw $exception;
        }
    }

    private function setCurrentVersion(
        int $version
    ): void {
        if ($version < 0) {
            throw new \InvalidArgumentException(
                'Visitor Intelligence database version cannot be negative.'
            );
        }

        $updated = update_option(
            self::OPTION_NAME,
            $version,
            false
        );

        if (
            !$updated
            && $this->getCurrentVersion() !== $version
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to persist Visitor Intelligence database version: %d',
                    $version
                )
            );
        }
    }

    private function getPreviousVersion(
        int $version
    ): int {
        $previous = 0;

        foreach (
            array_keys(
                $this->migrations
            ) as $registeredVersion
        ) {
            if ($registeredVersion >= $version) {
                break;
            }

            $previous = $registeredVersion;
        }

        return $previous;
    }

    private function validateCurrentVersion(
        int $currentVersion
    ): void {
        $latestVersion = $this->getLatestVersion();

        if ($currentVersion > $latestVersion) {
            throw new \RuntimeException(
                sprintf(
                    'Visitor Intelligence database version %d is newer than the registered migration version %d.',
                    $currentVersion,
                    $latestVersion
                )
            );
        }
    }

    private function validateSequence(): void
    {
        if ($this->migrations === []) {
            return;
        }

        $expected = 1;

        foreach (
            array_keys(
                $this->migrations
            ) as $version
        ) {
            if ($version !== $expected) {
                throw new \RuntimeException(
                    sprintf(
                        'Visitor Intelligence migration sequence is broken. Expected version %d, got %d.',
                        $expected,
                        $version
                    )
                );
            }

            $expected++;
        }
    }
}