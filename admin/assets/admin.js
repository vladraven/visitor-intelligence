(function($) {
    'use strict';

    let trafficChart = null;
    let hourlyChart = null;

    let currentFrom = null;
    let currentTo = null;

    $(document).ready(function() {
        const chartElement =
            document.getElementById('viTrafficChart');

        if (!chartElement) {
            return;
        }

        initializeDashboard();

        const range = getLast30Days();

        setDateInputs(
            range.from,
            range.to
        );

        loadDaily(
            range.from,
            range.to
        );

        function initializeDashboard() {
            createControls();
            createPagesContainer();
            createHourlyContainer();
            bindEvents();
        }

        function createControls() {
            const chartContainer =
                $('.vi-chart-container');

            if (!chartContainer.length) {
                return;
            }

            if (
                document.getElementById(
                    'viAnalyticsControls'
                )
            ) {
                return;
            }

            chartContainer.before(`
                <div
                    id="viAnalyticsControls"
                    class="vi-analytics-controls"
                    style="
                        display:flex;
                        flex-wrap:wrap;
                        gap:12px;
                        align-items:flex-end;
                        margin:20px 0;
                    "
                >
                    <div>
                        <label
                            for="viAnalyticsFrom"
                            style="
                                display:block;
                                font-weight:600;
                                margin-bottom:4px;
                            "
                        >
                            From
                        </label>

                        <input
                            type="date"
                            id="viAnalyticsFrom"
                        >
                    </div>

                    <div>
                        <label
                            for="viAnalyticsTo"
                            style="
                                display:block;
                                font-weight:600;
                                margin-bottom:4px;
                            "
                        >
                            To
                        </label>

                        <input
                            type="date"
                            id="viAnalyticsTo"
                        >
                    </div>

                    <button
                        type="button"
                        class="button button-primary"
                        id="viAnalyticsApply"
                    >
                        Apply
                    </button>

                    <button
                        type="button"
                        class="button"
                        id="viAnalytics30"
                    >
                        Last 30 days
                    </button>

                    <button
                        type="button"
                        class="button"
                        id="viAnalyticsPreviousMonth"
                    >
                        Previous month
                    </button>

                    <button
                        type="button"
                        class="button"
                        id="viAnalyticsCurrentMonth"
                    >
                        Current month
                    </button>

                    <button
                        type="button"
                        class="button"
                        id="viAnalyticsPages"
                    >
                        Pages
                    </button>
                </div>
            `);
        }

        function createPagesContainer() {
            if (
                document.getElementById(
                    'viPagesContainer'
                )
            ) {
                return;
            }

            $('.vi-dashboard-wrap').append(`
                <div
                    id="viPagesContainer"
                    style="
                        margin-top:30px;
                        display:none;
                    "
                >
                    <div
                        style="
                            display:flex;
                            justify-content:space-between;
                            align-items:center;
                            gap:15px;
                            margin-bottom:15px;
                        "
                    >
                        <h2 style="margin:0;">
                            Visited Pages
                        </h2>

                        <button
                            type="button"
                            class="button"
                            id="viPagesBack"
                        >
                            Traffic overview
                        </button>
                    </div>

                    <div
                        id="viPagesLoading"
                        style="display:none;"
                    >
                        Loading…
                    </div>

                    <div
                        id="viPagesError"
                        style="
                            display:none;
                            color:#b32d2e;
                            margin-bottom:15px;
                        "
                    ></div>

                    <div
                        style="
                            overflow-x:auto;
                            background:#fff;
                            border:1px solid #dcdcde;
                        "
                    >
                        <table
                            class="widefat striped"
                            id="viPagesTable"
                        >
                            <thead>
                                <tr>
                                    <th>Page</th>
                                    <th>Visitors</th>
                                    <th>Sessions</th>
                                    <th>Pageviews</th>
                                    <th>Entries</th>
                                    <th>Exits</th>
                                    <th>Active Time</th>
                                </tr>
                            </thead>

                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            `);
        }

        function createHourlyContainer() {
            if (
                document.getElementById(
                    'viHourlyContainer'
                )
            ) {
                return;
            }

            $('.vi-dashboard-wrap').append(`
                <div
                    id="viHourlyContainer"
                    style="
                        margin-top:30px;
                        display:none;
                    "
                >
                    <div
                        style="
                            display:flex;
                            justify-content:space-between;
                            align-items:center;
                            gap:15px;
                            margin-bottom:15px;
                        "
                    >
                        <h2
                            id="viHourlyTitle"
                            style="margin:0;"
                        >
                            Hourly Traffic
                        </h2>

                        <button
                            type="button"
                            class="button"
                            id="viHourlyBack"
                        >
                            Back to days
                        </button>
                    </div>

                    <div
                        style="
                            position:relative;
                            height:clamp(300px, 50vh, 520px);
							max-height:50vh;
                        "
                    >
                        <canvas
                            id="viHourlyChart"
                        ></canvas>
                    </div>
                </div>
            `);
        }

        function bindEvents() {
            $('#viAnalyticsApply').on(
                'click',
                applyCustomRange
            );

            $('#viAnalytics30').on(
                'click',
                loadLast30Days
            );

            $('#viAnalyticsPreviousMonth').on(
                'click',
                loadPreviousMonth
            );

            $('#viAnalyticsCurrentMonth').on(
                'click',
                loadCurrentMonth
            );

            $('#viAnalyticsPages').on(
                'click',
                loadPages
            );

            $('#viPagesBack').on(
                'click',
                showOverview
            );

            $('#viHourlyBack').on(
                'click',
                showDaily
            );
        }

        function loadDaily(
            from,
            to
        ) {
            currentFrom = from;
            currentTo = to;

            showDaily();

            setLoadingState(true);

            $.ajax({
                url:
                    viAdmin.apiEndpoint,

                method:
                    'GET',

                dataType:
                    'json',

                cache:
                    false,

                data:
                    {
                        from:
                            from,

                        to:
                            to,

                        granularity:
                            'day',

                        view:
                            'overview',

                        _:
                            Date.now()
                    },

                beforeSend:
                    setNonce,

                success:
                    function(response) {
                        if (
                            !isValidDailyResponse(
                                response
                            )
                        ) {
                            console.error(
                                'Invalid analytics response.',
                                response
                            );

                            renderError();

                            return;
                        }

                        renderStats(
                            response.summary
                        );

                        renderChart(
                            response.trends
                        );

                        updateChartTitle(
                            response.range
                        );
                    },

                error:
                    function(xhr) {
                        console.error(
                            'Analytics request failed:',
                            xhr
                        );

                        renderError();
                    },

                complete:
                    function() {
                        setLoadingState(false);
                    }
            });
        }

        function loadHourly(
            date
        ) {
            $('#viHourlyContainer').show();
            $('.vi-chart-container').hide();
            $('#viPagesContainer').hide();

            setLoadingState(true);

            $.ajax({
                url:
                    viAdmin.apiEndpoint,

                method:
                    'GET',

                dataType:
                    'json',

                cache:
                    false,

                data:
                    {
                        date:
                            date,

                        granularity:
                            'hour',

                        _:
                            Date.now()
                    },

                beforeSend:
                    setNonce,

                success:
                    function(response) {
                        if (
                            !isValidHourlyResponse(
                                response
                            )
                        ) {
                            console.error(
                                'Invalid hourly analytics response.',
                                response
                            );

                            return;
                        }

                        renderHourlyChart(
                            response
                        );

                        $('#viHourlyTitle').text(
                            'Hourly Traffic — '
                            + formatDisplayDate(
                                date
                            )
                        );
                    },

                error:
                    function(xhr) {
                        console.error(
                            'Hourly analytics request failed:',
                            xhr
                        );
                    },

                complete:
                    function() {
                        setLoadingState(false);
                    }
            });
        }

        function loadPages() {
            if (
                !currentFrom
                || !currentTo
            ) {
                return;
            }

            $('.vi-chart-container').hide();
            $('#viHourlyContainer').hide();
            $('#viPagesContainer').show();

            $('#viPagesLoading').show();
            $('#viPagesError').hide();

            $('#viPagesTable tbody').empty();

            $.ajax({
                url:
                    viAdmin.apiEndpoint,

                method:
                    'GET',

                dataType:
                    'json',

                cache:
                    false,

                data:
                    {
                        from:
                            currentFrom,

                        to:
                            currentTo,

                        view:
                            'pages',

                        limit:
                            500,

                        _:
                            Date.now()
                    },

                beforeSend:
                    setNonce,

                success:
                    function(response) {
                        if (
                            !response
                            || !Array.isArray(
                                response.pages
                            )
                        ) {
                            showPagesError(
                                'Invalid pages response.'
                            );

                            return;
                        }

                        renderPages(
                            response.pages
                        );
                    },

                error:
                    function(xhr) {
                        console.error(
                            'Pages analytics request failed:',
                            xhr
                        );

                        showPagesError(
                            'Unable to load visited pages.'
                        );
                    },

                complete:
                    function() {
                        $('#viPagesLoading').hide();
                    }
            });
        }

        function applyCustomRange() {
            const from =
                $('#viAnalyticsFrom').val();

            const to =
                $('#viAnalyticsTo').val();

            if (
                !from
                || !to
            ) {
                window.alert(
                    'Please select both dates.'
                );

                return;
            }

            if (
                from > to
            ) {
                window.alert(
                    'The start date cannot be after the end date.'
                );

                return;
            }

            if (
                daysBetween(
                    from,
                    to
                ) > 3650
            ) {
                window.alert(
                    'The maximum supported range is 10 years.'
                );

                return;
            }

            loadDaily(
                from,
                to
            );
        }

        function loadLast30Days() {
            const range =
                getLast30Days();

            setDateInputs(
                range.from,
                range.to
            );

            loadDaily(
                range.from,
                range.to
            );
        }

        function loadCurrentMonth() {
            const today =
                new Date();

            const from =
                formatDate(
                    new Date(
                        today.getFullYear(),
                        today.getMonth(),
                        1
                    )
                );

            const to =
                formatDate(
                    today
                );

            setDateInputs(
                from,
                to
            );

            loadDaily(
                from,
                to
            );
        }

        function loadPreviousMonth() {
            const today =
                new Date();

            const firstCurrent =
                new Date(
                    today.getFullYear(),
                    today.getMonth(),
                    1
                );

            const lastPrevious =
                new Date(
                    firstCurrent.getTime()
                    - 86400000
                );

            const firstPrevious =
                new Date(
                    lastPrevious.getFullYear(),
                    lastPrevious.getMonth(),
                    1
                );

            const from =
                formatDate(
                    firstPrevious
                );

            const to =
                formatDate(
                    lastPrevious
                );

            setDateInputs(
                from,
                to
            );

            loadDaily(
                from,
                to
            );
        }

        function renderStats(
            summary
        ) {
            $('#vi-stat-visitors').text(
                formatNumber(
                    summary.visitors
                )
            );

            $('#vi-stat-sessions').text(
                formatNumber(
                    summary.sessions
                )
            );

            $('#vi-stat-pageviews').text(
                formatNumber(
                    summary.pageviews
                )
            );

            $('#vi-stat-active-time').text(
                formatDuration(
                    summary.active_seconds
                )
            );
        }

        function renderChart(
            trends
        ) {
            const element =
                document.getElementById(
                    'viTrafficChart'
                );

            if (!element) {
                return;
            }

            const ctx =
                element.getContext('2d');

            if (trafficChart) {
                trafficChart.destroy();
                trafficChart = null;
            }

            trafficChart =
                new Chart(
                    ctx,
                    {
                        type:
                            'line',

                        data:
                            {
                                labels:
                                    trends.labels,

                                datasets:
                                    [
                                        createDataset(
                                            'Visitors',
                                            trends.visitors,
                                            '#8e44ad',
                                            'rgba(142,68,173,0.1)',
                                            true
                                        ),

                                        createDataset(
                                            'Sessions',
                                            trends.sessions,
                                            '#2271b1',
                                            'rgba(34,113,177,0.1)',
                                            false
                                        ),

                                        createDataset(
                                            'Pageviews',
                                            trends.pageviews,
                                            '#00a32a',
                                            'transparent',
                                            false
                                        )
                                    ]
                            },

                        options:
                            {
                                responsive:
                                    true,

                                maintainAspectRatio:
                                    false,

                                interaction:
                                    {
                                        mode:
                                            'index',

                                        intersect:
                                            false
                                    },

                                onClick:
                                    function(
                                        event,
                                        elements
                                    ) {
                                        if (
                                            !elements
                                            || !elements.length
                                        ) {
                                            return;
                                        }

                                        const index =
                                            elements[0].index;

                                        const date =
                                            trends.labels[
                                                index
                                            ];

                                        if (
                                            !isValidDate(
                                                date
                                            )
                                        ) {
                                            return;
                                        }

                                        loadHourly(
                                            date
                                        );
                                    },

                                plugins:
                                    {
                                        legend:
                                            {
                                                position:
                                                    'top'
                                            },

                                        tooltip:
                                            {
                                                callbacks:
                                                    {
                                                        title:
                                                            function(
                                                                items
                                                            ) {
                                                                if (
                                                                    !items.length
                                                                ) {
                                                                    return '';
                                                                }

                                                                return formatDisplayDate(
                                                                    items[0].label
                                                                );
                                                            },

                                                        label:
                                                            function(
                                                                context
                                                            ) {
                                                                return (
                                                                    context.dataset.label
                                                                    + ': '
                                                                    + formatNumber(
                                                                        context.parsed.y
                                                                    )
                                                                );
                                                            }
                                                    }
                                            }
                                    },

                                scales:
                                    {
                                        x:
                                            {
                                                ticks:
                                                    {
                                                        autoSkip:
                                                            true,

                                                        maxTicksLimit:
                                                            12
                                                    }
                                            },

                                        y:
                                            {
                                                beginAtZero:
                                                    true,

                                                ticks:
                                                    {
                                                        precision:
                                                            0
                                                    }
                                            }
                                    }
                            }
                    }
                );
        }

        function renderHourlyChart(
            response
        ) {
            const element =
                document.getElementById(
                    'viHourlyChart'
                );

            if (!element) {
                return;
            }

            const ctx =
                element.getContext('2d');

            if (hourlyChart) {
                hourlyChart.destroy();
                hourlyChart = null;
            }

            hourlyChart =
                new Chart(
                    ctx,
                    {
                        type:
                            'line',

                        data:
                            {
                                labels:
                                    response.trends.labels,

                                datasets:
                                    [
                                        createDataset(
                                            'Visitors',
                                            response.trends.visitors,
                                            '#8e44ad',
                                            'rgba(142,68,173,0.1)',
                                            true
                                        ),

                                        createDataset(
                                            'Sessions',
                                            response.trends.sessions,
                                            '#2271b1',
                                            'transparent',
                                            false
                                        ),

                                        createDataset(
                                            'Pageviews',
                                            response.trends.pageviews,
                                            '#00a32a',
                                            'transparent',
                                            false
                                        )
                                    ]
                            },

                        options:
                            {
                                responsive:
                                    true,

                                maintainAspectRatio:
                                    false,

                                interaction:
                                    {
                                        mode:
                                            'index',

                                        intersect:
                                            false
                                    },

                                plugins:
                                    {
                                        legend:
                                            {
                                                position:
                                                    'top'
                                            },

                                        tooltip:
                                            {
                                                callbacks:
                                                    {
                                                        label:
                                                            function(
                                                                context
                                                            ) {
                                                                return (
                                                                    context.dataset.label
                                                                    + ': '
                                                                    + formatNumber(
                                                                        context.parsed.y
                                                                    )
                                                                );
                                                            }
                                                    }
                                            }
                                    },

                                scales:
                                    {
                                        x:
                                            {
                                                ticks:
                                                    {
                                                        autoSkip:
                                                            false
                                                    }
                                            },

                                        y:
                                            {
                                                beginAtZero:
                                                    true,

                                                ticks:
                                                    {
                                                        precision:
                                                            0
                                                    }
                                            }
                                    }
                            }
                    }
                );
        }

        function createDataset(
            label,
            data,
            borderColor,
            backgroundColor,
            fill
        ) {
            return {
                label:
                    label,

                data:
                    normalizeSeries(
                        data
                    ),

                borderColor:
                    borderColor,

                backgroundColor:
                    backgroundColor,

                fill:
                    fill,

                tension:
                    0.3,

                pointRadius:
                    3,

                pointHoverRadius:
                    6
            };
        }

        function renderPages(
            pages
        ) {
            const tbody =
                $('#viPagesTable tbody');

            tbody.empty();

            if (
                pages.length === 0
            ) {
                tbody.append(
                    $('<tr>').append(
                        $('<td>')
                            .attr(
                                'colspan',
                                7
                            )
                            .text(
                                'No pageviews found for this period.'
                            )
                    )
                );

                return;
            }

            pages.forEach(
                function(page) {
                    const row =
                        $('<tr>');

                    const url =
                        String(
                            page.url || ''
                        );

                    const link =
                        $('<a>')
                            .attr(
                                'href',
                                url
                            )
                            .attr(
                                'target',
                                '_blank'
                            )
                            .attr(
                                'rel',
                                'noopener noreferrer'
                            )
                            .text(
                                url
                            );

                    row.append(
                        $('<td>').append(
                            link
                        )
                    );

                    row.append(
                        $('<td>').text(
                            formatNumber(
                                page.visitors
                            )
                        )
                    );

                    row.append(
                        $('<td>').text(
                            formatNumber(
                                page.sessions
                            )
                        )
                    );

                    row.append(
                        $('<td>').text(
                            formatNumber(
                                page.pageviews
                            )
                        )
                    );

                    row.append(
                        $('<td>').text(
                            formatNumber(
                                page.entries
                            )
                        )
                    );

                    row.append(
                        $('<td>').text(
                            formatNumber(
                                page.exits
                            )
                        )
                    );

                    row.append(
                        $('<td>').text(
                            formatDuration(
                                page.active_seconds
                            )
                        )
                    );

                    tbody.append(
                        row
                    );
                }
            );
        }

        function showOverview() {
            $('#viPagesContainer').hide();
            $('#viHourlyContainer').hide();
            $('.vi-chart-container').show();

            if (
                currentFrom
                && currentTo
            ) {
                loadDaily(
                    currentFrom,
                    currentTo
                );
            }
        }

        function showDaily() {
            $('#viPagesContainer').hide();
            $('#viHourlyContainer').hide();
            $('.vi-chart-container').show();
        }

        function updateChartTitle(
            range
        ) {
            const title =
                $('.vi-chart-container h2');

            if (!title.length) {
                return;
            }

            if (
                range
                && range.from
                && range.to
            ) {
                title.text(
                    'Traffic Trends ('
                    + formatDisplayDate(
                        range.from
                    )
                    + ' / '
                    + formatDisplayDate(
                        range.to
                    )
                    + ')'
                );
            }
        }

        function setDateInputs(
            from,
            to
        ) {
            $('#viAnalyticsFrom').val(
                from
            );

            $('#viAnalyticsTo').val(
                to
            );
        }

        function setNonce(
            xhr
        ) {
            if (
                typeof viAdmin !== 'undefined'
                && viAdmin.nonce
            ) {
                xhr.setRequestHeader(
                    'X-WP-Nonce',
                    viAdmin.nonce
                );
            }
        }

        function setLoadingState(
            loading
        ) {
            $('#viTrafficChart').css(
                'opacity',
                loading ? '0.5' : '1'
            );
        }

        function isValidDailyResponse(
            response
        ) {
            if (
                !response
                || typeof response !== 'object'
                || !response.summary
                || !response.trends
            ) {
                return false;
            }

            const trends =
                response.trends;

            if (
                !Array.isArray(
                    trends.labels
                )
                || !Array.isArray(
                    trends.visitors
                )
                || !Array.isArray(
                    trends.sessions
                )
                || !Array.isArray(
                    trends.pageviews
                )
            ) {
                return false;
            }

            const length =
                trends.labels.length;

            return (
                length ===
                    trends.visitors.length
                && length ===
                    trends.sessions.length
                && length ===
                    trends.pageviews.length
            );
        }

        function isValidHourlyResponse(
            response
        ) {
            if (
                !response
                || typeof response !== 'object'
                || !response.trends
            ) {
                return false;
            }

            const trends =
                response.trends;

            if (
                !Array.isArray(
                    trends.labels
                )
                || !Array.isArray(
                    trends.visitors
                )
                || !Array.isArray(
                    trends.sessions
                )
                || !Array.isArray(
                    trends.pageviews
                )
            ) {
                return false;
            }

            const length =
                trends.labels.length;

            return (
                length ===
                    trends.visitors.length
                && length ===
                    trends.sessions.length
                && length ===
                    trends.pageviews.length
            );
        }

        function normalizeSeries(
            series
        ) {
            if (
                !Array.isArray(series)
            ) {
                return [];
            }

            return series.map(
                function(value) {
                    const number =
                        Number(value);

                    return (
                        Number.isFinite(number)
                        && number >= 0
                    )
                        ? number
                        : 0;
                }
            );
        }

        function renderError() {
            $('#vi-stat-visitors').text('—');
            $('#vi-stat-sessions').text('—');
            $('#vi-stat-pageviews').text('—');
            $('#vi-stat-active-time').text('—');
        }

        function showPagesError(
            message
        ) {
            $('#viPagesError')
                .text(message)
                .show();
        }

        function formatNumber(
            value
        ) {
            const number =
                Number(value);

            if (
                !Number.isFinite(number)
            ) {
                return '0';
            }

            return number.toLocaleString();
        }

        function formatDuration(
            value
        ) {
            let seconds =
                Number(value);

            if (
                !Number.isFinite(seconds)
                || seconds < 0
            ) {
                seconds = 0;
            }

            seconds =
                Math.floor(seconds);

            const hours =
                Math.floor(
                    seconds / 3600
                );

            const minutes =
                Math.floor(
                    (seconds % 3600) / 60
                );

            const remainingSeconds =
                seconds % 60;

            if (hours > 0) {
                return (
                    hours
                    + 'h '
                    + String(minutes)
                        .padStart(2, '0')
                    + 'm'
                );
            }

            if (minutes > 0) {
                return (
                    minutes
                    + 'm '
                    + String(remainingSeconds)
                        .padStart(2, '0')
                    + 's'
                );
            }

            return (
                remainingSeconds
                + 's'
            );
        }

        function getLast30Days() {
            const today =
                new Date();

            const from =
                new Date(
                    today.getTime()
                    - (
                        29
                        * 86400000
                    )
                );

            return {
                from:
                    formatDate(from),

                to:
                    formatDate(today)
            };
        }

        function formatDate(
            date
        ) {
            return (
                date.getFullYear()
                + '-'
                + String(
                    date.getMonth() + 1
                ).padStart(2, '0')
                + '-'
                + String(
                    date.getDate()
                ).padStart(2, '0')
            );
        }

        function formatDisplayDate(
            value
        ) {
            if (
                !isValidDate(value)
            ) {
                return String(
                    value || ''
                );
            }

            const parts =
                String(value).split('-');

            return (
                parts[2]
                + '.'
                + parts[1]
                + '.'
                + parts[0]
            );
        }

        function isValidDate(
            value
        ) {
            return /^\d{4}-\d{2}-\d{2}$/.test(
                String(value || '')
            );
        }

        function daysBetween(
            from,
            to
        ) {
            const fromDate =
                new Date(
                    from + 'T00:00:00'
                );

            const toDate =
                new Date(
                    to + 'T00:00:00'
                );

            return (
                Math.floor(
                    (
                        toDate.getTime()
                        - fromDate.getTime()
                    ) / 86400000
                ) + 1
            );
        }
    });
})(jQuery);