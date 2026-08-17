<?php

declare(strict_types=1);

namespace VisitorIntelligence\Database;

defined('ABSPATH') || exit;

interface MigrationInterface
{
    /**
     * Return the monotonically increasing migration version.
     */
    public function getVersion(): int;

    /**
     * Return a stable migration identifier.
     *
     * Example:
     * vi_001_initial
     */
    public function getId(): string;

    /**
     * Apply the migration.
     */
    public function up(Database $database): void;

    /**
     * Reverse the migration.
     */
    public function down(Database $database): void;

    /**
     * Whether the migration may be executed inside
     * a database transaction.
     *
     * MySQL DDL is not generally transactional, therefore
     * schema migrations should normally return false.
     */
    public function isTransactional(): bool;
}