<?php

declare(strict_types=1);

namespace VisitorIntelligence\Integrations\GravityForms;

defined('ABSPATH') || exit;

final class GravityFormsRepository
{
    private const VISITOR_META_KEY = 'vi_visitor_id';

    public function isAvailable(): bool
    {
        return class_exists(
            'GFAPI'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFormsByVisitorId(
        string $visitorId
    ): array {
        $visitorId =
            trim(
                $visitorId
            );

        if (
            $visitorId === ''
            || !$this->isValidVisitorId(
                $visitorId
            )
            || !$this->isAvailable()
        ) {
            return [];
        }

        $searchCriteria = [
            'status' =>
                'active',

            'field_filters' =>
                [
                    [
                        'key' =>
                            'meta.' . self::VISITOR_META_KEY,

                        'value' =>
                            $visitorId,

                        'operator' =>
                            'is',
                    ],
                ],
        ];

        $sorting = [
            'key' =>
                'date_created',

            'direction' =>
                'DESC',

            'is_numeric' =>
                false,
        ];

        $paging = [
            'offset' =>
                0,

            'page_size' =>
                100,
        ];

        $entries =
            \GFAPI::get_entries(
                0,
                $searchCriteria,
                $sorting,
                $paging
            );

        if (
            is_wp_error(
                $entries
            )
            || !is_array(
                $entries
            )
        ) {
            return [];
        }

        $result = [];

        foreach (
            $entries as $entry
        ) {
            if (
                !is_array(
                    $entry
                )
            ) {
                continue;
            }

            $formId =
                isset(
                    $entry['form_id']
                )
                    ? (int) $entry['form_id']
                    : 0;

            $entryId =
                isset(
                    $entry['id']
                )
                    ? (int) $entry['id']
                    : 0;

            if (
                $formId < 1
                || $entryId < 1
            ) {
                continue;
            }

            $form =
                \GFAPI::get_form(
                    $formId
                );

            $formTitle =
                '';

            if (
                is_array(
                    $form
                )
                && isset(
                    $form['title']
                )
            ) {
                $formTitle =
                    (string) $form['title'];
            }

            if (
                $formTitle === ''
            ) {
                $formTitle =
                    sprintf(
                        'Form #%d',
                        $formId
                    );
            }

            $result[] = [
                'entry_id' =>
                    $entryId,

                'form_id' =>
                    $formId,

                'form_title' =>
                    $formTitle,

                'date_created' =>
                    isset(
                        $entry['date_created']
                    )
                        ? (string) $entry['date_created']
                        : null,

                'status' =>
                    isset(
                        $entry['status']
                    )
                        ? (string) $entry['status']
                        : null,
            ];
        }

        return $result;
    }

    public function attachVisitorToEntry(
        int $entryId,
        string $visitorId
    ): bool {
        if (
            $entryId < 1
            || !$this->isValidVisitorId(
                $visitorId
            )
            || !$this->isAvailable()
        ) {
            return false;
        }

        $result =
            \gform_update_meta(
                $entryId,
                self::VISITOR_META_KEY,
                $visitorId
            );

        return $result !== false;
    }

    private function isValidVisitorId(
        string $visitorId
    ): bool {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $visitorId
        ) === 1;
    }
}