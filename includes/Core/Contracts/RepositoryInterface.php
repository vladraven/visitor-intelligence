<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core\Contracts;

defined('ABSPATH') || exit;

interface RepositoryInterface
{
    /**
     * Find a record by its canonical identifier.
     *
     * @param string $id
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array;

    /**
     * Check if a record exists by its canonical identifier.
     *
     * @param string $id
     */
    public function existsById(string $id): bool;

    /**
     * Persist a record in the storage.
     *
     * @param array<string, mixed> $data
     * @return string Canonical identifier
     */
    public function persist(array $data): string;
}