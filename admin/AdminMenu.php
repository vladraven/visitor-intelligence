<?php

declare(strict_types=1);

namespace VisitorIntelligence\Admin;

defined('ABSPATH') || exit;

final class AdminMenu
{
    private const CAPABILITY = 'manage_options';

    public function addMenuPage(): void
    {
        add_menu_page(
            __('Visitor Intelligence', 'visitor-intelligence'),
            __('Visitor Intel', 'visitor-intelligence'),
            self::CAPABILITY,
            'visitor-intelligence',
            [$this, 'renderDashboardPage'],
            'dashicons-chart-bar',
            30
        );
    }

    public function addVisitorsPage(
        VisitorsPage $visitorsPage
    ): void {
        add_submenu_page(
            'visitor-intelligence',
            __('Visitors', 'visitor-intelligence'),
            __('Visitors', 'visitor-intelligence'),
            self::CAPABILITY,
            'visitor-intelligence-visitors',
            [$visitorsPage, 'render']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook === 'toplevel_page_visitor-intelligence') {
            $this->enqueueDashboardAssets();

            return;
        }

        if ($hook === 'visitor-intel_page_visitor-intelligence-visitors') {
            $this->enqueueVisitorsAssets();

            return;
        }
    }

    public function renderDashboardPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(
                esc_html__(
                    'You do not have sufficient permissions to access this page.',
                    'visitor-intelligence'
                )
            );
        }
        ?>
        <div class="wrap vi-dashboard-wrap">
            <h1 class="wp-heading-inline">
                <?php
                echo esc_html__(
                    'Visitor Intelligence Dashboard',
                    'visitor-intelligence'
                );
                ?>
            </h1>

            <hr class="wp-header-end">

            <div class="vi-stats-overview">
                <div class="vi-card">
                    <h3>
                        <?php
                        echo esc_html__(
                            'Visitors',
                            'visitor-intelligence'
                        );
                        ?>
                    </h3>

                    <div
                        class="vi-card-value"
                        id="vi-stat-visitors"
                    >
                        —
                    </div>
                </div>

                <div class="vi-card">
                    <h3>
                        <?php
                        echo esc_html__(
                            'Sessions',
                            'visitor-intelligence'
                        );
                        ?>
                    </h3>

                    <div
                        class="vi-card-value"
                        id="vi-stat-sessions"
                    >
                        —
                    </div>
                </div>

                <div class="vi-card">
                    <h3>
                        <?php
                        echo esc_html__(
                            'Pageviews',
                            'visitor-intelligence'
                        );
                        ?>
                    </h3>

                    <div
                        class="vi-card-value"
                        id="vi-stat-pageviews"
                    >
                        —
                    </div>
                </div>

                <div class="vi-card">
                    <h3>
                        <?php
                        echo esc_html__(
                            'Active Time (sec)',
                            'visitor-intelligence'
                        );
                        ?>
                    </h3>

                    <div
                        class="vi-card-value"
                        id="vi-stat-active-time"
                    >
                        —
                    </div>
                </div>
            </div>

            <div class="vi-chart-container">
                <h2>
                    <?php
                    echo esc_html__(
                        'Traffic Trends (Last 30 Days)',
                        'visitor-intelligence'
                    );
                    ?>
                </h2>

                <canvas
                    id="viTrafficChart"
                    height="100"
                ></canvas>
            </div>
        </div>
        <?php
    }

    private function enqueueDashboardAssets(): void
    {
        wp_enqueue_style(
            'vi-admin-css',
            VI_URL . 'admin/assets/admin.css',
            [],
            VI_VERSION
        );

        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        wp_enqueue_script(
            'vi-admin-js',
            VI_URL . 'admin/assets/admin.js',
            ['jquery', 'chart-js'],
            VI_VERSION,
            true
        );

        wp_localize_script(
            'vi-admin-js',
            'viAdmin',
            [
                'apiEndpoint' =>
                    rest_url(
                        'vi/v1/analytics'
                    ),

                'nonce' =>
                    wp_create_nonce(
                        'wp_rest'
                    ),
            ]
        );
    }

    private function enqueueVisitorsAssets(): void
    {
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

                'strings' =>
                    [
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
}