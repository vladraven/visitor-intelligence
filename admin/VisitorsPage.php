<?php

declare(strict_types=1);

namespace VisitorIntelligence\Admin;

defined('ABSPATH') || exit;

final class VisitorsPage
{
    private const CAPABILITY = 'manage_options';

    public function register(): void
    {
        add_submenu_page(
            'visitor-intelligence',
            __('Visitors', 'visitor-intelligence'),
            __('Visitors', 'visitor-intelligence'),
            self::CAPABILITY,
            'visitor-intelligence-visitors',
            [$this, 'render']
        );

        add_action(
            'admin_enqueue_scripts',
            [$this, 'enqueueAssets']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (
            $hook !==
            'visitor-intelligence_page_visitor-intelligence-visitors'
        ) {
            return;
        }

        wp_enqueue_style(
            'vi-admin-css',
            VI_URL . 'admin/assets/admin.css',
            [],
            VI_VERSION
        );

        wp_enqueue_script(
            'vi-visitors-js',
            VI_URL . 'admin/assets/visitors.js',
            [],
            VI_VERSION,
            true
        );

        wp_localize_script(
            'vi-visitors-js',
            'viVisitors',
            [
                'apiEndpoint' =>
                    rest_url(
                        'vi/v1/visitors'
                    ),

                'nonce' =>
                    wp_create_nonce(
                        'wp_rest'
                    ),

                'strings' => [
                    'loading' =>
                        __(
                            'Loading visitors...',
                            'visitor-intelligence'
                        ),

                    'empty' =>
                        __(
                            'No visitors found.',
                            'visitor-intelligence'
                        ),

                    'error' =>
                        __(
                            'Unable to load visitors.',
                            'visitor-intelligence'
                        ),

                    'notAvailable' =>
                        __(
                            'N/A',
                            'visitor-intelligence'
                        ),
                ],
            ]
        );
    }

    public function render(): void
    {
        if (
            !current_user_can(
                self::CAPABILITY
            )
        ) {
            wp_die(
                esc_html__(
                    'You do not have sufficient permissions to access this page.',
                    'visitor-intelligence'
                )
            );
        }
        ?>

        <div class="wrap vi-visitors-wrap">

            <div class="vi-visitors-header">

                <h1 class="wp-heading-inline">
                    <?php
                    echo esc_html__(
                        'Visitors',
                        'visitor-intelligence'
                    );
                    ?>
                </h1>

                <div class="vi-visitors-actions">

                    <button
                        type="button"
                        class="button button-secondary"
                        id="vi-visitors-refresh"
                    >
                        <?php
                        echo esc_html__(
                            'Refresh',
                            'visitor-intelligence'
                        );
                        ?>
                    </button>

                    <button
                        type="button"
                        class="button button-secondary"
                        id="vi-visitors-export"
                    >
                        <?php
                        echo esc_html__(
                            'Export CSV',
                            'visitor-intelligence'
                        );
                        ?>
                    </button>

                </div>

            </div>

            <hr class="wp-header-end">

            <div
                id="vi-visitors-status"
                class="notice"
                style="display:none;"
            ></div>

            <div
                id="vi-visitors-loading"
                class="vi-visitors-loading"
                style="display:none;"
            >
                <?php
                echo esc_html__(
                    'Loading visitors...',
                    'visitor-intelligence'
                );
                ?>
            </div>

            <div
                id="vi-visitors-empty"
                class="vi-visitors-empty"
                style="display:none;"
            >
                <?php
                echo esc_html__(
                    'No visitors found.',
                    'visitor-intelligence'
                );
                ?>
            </div>

            <div
                id="vi-visitors-filters"
                class="vi-visitors-filters"
            >

                <div class="vi-visitors-filter-grid">

                    <div class="vi-filter-field vi-filter-search">
                        <label
                            for="vi-filter-search"
                        >
                            <?php
                            echo esc_html__(
                                'Search',
                                'visitor-intelligence'
                            );
                            ?>
                        </label>

                        <input
                            type="search"
                            id="vi-filter-search"
                            placeholder="<?php
                            echo esc_attr__(
                                'Visitor ID, country, city...',
                                'visitor-intelligence'
                            );
                            ?>"
                        >
                    </div>

                    <div class="vi-filter-field">
                        <label
                            for="vi-filter-country"
                        >
                            <?php
                            echo esc_html__(
                                'Country',
                                'visitor-intelligence'
                            );
                            ?>
                        </label>

                        <select
                            id="vi-filter-country"
                        >
                            <option value="">
                                <?php
                                echo esc_html__(
                                    'All countries',
                                    'visitor-intelligence'
                                );
                                ?>
                            </option>
                        </select>
                    </div>

                    <div class="vi-filter-field">
                        <label
                            for="vi-filter-region"
                        >
                            <?php
                            echo esc_html__(
                                'Province / Region',
                                'visitor-intelligence'
                            );
                            ?>
                        </label>

                        <select
                            id="vi-filter-region"
                        >
                            <option value="">
                                <?php
                                echo esc_html__(
                                    'All provinces / regions',
                                    'visitor-intelligence'
                                );
                                ?>
                            </option>
                        </select>
                    </div>

                    <div class="vi-filter-field">
                        <label
                            for="vi-filter-city"
                        >
                            <?php
                            echo esc_html__(
                                'City',
                                'visitor-intelligence'
                            );
                            ?>
                        </label>

                        <select
                            id="vi-filter-city"
                        >
                            <option value="">
                                <?php
                                echo esc_html__(
                                    'All cities',
                                    'visitor-intelligence'
                                );
                                ?>
                            </option>
                        </select>
                    </div>

                    <div class="vi-filter-field">
                        <label
                            for="vi-filter-type"
                        >
                            <?php
                            echo esc_html__(
                                'Visitor Type',
                                'visitor-intelligence'
                            );
                            ?>
                        </label>

                        <select
                            id="vi-filter-type"
                        >
                            <option value="">
                                <?php
                                echo esc_html__(
                                    'All types',
                                    'visitor-intelligence'
                                );
                                ?>
                            </option>

                            <option value="human">
                                <?php
                                echo esc_html__(
                                    'Human',
                                    'visitor-intelligence'
                                );
                                ?>
                            </option>

                            <option value="suspicious">
                                <?php
                                echo esc_html__(
                                    'Suspicious',
                                    'visitor-intelligence'
                                );
                                ?>
                            </option>

                            <option value="bot">
                                <?php
                                echo esc_html__(
                                    'Bot',
                                    'visitor-intelligence'
                                );
                                ?>
                            </option>

                            <option value="unknown">
                                <?php
                                echo esc_html__(
                                    'Unknown',
                                    'visitor-intelligence'
                                );
                                ?>
                            </option>
                        </select>
                    </div>

                    <div class="vi-filter-field">
                        <label
                            for="vi-filter-device"
                        >
                            <?php
                            echo esc_html__(
                                'Device',
                                'visitor-intelligence'
                            );
                            ?>
                        </label>

                        <select
                            id="vi-filter-device"
                        >
                            <option value="">
                                <?php
                                echo esc_html__(
                                    'All devices',
                                    'visitor-intelligence'
                                );
                                ?>
                            </option>
                        </select>
                    </div>

                    <div class="vi-filter-field">
                        <label
                            for="vi-filter-browser"
                        >
                            <?php
                            echo esc_html__(
                                'Browser',
                                'visitor-intelligence'
                            );
                            ?>
                        </label>

                        <select
                            id="vi-filter-browser"
                        >
                            <option value="">
                                <?php
                                echo esc_html__(
                                    'All browsers',
                                    'visitor-intelligence'
                                );
                                ?>
                            </option>
                        </select>
                    </div>

                    <div class="vi-filter-field">
                        <label
                            for="vi-filter-os"
                        >
                            <?php
                            echo esc_html__(
                                'Operating System',
                                'visitor-intelligence'
                            );
                            ?>
                        </label>

                        <select
                            id="vi-filter-os"
                        >
                            <option value="">
                                <?php
                                echo esc_html__(
                                    'All operating systems',
                                    'visitor-intelligence'
                                );
                                ?>
                            </option>
                        </select>
                    </div>

                    <div class="vi-filter-field">
                        <label
                            for="vi-filter-from"
                        >
                            <?php
                            echo esc_html__(
                                'Last Seen From',
                                'visitor-intelligence'
                            );
                            ?>
                        </label>

                        <input
                            type="date"
                            id="vi-filter-from"
                        >
                    </div>

                    <div class="vi-filter-field">
                        <label
                            for="vi-filter-to"
                        >
                            <?php
                            echo esc_html__(
                                'Last Seen To',
                                'visitor-intelligence'
                            );
                            ?>
                        </label>

                        <input
                            type="date"
                            id="vi-filter-to"
                        >
                    </div>

                </div>

                <div class="vi-filter-actions">

                    <button
                        type="button"
                        class="button button-primary"
                        id="vi-visitors-apply-filters"
                    >
                        <?php
                        echo esc_html__(
                            'Apply Filters',
                            'visitor-intelligence'
                        );
                        ?>
                    </button>

                    <button
                        type="button"
                        class="button"
                        id="vi-visitors-reset-filters"
                    >
                        <?php
                        echo esc_html__(
                            'Reset',
                            'visitor-intelligence'
                        );
                        ?>
                    </button>

                </div>

            </div>

            <div
                id="vi-visitors-table-container"
                class="vi-visitors-table-container"
                style="display:none;"
            >

                <div
                    id="vi-visitors-top-scroll"
                    class="vi-visitors-top-scroll"
                >
                    <div
                        id="vi-visitors-top-scroll-inner"
                        class="vi-visitors-top-scroll-inner"
                    ></div>
                </div>

                <div class="vi-visitors-table-scroll">

                    <table
                        id="vi-visitors-table"
                        class="widefat striped vi-visitors-table"
                    >

                        <thead>

                            <tr>

                                <th
                                    data-sort="id"
                                    class="vi-sortable"
                                >
                                    ID
                                    <span class="vi-sort-indicator"></span>
                                </th>

                                <th
                                    data-sort="visitor_id"
                                    class="vi-sortable vi-column-hidden"
                                >
                                    Visitor ID
                                    <span class="vi-sort-indicator"></span>
                                </th>

                                <th
                                    data-sort="last_seen"
                                    class="vi-sortable"
                                >
                                    Last Seen
                                    <span class="vi-sort-indicator"></span>
                                </th>

                                <th
                                    data-sort="first_seen"
                                    class="vi-sortable"
                                >
                                    First Seen
                                    <span class="vi-sort-indicator"></span>
                                </th>

                                <th
                                    data-sort="country"
                                    class="vi-sortable"
                                >
                                    Country
                                    <span class="vi-sort-indicator"></span>
                                </th>

                                <th
                                    data-sort="region"
                                    class="vi-sortable"
                                >
                                    Province / Region
                                    <span class="vi-sort-indicator"></span>
                                </th>

                                <th
                                    data-sort="city"
                                    class="vi-sortable"
                                >
                                    City
                                    <span class="vi-sort-indicator"></span>
                                </th>

                                <th
                                    data-sort="device_type"
                                    class="vi-sortable"
                                >
                                    Device
                                    <span class="vi-sort-indicator"></span>
                                </th>

                                <th
                                    data-sort="browser"
                                    class="vi-sortable"
                                >
                                    Browser
                                    <span class="vi-sort-indicator"></span>
                                </th>

                                <th>
                                    Browser Version
                                </th>

                                <th
                                    data-sort="os"
                                    class="vi-sortable"
                                >
                                    OS
                                    <span class="vi-sort-indicator"></span>
                                </th>

                                <th>
                                    OS Version
                                </th>

                                <th
                                    data-sort="sessions_count"
                                    class="vi-sortable"
                                >
                                    Sessions
                                    <span class="vi-sort-indicator"></span>
                                </th>

                                <th
                                    data-sort="pageviews_count"
                                    class="vi-sortable"
                                >
                                    Pageviews
                                    <span class="vi-sort-indicator"></span>
                                </th>

                                <th
                                    data-sort="bot_classification"
                                    class="vi-sortable"
                                >
                                    Type
                                    <span class="vi-sort-indicator"></span>
                                </th>

                                <th
                                    class="vi-column-hidden"
                                >
                                    Coordinates
                                </th>

                            </tr>

                        </thead>

                        <tbody
                            id="vi-visitors-table-body"
                        ></tbody>

                    </table>

                </div>

            </div>

            <div
                id="vi-visitors-pagination"
                class="vi-visitors-pagination"
                style="display:none;"
            >

                <button
                    type="button"
                    class="button"
                    id="vi-visitors-prev"
                >
                    <?php
                    echo esc_html__(
                        'Previous',
                        'visitor-intelligence'
                    );
                    ?>
                </button>

                <span
                    id="vi-visitors-page-info"
                    class="vi-visitors-page-info"
                ></span>

                <button
                    type="button"
                    class="button"
                    id="vi-visitors-next"
                >
                    <?php
                    echo esc_html__(
                        'Next',
                        'visitor-intelligence'
                    );
                    ?>
                </button>

            </div>

            <div
                id="vi-visitor-details"
                class="vi-visitor-details"
                style="display:none;"
            >

                <hr>

                <h2>
                    <?php
                    echo esc_html__(
                        'Visitor Details',
                        'visitor-intelligence'
                    );
                    ?>
                </h2>

                <div
                    id="vi-visitor-details-content"
                ></div>

            </div>

        </div>

        <style>
            .vi-visitors-wrap {
                max-width:100%;
            }

            .vi-visitors-header {
                display:flex;
                align-items:center;
                gap:12px;
                flex-wrap:wrap;
            }

            .vi-visitors-header h1 {
                margin-right:auto;
            }

            .vi-visitors-actions {
                display:flex;
                align-items:center;
                gap:8px;
            }

            .vi-visitors-filters {
                background:#fff;
                border:1px solid #dcdcde;
                padding:16px;
                margin:16px 0;
                box-sizing:border-box;
            }

            .vi-visitors-filter-grid {
                display:grid;
                grid-template-columns:
                    repeat(
                        auto-fit,
                        minmax(
                            190px,
                            1fr
                        )
                    );
                gap:14px;
                align-items:end;
            }

            .vi-filter-field {
                min-width:0;
            }

            .vi-filter-field label {
                display:block;
                font-weight:600;
                margin:0 0 5px;
            }

            .vi-filter-field input,
            .vi-filter-field select {
                width:100%;
                min-height:36px;
                box-sizing:border-box;
            }

            .vi-filter-actions {
                display:flex;
                align-items:center;
                gap:8px;
                margin-top:14px;
            }

            .vi-visitors-table-container {
                width:100%;
                max-width:100%;
                position:relative;
            }

            .vi-visitors-top-scroll {
                width:100%;
                height:18px;
                overflow-x:auto;
                overflow-y:hidden;
                position:sticky;
                top:32px;
                z-index:20;
                background:#fff;
                border:1px solid #dcdcde;
                border-bottom:0;
                box-sizing:border-box;
            }

            .vi-visitors-top-scroll-inner {
                height:1px;
            }

            .vi-visitors-table-scroll {
                width:100%;
                max-width:100%;
                overflow-x:auto;
                overflow-y:visible;
                border:1px solid #dcdcde;
                border-top:0;
                box-sizing:border-box;
            }

            .vi-visitors-table {
                table-layout:auto;
                width:100%;
                min-width:100%;
                max-width:none;
                margin:0;
                border-collapse:collapse;
            }

            .vi-visitors-table th,
            .vi-visitors-table td {
                box-sizing:border-box;
                vertical-align:middle;
                padding:9px 10px;
                white-space:normal;
                overflow-wrap:anywhere;
                word-break:normal;
            }

            .vi-visitors-table th {
                font-weight:600;
                background:#f6f7f7;
                position:relative;
                white-space:normal;
            }

            .vi-visitors-table td {
                height:44px;
            }

            .vi-visitors-table td:nth-child(1),
            .vi-visitors-table td:nth-child(3),
            .vi-visitors-table td:nth-child(4),
            .vi-visitors-table td:nth-child(8),
            .vi-visitors-table td:nth-child(9),
            .vi-visitors-table td:nth-child(10),
            .vi-visitors-table td:nth-child(11),
            .vi-visitors-table td:nth-child(12),
            .vi-visitors-table td:nth-child(13),
            .vi-visitors-table td:nth-child(14),
            .vi-visitors-table td:nth-child(15) {
                white-space:normal;
                overflow-wrap:normal;
            }

            .vi-visitors-table td:nth-child(6),
            .vi-visitors-table td:nth-child(7) {
                white-space:normal;
                overflow-wrap:anywhere;
                word-break:normal;
            }

            .vi-visitors-table .vi-sortable {
                cursor:pointer;
                user-select:none;
            }

            .vi-visitors-table .vi-sortable:hover {
                background:#f0f0f1;
            }

            .vi-sort-indicator {
                display:inline-block;
                width:14px;
                margin-left:4px;
                font-size:12px;
                font-weight:700;
            }
            .vi-visitors-table th:nth-child(2),
            .vi-visitors-table td:nth-child(2),
            .vi-visitors-table th:nth-child(16),
            .vi-visitors-table td:nth-child(16) {
                display:none !important;
            }

            .vi-visitors-table td:nth-child(3),
            .vi-visitors-table td:nth-child(4) {
                font-variant-numeric:tabular-nums;
            }

            .vi-visitors-table .vi-visitor-link {
                max-width:100%;
                overflow-wrap:anywhere;
                word-break:normal;
                white-space:normal;
                display:block;
                text-align:left;
                padding:0;
                margin:0;
            }

            .vi-visitors-pagination {
                display:flex;
                align-items:center;
                justify-content:flex-start;
                gap:8px;
                margin:16px 0;
            }

            .vi-visitors-page-info {
                min-width:180px;
                text-align:center;
            }

            .vi-visitors-loading {
                margin:16px 0;
            }

            .vi-visitors-empty {
                margin:16px 0;
            }

            .vi-visitor-details {
                margin-top:24px;
            }

            @media screen and (max-width:782px) {

                .vi-visitors-header {
                    align-items:flex-start;
                }

                .vi-visitors-header h1 {
                    width:100%;
                    margin:0;
                }

                .vi-visitors-actions {
                    width:100%;
                }

                .vi-visitors-actions .button {
                    flex:1;
                }

                .vi-visitors-filter-grid {
                    grid-template-columns:1fr;
                }

                .vi-visitors-top-scroll {
                    top:46px;
                }

            }
        </style>

        <script>
            (function () {
                const tableContainer =
                    document.getElementById(
                        'vi-visitors-table-container'
                    );

                const topScroll =
                    document.getElementById(
                        'vi-visitors-top-scroll'
                    );

                const topScrollInner =
                    document.getElementById(
                        'vi-visitors-top-scroll-inner'
                    );

                const tableScroll =
                    tableContainer
                    ? tableContainer.querySelector(
                        '.vi-visitors-table-scroll'
                    )
                    : null;

                const table =
                    document.getElementById(
                        'vi-visitors-table'
                    );

                if (
                    !topScroll
                    || !topScrollInner
                    || !tableScroll
                    || !table
                ) {
                    return;
                }

                function syncWidth() {
                    topScrollInner.style.width =
                        table.offsetWidth + 'px';
                }

                let syncing = false;

                topScroll.addEventListener(
                    'scroll',
                    function () {
                        if (syncing) {
                            return;
                        }

                        syncing = true;

                        tableScroll.scrollLeft =
                            topScroll.scrollLeft;

                        syncing = false;
                    }
                );

                tableScroll.addEventListener(
                    'scroll',
                    function () {
                        if (syncing) {
                            return;
                        }

                        syncing = true;

                        topScroll.scrollLeft =
                            tableScroll.scrollLeft;

                        syncing = false;
                    }
                );

                const observer =
                    new ResizeObserver(
                        syncWidth
                    );

                observer.observe(table);

                syncWidth();

                window.addEventListener(
                    'resize',
                    syncWidth
                );
            }());
        </script>

        <?php
    }
}