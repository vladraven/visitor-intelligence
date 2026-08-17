<?php

declare(strict_types=1);

namespace VisitorIntelligence\Identity;

use VisitorIntelligence\Database\Repositories\VisitorRepository;

defined('ABSPATH') || exit;

final class VisitorManager
{
    public function __construct(
        private readonly VisitorRepository $repository,
        private readonly VisitorCookie $cookie
    ) {
    }

    public function resolveVisitorId(): string
    {
        $existing =
            $this->cookie->get();

        if ($existing !== null) {
            if (
                $this->repository->existsById(
                    $existing
                )
            ) {
                $this->cookie->set(
                    $existing
                );

                return $existing;
            }
        }

        $id =
            wp_generate_uuid4();

        if (
            !is_string($id)
            || $id === ''
        ) {
            throw new \RuntimeException(
                'Unable to generate visitor ID.'
            );
        }

        $this->repository->persist(
            [
                'visitor_id' =>
                    $id,
            ]
        );

        if (
            !$this->repository->existsById(
                $id
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Visitor was not persisted: %s',
                    $id
                )
            );
        }

        $this->cookie->set(
            $id
        );

        return $id;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function touch(
        string $visitorId,
        array $meta = []
    ): void {
        $visitorId =
            trim(
                $visitorId
            );

        if ($visitorId === '') {
            throw new \InvalidArgumentException(
                'Visitor ID cannot be empty.'
            );
        }

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

        if (
            !$this->repository->existsById(
                $visitorId
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor does not exist: %s',
                    $visitorId
                )
            );
        }

        $now =
            gmdate(
                'Y-m-d H:i:s'
            );

        $data =
            $meta;

        $data['visitor_id'] =
            $visitorId;

        /*
         * first_seen is immutable and must never be
         * rewritten by a touch operation.
         *
         * last_seen is advanced by the repository.
         */
        $data['last_seen'] =
            $now;

        $this->repository->persist(
            $data
        );
    }
}