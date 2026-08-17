<?php

declare(strict_types=1);

namespace VisitorIntelligence\API;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use VisitorIntelligence\Database\Repositories\VisitorRepository;

defined('ABSPATH') || exit;

final class VisitorController
{
    private const DEFAULT_PAGE = 1;

    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 100;

    private const DEFAULT_SORT = 'last_seen';

    private const DEFAULT_DIRECTION = 'DESC';

    private const ALLOWED_DIRECTIONS = [
        'ASC',
        'DESC',
    ];

    private const ALLOWED_SORTS = [
        'id',
        'visitor_id',
        'first_seen',
        'last_seen',
        'sessions_count',
        'pageviews_count',
        'country',
        'region',
        'city',
        'device_type',
        'browser',
        'os',
        'bot_classification',
    ];

    public function __construct(
        private readonly VisitorRepository $repository
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'vi/v1',
            '/visitors',
            [
                'methods' =>
                    'GET',

                'callback' =>
                    [
                        $this,
                        'getVisitors',
                    ],

                'permission_callback' =>
                    [
                        $this,
                        'canViewVisitors',
                    ],

                'args' =>
                    [
                        'page' => [
                            'required' =>
                                false,

                            'default' =>
                                self::DEFAULT_PAGE,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): int {
                                    return max(
                                        1,
                                        (int) $value
                                    );
                                },

                            'validate_callback' =>
                                static function (
                                    mixed $value
                                ): bool {
                                    if (
                                        is_array($value)
                                        || is_object($value)
                                    ) {
                                        return false;
                                    }

                                    return filter_var(
                                        $value,
                                        FILTER_VALIDATE_INT
                                    ) !== false;
                                },
                        ],

                        'per_page' => [
                            'required' =>
                                false,

                            'default' =>
                                self::DEFAULT_PER_PAGE,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): int {
                                    return max(
                                        1,
                                        min(
                                            self::MAX_PER_PAGE,
                                            (int) $value
                                        )
                                    );
                                },

                            'validate_callback' =>
                                static function (
                                    mixed $value
                                ): bool {
                                    if (
                                        is_array($value)
                                        || is_object($value)
                                    ) {
                                        return false;
                                    }

                                    $perPage =
                                        filter_var(
                                            $value,
                                            FILTER_VALIDATE_INT
                                        );

                                    if (
                                        $perPage === false
                                    ) {
                                        return false;
                                    }

                                    return
                                        $perPage >= 1
                                        && $perPage <=
                                            self::MAX_PER_PAGE;
                                },
                        ],

                        'sort' => [
                            'required' =>
                                false,

                            'default' =>
                                self::DEFAULT_SORT,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): string {
                                    return strtolower(
                                        trim(
                                            (string) $value
                                        )
                                    );
                                },

                            'validate_callback' =>
                                static function (
                                    mixed $value
                                ): bool {
                                    return in_array(
                                        strtolower(
                                            trim(
                                                (string) $value
                                            )
                                        ),
                                        self::ALLOWED_SORTS,
                                        true
                                    );
                                },
                        ],

                        'direction' => [
                            'required' =>
                                false,

                            'default' =>
                                self::DEFAULT_DIRECTION,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): string {
                                    return strtoupper(
                                        trim(
                                            (string) $value
                                        )
                                    );
                                },

