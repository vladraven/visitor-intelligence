<?php

declare(strict_types=1);

namespace VisitorIntelligence\Integrations\GravityForms;

use VisitorIntelligence\Identity\VisitorManager;

defined('ABSPATH') || exit;

final class GravityFormsIntegration
{
    private const VISITOR_META_KEY = 'vi_visitor_id';

    public function __construct(
        private readonly VisitorManager $visitors,
        private readonly GravityFormsRepository $repository
    ) {
    }

    public function register(): void
    {
        if (
            !class_exists('GFAPI')
        ) {
            return;
        }

        add_action(
            'gform_after_submission',
            [
                $this,
                'handleSubmission',
            ],
            10,
            2
        );
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $form
     */
    public function handleSubmission(
        array $entry,
        array $form
    ): void {
        if (
            !isset(
                $entry['id']
            )
        ) {
            return;
        }

        $entryId =
            (int) $entry['id'];

        if (
            $entryId < 1
        ) {
            return;
        }

        try {
            $visitorId =
                $this->visitors->resolveVisitorId();

            if (
                $visitorId === ''
            ) {
                return;
            }

            $this->repository->attachVisitorToEntry(
                $entryId,
                $visitorId
            );
        } catch (
            \Throwable $exception
        ) {
            do_action(
                'vi_gravity_forms_error',
                $exception,
                $entry,
                $form
            );
        }
    }
}