                            'validate_callback' =>
                                static function (
                                    mixed $value
                                ): bool {
                                    return in_array(
                                        strtoupper(
                                            trim(
                                                (string) $value
                                            )
                                        ),
                                        self::ALLOWED_DIRECTIONS,
                                        true
                                    );
                                },
                        ],

                        'search' => [
                            'required' =>
                                false,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): string {
                                    return trim(
                                        (string) $value
                                    );
                                },
                        ],

                        'country' => [
                            'required' =>
                                false,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): string {
                                    return trim(
                                        (string) $value
                                    );
                                },
                        ],

                        'region' => [
                            'required' =>
                                false,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): string {
                                    return trim(
                                        (string) $value
                                    );
                                },
                        ],

                        'city' => [
                            'required' =>
                                false,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): string {
                                    return trim(
                                        (string) $value
                                    );
                                },
                        ],

                        'visitor_type' => [
                            'required' =>
                                false,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): string {
                                    return trim(
                                        (string) $value
                                    );
                                },

                            'validate_callback' =>
                                static function (
                                    mixed $value
                                ): bool {
                                    return in_array(
                                        strtolower(
                                            trim(
                                                (string) $value
                                            )
                                        ),
                                        [
                                            '',
                                            'human',
                                            'suspicious',
                                            'bot',
                                            'unknown',
                                        ],
                                        true
                                    );
                                },
                        ],

                        'device' => [
                            'required' =>
                                false,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): string {
                                    return trim(
                                        (string) $value
                                    );
                                },
                        ],

                        'browser' => [
                            'required' =>
                                false,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): string {
                                    return trim(
                                        (string) $value
                                    );
                                },
                        ],

                        'os' => [
                            'required' =>
                                false,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): string {
                                    return trim(
                                        (string) $value
                                    );
                                },
                        ],

                        'last_seen_from' => [
                            'required' =>
                                false,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): string {
                                    return trim(
                                        (string) $value
                                    );
                                },

                            'validate_callback' =>
                                [
                                    $this,
                                    'validateDate',
                                ],
                        ],

                        'last_seen_to' => [
                            'required' =>
                                false,

                            'sanitize_callback' =>
                                static function (
                                    mixed $value
                                ): string {
                                    return trim(
                                        (string) $value
                                    );
                                },

                            'validate_callback' =>
                                [
                                    $this,
                                    'validateDate',
                                ],
                        ],
                    ],
            ]
        );

        register_rest_route(
            'vi/v1',
            '/visitors/filters',
            [
                'methods' =>
                    'GET',

                'callback' =>
                    [
                        $this,
                        'getFilterOptions',
                    ],

                'permission_callback' =>
                    [
                        $this,
                        'canViewVisitors',
                    ],
            ]
        );

        register_rest_route(
            'vi/v1',
            '/visitors/(?P<visitor_id>[A-Za-z0-9_-]+)/pageviews',
            [
                'methods' =>
                    'GET',

                'callback' =>
                    [
                        $this,
                        'getVisitorPageviews',
                    ],

                'permission_callback' =>
                    [
                        $this,
                        'canViewVisitors',
                    ],

                'args' =>
                    [
                        'visitor_id' =>
                            [
                                'required' =>
                                    true,

                                'sanitize_callback' =>
                                    static function (
                                        mixed $value
                                    ): string {
                                        return trim(
                                            (string) $value
                                        );
                                    },

                                'validate_callback' =>
                                    static function (
                                        mixed $value
                                    ): bool {
                                        if (
                                            !is_string($value)
                                            && !is_numeric($value)
                                        ) {
                                            return false;
                                        }

                                        return preg_match(
                                            '/^[A-Za-z0-9_-]+$/',
                                            trim(
                                                (string) $value
                                            )
                                        ) === 1;
                                    },
                            ],
                    ],
            ]
        );

        register_rest_route(
            'vi/v1',
            '/visitors/(?P<visitor_id>[A-Za-z0-9_-]+)',
            [
                'methods' =>
                    'GET',

                'callback' =>
                    [
                        $this,
                        'getVisitor',
                    ],

                'permission_callback' =>
                    [
                        $this,
                        'canViewVisitors',
                    ],

                'args' =>
                    [
                        'visitor_id' =>
                            [
                                'required' =>
                                    true,

                                'sanitize_callback' =>
                                    static function (
                                        mixed $value
                                    ): string {
                                        return trim(
                                            (string) $value
                                        );
                                    },

                                'validate_callback' =>
                                    static function (
                                        mixed $value
                                    ): bool {
                                        if (
                                            !is_string($value)
                                            && !is_numeric($value)
                                        ) {
                                            return false;
                                        }

                                        return preg_match(
                                            '/^[A-Za-z0-9_-]+$/',
                                            trim(
                                                (string) $value
                                            )
                                        ) === 1;
                                    },
                            ],
                    ],
            ]
        );
    }

    public function getVisitors(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        try {
            $page =
                $this->getPositiveInt(
                    $request->get_param(
                        'page'
                    ),
                    self::DEFAULT_PAGE
                );

            $perPage =
                $this->getPositiveInt(
                    $request->get_param(
                        'per_page'
                    ),
                    self::DEFAULT_PER_PAGE
                );

            $perPage =
                min(
                    self::MAX_PER_PAGE,
                    $perPage
                );

            $sort =
                strtolower(
                    trim(
                        (string) (
                            $request->get_param(
                                'sort'
                            )
                            ?? self::DEFAULT_SORT
                        )
                    )
                );

            if (
                !in_array(
                    $sort,
                    self::ALLOWED_SORTS,
                    true
                )
            ) {
                $sort =
                    self::DEFAULT_SORT;
            }

            $direction =
                strtoupper(
                    trim(
                        (string) (
                            $request->get_param(
                                'direction'
                            )
                            ?? self::DEFAULT_DIRECTION
                        )
                    )
                );

            if (
                !in_array(
                    $direction,
                    self::ALLOWED_DIRECTIONS,
                    true
                )
            ) {
                $direction =
                    self::DEFAULT_DIRECTION;
            }

            $filters =
                $this->buildFilters(
                    $request
                );

            $result =
                $this->repository->paginate(
                    $filters,
                    $sort,
                    $direction,
                    $page,
                    $perPage
                );

            return new WP_REST_Response(
                [
                    'items' =>
                        $result['items'],

                    'count' =>
                        count(
                            $result['items']
                        ),

                    'total' =>
                        $result['total'],

                    'page' =>
                        $result['page'],

                    'per_page' =>
                        $result['per_page'],

                    'total_pages' =>
                        $result['total_pages'],

                    'sort' =>
                        $sort,

                    'direction' =>
                        $direction,

                    'filters' =>
                        $filters,
                ],
                200
            );
        } catch (
            \Throwable $exception
        ) {
            do_action(
                'vi_visitors_error',
                $exception,
                [
                    'action' =>
                        'list',

                    'request' =>
                        $request->get_params(),
                ]
            );

            return new WP_Error(
                'vi_visitors_unavailable',
                'Unable to load visitor data.',
                [
                    'status' =>
                        500,
                ]
            );
        }
    }

    public function getFilterOptions(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        try {
            $filters =
                $this->buildFilters(
                    $request
                );

            return new WP_REST_Response(
                [
                    'filters' =>
                        $this->repository->getFilterOptions(
                            $filters
                        ),
                ],
                200
            );
        } catch (
            \Throwable $exception
        ) {
            do_action(
                'vi_visitors_error',
                $exception,
                [
                    'action' =>
                        'filters',
                ]
            );

            return new WP_Error(
                'vi_visitors_filters_unavailable',
                'Unable to load visitor filter options.',
                [
                    'status' =>
                        500,
                ]
            );
        }
    }

    public function getVisitorPageviews(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $visitorId =
            trim(
                (string) (
                    $request->get_param(
                        'visitor_id'
                    )
                    ?? ''
                )
            );

        if (
            $visitorId === ''
        ) {
            return new WP_Error(
                'vi_invalid_visitor_id',
                'Visitor ID is required.',
                [
                    'status' =>
                        400,
                ]
            );
        }

        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $visitorId
            ) !== 1
        ) {
            return new WP_Error(
                'vi_invalid_visitor_id',
                'Visitor ID must be a valid UUID.',
                [
                    'status' =>
                        400,
                ]
            );
        }

        try {
            $visitor =
                $this->repository->findById(
                    $visitorId
                );

            if (
                $visitor === null
            ) {
                return new WP_Error(
                    'vi_visitor_not_found',
                    'Visitor not found.',
                    [
                        'status' =>
                            404,
                    ]
                );
            }

            global $wpdb;

            if (
                !$wpdb instanceof \wpdb
            ) {
                throw new \RuntimeException(
                    'WordPress database object is not available.'
                );
            }

            $table =
                $wpdb->prefix
                . 'vi_pageviews';

            $sql =
                "SELECT
                    pageview_id,
                    occurred_at,
                    url,
                    sequence_number
                 FROM {$table}
                 WHERE visitor_id = %s
                 ORDER BY occurred_at ASC, sequence_number ASC
                 LIMIT 1000";

            $prepared =
                $wpdb->prepare(
                    $sql,
                    $visitorId
                );

            if (
                !is_string($prepared)
            ) {
                throw new \RuntimeException(
                    'Unable to prepare pageviews query.'
                );
            }

            $pageviews =
                $wpdb->get_results(
                    $prepared,
                    ARRAY_A
                );

            if (
                $pageviews === null
            ) {
                $error =
                    trim(
                        (string) $wpdb->last_error
                    );

                throw new \RuntimeException(
                    $error !== ''
                        ? $error
                        : 'Unable to query visitor pageviews.'
                );
            }

            $items = [];

            foreach (
                $pageviews as $pageview
            ) {
                if (
                    !is_array($pageview)
                ) {
                    continue;
                }

                $items[] = [
                    'pageview_id' =>
                        (string) (
                            $pageview['pageview_id']
                            ?? ''
                        ),

                    'occurred_at' =>
                        (string) (
                            $pageview['occurred_at']
                            ?? ''
                        ),

                    'url' =>
                        (string) (
                            $pageview['url']
                            ?? ''
                        ),

                    'sequence_number' =>
                        isset(
                            $pageview[
                                'sequence_number'
                            ]
                        )
                            ? (int) $pageview[
                                'sequence_number'
                            ]
                            : null,
                ];
            }

            return new WP_REST_Response(
                [
                    'visitor_id' =>
                        $visitorId,

                    'count' =>
                        count($items),

                    'pageviews' =>
                        $items,
                ],
                200
            );
        } catch (
            \Throwable $exception
        ) {
            do_action(
                'vi_visitors_error',
                $exception,
                [
                    'action' =>
                        'pageviews',

                    'visitor_id' =>
                        $visitorId,
                ]
            );

            return new WP_Error(
                'vi_visitor_pageviews_unavailable',
                'Unable to load visitor pageviews.',
                [
                    'status' =>
                        500,
                ]
            );
        }
    }

    public function getVisitor(
        WP_REST_Request $request
    ): WP_REST_Response|WP_Error {
        $visitorId =
            trim(
                (string) (
                    $request->get_param(
                        'visitor_id'
                    )
                    ?? ''
                )
            );

        if (
            $visitorId === ''
        ) {
            return new WP_Error(
                'vi_invalid_visitor_id',
                'Visitor ID is required.',
                [
                    'status' =>
                        400,
                ]
            );
        }

        try {
            $visitor =
                $this->repository->getSummary(
                    $visitorId
                );

            if (
                $visitor === null
            ) {
                return new WP_Error(
                    'vi_visitor_not_found',
                    'Visitor not found.',
                    [
                        'status' =>
                            404,
                    ]
                );
            }

            return new WP_REST_Response(
                [
                    'visitor' =>
                        $visitor,
                ],
                200
            );
        } catch (
            \Throwable $exception
        ) {
            do_action(
                'vi_visitors_error',
                $exception,
                [
                    'action' =>
                        'single',

                    'visitor_id' =>
                        $visitorId,
                ]
            );

            return new WP_Error(
                'vi_visitor_unavailable',
                'Unable to load visitor data.',
                [
                    'status' =>
                        500,
                ]
            );
        }
    }

    public function canViewVisitors(): bool
    {
        return current_user_can(
            'manage_options'
        );
    }

    public function validateDate(
        mixed $value
    ): bool {
        if (
            !is_string($value)
        ) {
            return false;
        }

        return preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            trim($value)
        ) === 1
            && $this->isValidCalendarDate(
                trim($value)
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilters(
        WP_REST_Request $request
    ): array {
        $filters = [];

        $search =
            trim(
                (string) (
                    $request->get_param(
                        'search'
                    )
                    ?? ''
                )
            );

        if ($search !== '') {
            $filters['search'] =
                $search;
        }

        $country =
            trim(
                (string) (
                    $request->get_param(
                        'country'
                    )
                    ?? ''
                )
            );

        if ($country !== '') {
            $filters['country_code'] =
                $country;
        }

        $region =
            trim(
                (string) (
                    $request->get_param(
                        'region'
                    )
                    ?? ''
                )
            );

        if ($region !== '') {
            $filters['region_name'] =
                $region;
        }

        $city =
            trim(
                (string) (
                    $request->get_param(
                        'city'
                    )
                    ?? ''
                )
            );

        if ($city !== '') {
            $filters['city'] =
                $city;
        }

        $visitorType =
            strtolower(
                trim(
                    (string) (
                        $request->get_param(
                            'visitor_type'
                        )
                        ?? ''
                    )
                )
            );

        if ($visitorType !== '') {
            $filters['bot_classification'] =
                $visitorType;
        }

        $device =
            trim(
                (string) (
                    $request->get_param(
                        'device'
                    )
                    ?? ''
                )
            );

        if ($device !== '') {
            $filters['device_type'] =
                $device;
        }

        $browser =
            trim(
                (string) (
                    $request->get_param(
                        'browser'
                    )
                    ?? ''
                )
            );

        if ($browser !== '') {
            $filters['browser'] =
                $browser;
        }

        $os =
            trim(
                (string) (
                    $request->get_param(
                        'os'
                    )
                    ?? ''
                )
            );

        if ($os !== '') {
            $filters['os'] =
                $os;
        }

        $from =
            trim(
                (string) (
                    $request->get_param(
                        'last_seen_from'
                    )
                    ?? ''
                )
            );

        if ($from !== '') {
            $filters['last_seen_from'] =
                $from . ' 00:00:00';
        }

        $to =
            trim(
                (string) (
                    $request->get_param(
                        'last_seen_to'
                    )
                    ?? ''
                )
            );

        if ($to !== '') {
            $filters['last_seen_to'] =
                $to . ' 23:59:59';
        }

        return $filters;
    }

    private function getPositiveInt(
        mixed $value,
        int $default
    ): int {
        if (
            is_array($value)
            || is_object($value)
        ) {
            return $default;
        }

        $parsed =
            filter_var(
                $value,
                FILTER_VALIDATE_INT
            );

        if (
            $parsed === false
            || $parsed < 1
        ) {
            return $default;
        }

        return (int) $parsed;
    }

    private function isValidCalendarDate(
        string $date
    ): bool {
        $parts =
            explode(
                '-',
                $date
            );

        if (
            count($parts) !== 3
        ) {
            return false;
        }

        return checkdate(
            (int) $parts[1],
            (int) $parts[2],
            (int) $parts[0]
        );
    }
}