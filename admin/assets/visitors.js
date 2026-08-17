(function () {
    'use strict';

    const config =
        window.viVisitors || {};

    const state = {
        visitors: [],

        loading: false,

        page: 1,

        perPage: 50,

        total: 0,

        totalPages: 0,

        sort: 'last_seen',

        direction: 'DESC',

        filters: {
            search: '',
            country: '',
            region: '',
            city: '',
            visitor_type: '',
            device: '',
            browser: '',
            os: '',
            last_seen_from: '',
            last_seen_to: '',
        },

        filterOptions: {
            countries: [],
            regions: [],
            cities: [],
            device_types: [],
            browsers: [],
            operating_systems: [],
            bot_classifications: [],
        },
    };

    const elements = {
        refresh:
            document.getElementById(
                'vi-visitors-refresh'
            ),

        export:
            document.getElementById(
                'vi-visitors-export'
            ),

        loading:
            document.getElementById(
                'vi-visitors-loading'
            ),

        empty:
            document.getElementById(
                'vi-visitors-empty'
            ),

        status:
            document.getElementById(
                'vi-visitors-status'
            ),

        tableContainer:
            document.getElementById(
                'vi-visitors-table-container'
            ),

        tableBody:
            document.getElementById(
                'vi-visitors-table-body'
            ),

        details:
            document.getElementById(
                'vi-visitor-details'
            ),

        detailsContent:
            document.getElementById(
                'vi-visitor-details-content'
            ),

        search:
            document.getElementById(
                'vi-filter-search'
            ),

        country:
            document.getElementById(
                'vi-filter-country'
            ),

        region:
            document.getElementById(
                'vi-filter-region'
            ),

        city:
            document.getElementById(
                'vi-filter-city'
            ),

        type:
            document.getElementById(
                'vi-filter-type'
            ),

        device:
            document.getElementById(
                'vi-filter-device'
            ),

        browser:
            document.getElementById(
                'vi-filter-browser'
            ),

        os:
            document.getElementById(
                'vi-filter-os'
            ),

        from:
            document.getElementById(
                'vi-filter-from'
            ),

        to:
            document.getElementById(
                'vi-filter-to'
            ),

        applyFilters:
            document.getElementById(
                'vi-visitors-apply-filters'
            ),

        resetFilters:
            document.getElementById(
                'vi-visitors-reset-filters'
            ),

        pagination:
            document.getElementById(
                'vi-visitors-pagination'
            ),

        previous:
            document.getElementById(
                'vi-visitors-prev'
            ),

        next:
            document.getElementById(
                'vi-visitors-next'
            ),

        pageInfo:
            document.getElementById(
                'vi-visitors-page-info'
            ),

        topScroll:
            document.getElementById(
                'vi-visitors-top-scroll'
            ),

        topScrollInner:
            document.getElementById(
                'vi-visitors-top-scroll-inner'
            ),
    };

    function stringValue(value) {
        if (
            value === null
            || value === undefined
            || value === ''
        ) {
            return (
                config.strings?.notAvailable
                || 'N/A'
            );
        }

        return String(value);
    }

    function escapeHtml(value) {
        return stringValue(value)
            .replaceAll(
                '&',
                '&amp;'
            )
            .replaceAll(
                '<',
                '&lt;'
            )
            .replaceAll(
                '>',
                '&gt;'
            )
            .replaceAll(
                '"',
                '&quot;'
            )
            .replaceAll(
                "'",
                '&#039;'
            );
    }

    function setVisible(
        element,
        visible
    ) {
        if (!element) {
            return;
        }

        element.style.display =
            visible
                ? ''
                : 'none';
    }

    function showStatus(
        message,
        type = 'error'
    ) {
        if (!elements.status) {
            return;
        }

        elements.status.className =
            'notice notice-' + type;

        elements.status.textContent =
            message;

        elements.status.style.display =
            '';
    }

    function hideStatus() {
        if (!elements.status) {
            return;
        }

        elements.status.style.display =
            'none';

        elements.status.textContent =
            '';

        elements.status.className =
            'notice';
    }

    function setLoading(
        loading
    ) {
        state.loading =
            loading;

        setVisible(
            elements.loading,
            loading
        );

        if (elements.refresh) {
            elements.refresh.disabled =
                loading;
        }

        if (elements.applyFilters) {
            elements.applyFilters.disabled =
                loading;
        }

        if (elements.resetFilters) {
            elements.resetFilters.disabled =
                loading;
        }

        if (elements.export) {
            elements.export.disabled =
                loading;
        }
    }

    function getHeaders() {
        const headers = {
            Accept:
                'application/json',
        };

        if (config.nonce) {
            headers['X-WP-Nonce'] =
                config.nonce;
        }

        return headers;
    }

    async function request(
        url
    ) {
        const response =
            await fetch(
                url,
                {
                    method:
                        'GET',

                    credentials:
                        'same-origin',

                    headers:
                        getHeaders(),
                }
            );

        let payload = null;

        try {
            payload =
                await response.json();
        } catch (
            error
        ) {
            payload =
                null;
        }

        if (!response.ok) {
            const message =
                payload?.message
                || config.strings?.error
                || 'Request failed.';

            throw new Error(
                message
            );
        }

        return payload;
    }

    function getVisitorId(
        visitor
    ) {
        return String(
            visitor?.visitor_id
            ?? visitor?.id
            ?? ''
        );
    }

    function getCoordinates(
        visitor
    ) {
        const latitude =
            visitor?.latitude;

        const longitude =
            visitor?.longitude;

        if (
            latitude === null
            || latitude === undefined
            || latitude === ''
            || longitude === null
            || longitude === undefined
            || longitude === ''
        ) {
            return (
                config.strings?.notAvailable
                || 'N/A'
            );
        }

        return (
            escapeHtml(latitude)
            + ', '
            + escapeHtml(longitude)
        );
    }

    function getCountry(
        visitor
    ) {
        const name =
            visitor?.country_name;

        const code =
            visitor?.country_code;

        if (
            !name
            && !code
        ) {
            return (
                config.strings?.notAvailable
                || 'N/A'
            );
        }

        if (
            name
            && code
        ) {
            return (
                escapeHtml(name)
                + ' ('
                + escapeHtml(code)
                + ')'
            );
        }

        return escapeHtml(
            name || code
        );
    }

    function getRegion(
        visitor
    ) {
        const name =
            visitor?.region_name;

        const code =
            visitor?.region_code;

        if (
            !name
            && !code
        ) {
            return (
                config.strings?.notAvailable
                || 'N/A'
            );
        }

        if (
            name
            && code
        ) {
            return (
                escapeHtml(name)
                + ' ('
                + escapeHtml(code)
                + ')'
            );
        }

        return escapeHtml(
            name || code
        );
    }

    function getBotType(
        visitor
    ) {
        return escapeHtml(
            visitor?.bot_classification
        );
    }

    function renderRows() {
        if (!elements.tableBody) {
            return;
        }

        elements.tableBody.innerHTML =
            '';

        state.visitors.forEach(
            function (visitor) {
                const visitorId =
                    getVisitorId(
                        visitor
                    );

                const row =
                    document.createElement(
                        'tr'
                    );

                row.innerHTML = `
                    <td>
                        ${escapeHtml(
                            visitor?.id
                        )}
                    </td>

                    <td>
                        <button
                            type="button"
                            class="button-link vi-visitor-link"
                            data-visitor-id="${escapeHtml(visitorId)}"
                        >
                            ${escapeHtml(visitorId)}
                        </button>
                    </td>

                    <td>
                        ${escapeHtml(
                            visitor?.last_seen
                        )}
                    </td>

                    <td>
                        ${escapeHtml(
                            visitor?.first_seen
                        )}
                    </td>

                    <td>
                        ${getCountry(visitor)}
                    </td>

                    <td>
                        ${getRegion(visitor)}
                    </td>

                    <td>
                        ${escapeHtml(
                            visitor?.city
                        )}
                    </td>

                    <td>
                        ${escapeHtml(
                            visitor?.device_type
                        )}
                    </td>

                    <td>
                        ${escapeHtml(
                            visitor?.browser
                        )}
                    </td>

                    <td>
                        ${escapeHtml(
                            visitor?.browser_version
                        )}
                    </td>

                    <td>
                        ${escapeHtml(
                            visitor?.os
                        )}
                    </td>

                    <td>
                        ${escapeHtml(
                            visitor?.os_version
                        )}
                    </td>

                    <td>
                        ${escapeHtml(
                            visitor?.sessions_count
                        )}
                    </td>

                    <td>
                        ${escapeHtml(
                            visitor?.pageviews_count
                        )}
                    </td>

                    <td>
                        ${getBotType(visitor)}
                    </td>

                    <td>
                        ${getCoordinates(visitor)}
                    </td>
                `;

                elements.tableBody.appendChild(
                    row
                );
            }
        );

        updateSortIndicators();

        synchronizeTopScroll();
    }

    function renderListState() {
        const hasVisitors =
            state.visitors.length > 0;

        setVisible(
            elements.empty,
            !state.loading
            && !hasVisitors
        );

        setVisible(
            elements.tableContainer,
            !state.loading
            && hasVisitors
        );

        setVisible(
            elements.pagination,
            !state.loading
            && state.totalPages > 0
        );
    }

    function renderPagination() {
        if (!elements.pagination) {
            return;
        }

        const totalPages =
            state.totalPages;

        const currentPage =
            state.page;

        if (
            totalPages <= 0
        ) {
            setVisible(
                elements.pagination,
                false
            );

            return;
        }

        setVisible(
            elements.pagination,
            true
        );

        if (elements.pageInfo) {
            elements.pageInfo.textContent =
                'Page '
                + currentPage
                + ' of '
                + totalPages
                + ' — '
                + state.total
                + ' visitors';
        }

        if (elements.previous) {
            elements.previous.disabled =
                currentPage <= 1
                || state.loading;
        }

        if (elements.next) {
            elements.next.disabled =
                currentPage >= totalPages
                || state.loading;
        }
    }

    function updateSortIndicators() {
        const headers =
            document.querySelectorAll(
                '#vi-visitors-table .vi-sortable'
            );

        headers.forEach(
            function (header) {
                const field =
                    header.dataset.sort;

                const indicator =
                    header.querySelector(
                        '.vi-sort-indicator'
                    );

                if (!indicator) {
                    return;
                }

                if (
                    field !== state.sort
                ) {
                    indicator.textContent =
                        '';

                    return;
                }

                indicator.textContent =
                    state.direction === 'ASC'
                        ? '↑'
                        : '↓';
            }
        );
    }

    function readFiltersFromForm() {
        state.filters = {
            search:
                elements.search?.value
                ?.trim()
                || '',

            country:
                elements.country?.value
                || '',

            region:
                elements.region?.value
                || '',

            city:
                elements.city?.value
                || '',

            visitor_type:
                elements.type?.value
                || '',

            device:
                elements.device?.value
                || '',

            browser:
                elements.browser?.value
                || '',

            os:
                elements.os?.value
                || '',

            last_seen_from:
                elements.from?.value
                || '',

            last_seen_to:
                elements.to?.value
                || '',
        };
    }

    function clearFiltersForm() {
        if (elements.search) {
            elements.search.value =
                '';
        }

        if (elements.country) {
            elements.country.value =
                '';
        }

        if (elements.region) {
            elements.region.value =
                '';
        }

        if (elements.city) {
            elements.city.value =
                '';
        }

        if (elements.type) {
            elements.type.value =
                '';
        }

        if (elements.device) {
            elements.device.value =
                '';
        }

        if (elements.browser) {
            elements.browser.value =
                '';
        }

        if (elements.os) {
            elements.os.value =
                '';
        }

        if (elements.from) {
            elements.from.value =
                '';
        }

        if (elements.to) {
            elements.to.value =
                '';
        }
    }

    function buildQueryString(
        includePagination = true
    ) {
        const params =
            new URLSearchParams();

        if (includePagination) {
            params.set(
                'page',
                String(
                    state.page
                )
            );

            params.set(
                'per_page',
                String(
                    state.perPage
                )
            );
        }

        params.set(
            'sort',
            state.sort
        );

        params.set(
            'direction',
            state.direction
        );

        Object.keys(
            state.filters
        ).forEach(
            function (key) {
                const value =
                    state.filters[key];

                if (
                    value === null
                    || value === undefined
                    || value === ''
                ) {
                    return;
                }

                params.set(
                    key,
                    value
                );
            }
        );

        return params.toString();
    }

    function getVisitorsUrl() {
        const query =
            buildQueryString(
                true
            );

        return (
            config.apiEndpoint
            + '?'
            + query
        );
    }

    function getFiltersUrl() {
        const query =
            buildQueryString(
                false
            );

        return (
            config.apiEndpoint
            + '/filters'
            + (
                query
                    ? '?' + query
                    : ''
            )
        );
    }

    async function loadVisitors() {
        if (
            state.loading
            || !config.apiEndpoint
        ) {
            return;
        }

        hideStatus();

        setLoading(
            true
        );

        setVisible(
            elements.empty,
            false
        );

        try {
            const payload =
                await request(
                    getVisitorsUrl()
                );

            const items =
                Array.isArray(payload?.items)
                    ? payload.items
                    : Array.isArray(payload?.data)
                        ? payload.data
                        : Array.isArray(payload?.visitors)
                            ? payload.visitors
                            : [];

            state.visitors = items;

            state.page =
                Number(
                    payload?.page
                    || state.page
                );

            state.perPage =
                Number(
                    payload?.per_page
                    || state.perPage
                );

            state.total =
                Number(
                    payload?.total
                    || 0
                );

            state.totalPages =
                Number(
                    payload?.total_pages
                    || payload?.meta?.total_pages
                    || 0
                );

            renderRows();
            renderPagination();
            renderListState();
        } catch (
            error
        ) {
            state.visitors =
                [];

            state.total =
                0;

            state.totalPages =
                0;

            renderListState();
            renderPagination();

            showStatus(
                error.message
                || config.strings?.error
                || 'Unable to load visitors.',
                'error'
            );
        } finally {
            setLoading(
                false
            );

            renderListState();
            renderPagination();
        }
    }

    async function loadFilterOptions() {
        if (
            !config.apiEndpoint
        ) {
            return;
        }

        try {
            const payload =
                await request(
                    getFiltersUrl()
                );

            state.filterOptions =
                payload?.filters
                || state.filterOptions;

            renderFilterOptions();
        } catch (
            error
        ) {
            showStatus(
                error.message
                || 'Unable to load visitor filters.',
                'error'
            );
        }
    }

    function renderFilterOptions() {
        renderSelectOptions(
            elements.country,
            state.filterOptions.countries,
            'All countries',
            function (item) {
                return {
                    value:
                        item?.code
                        || '',

                    label:
                        (
                            item?.name
                            || item?.code
                            || ''
                        )
                        + (
                            item?.count !== undefined
                                ? ' (' + item.count + ')'
                                : ''
                        ),
                };
            }
        );

        renderSelectOptions(
            elements.region,
            state.filterOptions.regions,
            'All provinces / regions',
            function (item) {
                return {
                    value:
                        item?.name
                        || '',

                    label:
                        (
                            item?.name
                            || ''
                        )
                        + (
                            item?.country_name
                                ? ' — '
                                + item.country_name
                                : ''
                        )
                        + (
                            item?.count !== undefined
                                ? ' (' + item.count + ')'
                                : ''
                        ),
                };
            }
        );

        renderSelectOptions(
            elements.city,
            state.filterOptions.cities,
            'All cities',
            function (item) {
                if (
                    typeof item === 'string'
                ) {
                    return {
                        value:
                            item,

                        label:
                            item,
                    };
                }

                return {
                    value:
                        item?.value
                        || item?.name
                        || '',

                    label:
                        item?.label
                        || item?.name
                        || '',
                };
            }
        );

        renderSelectOptions(
            elements.device,
            state.filterOptions.device_types,
            'All devices',
            function (item) {
                return {
                    value:
                        typeof item === 'string'
                            ? item
                            : item?.value
                                || item?.name
                                || '',

                    label:
                        typeof item === 'string'
                            ? item
                            : item?.label
                                || item?.name
                                || '',
                };
            }
        );

        renderSelectOptions(
            elements.browser,
            state.filterOptions.browsers,
            'All browsers',
            function (item) {
                return {
                    value:
                        typeof item === 'string'
                            ? item
                            : item?.value
                                || item?.name
                                || '',

                    label:
                        typeof item === 'string'
                            ? item
                            : item?.label
                                || item?.name
                                || '',
                };
            }
        );

        renderSelectOptions(
            elements.os,
            state.filterOptions.operating_systems,
            'All operating systems',
            function (item) {
                return {
                    value:
                        typeof item === 'string'
                            ? item
                            : item?.value
                                || item?.name
                                || '',

                    label:
                        typeof item === 'string'
                            ? item
                            : item?.label
                                || item?.name
                                || '',
                };
            }
        );

        restoreFilterValues();
    }

    function renderSelectOptions(
        select,
        values,
        placeholder,
        formatter
    ) {
        if (!select) {
            return;
        }

        const currentValue =
            select.value;

        select.innerHTML =
            '';

        const placeholderOption =
            document.createElement(
                'option'
            );

        placeholderOption.value =
            '';

        placeholderOption.textContent =
            placeholder;

        select.appendChild(
            placeholderOption
        );

        if (
            !Array.isArray(values)
        ) {
            return;
        }

        values.forEach(
            function (item) {
                const optionData =
                    formatter(item);

                if (
                    !optionData.value
                ) {
                    return;
                }

                const option =
                    document.createElement(
                        'option'
                    );

                option.value =
                    String(
                        optionData.value
                    );

                option.textContent =
                    String(
                        optionData.label
                        || optionData.value
                    );

                select.appendChild(
                    option
                );
            }
        );

        if (
            currentValue
        ) {
            const exists =
                Array.from(
                    select.options
                ).some(
                    function (option) {
                        return (
                            option.value
                            === currentValue
                        );
                    }
                );

            if (exists) {
                select.value =
                    currentValue;
            }
        }
    }

    function restoreFilterValues() {
        const filters =
            state.filters;

        if (elements.search) {
            elements.search.value =
                filters.search;
        }

        if (elements.country) {
            elements.country.value =
                filters.country;
        }

        if (elements.region) {
            elements.region.value =
                filters.region;
        }

        if (elements.city) {
            elements.city.value =
                filters.city;
        }

        if (elements.type) {
            elements.type.value =
                filters.visitor_type;
        }

        if (elements.device) {
            elements.device.value =
                filters.device;
        }

        if (elements.browser) {
            elements.browser.value =
                filters.browser;
        }

        if (elements.os) {
            elements.os.value =
                filters.os;
        }

        if (elements.from) {
            elements.from.value =
                filters.last_seen_from;
        }

        if (elements.to) {
            elements.to.value =
                filters.last_seen_to;
        }
    }

    function applyFilters() {
        readFiltersFromForm();

        if (
            state.filters.last_seen_from
            && state.filters.last_seen_to
            && state.filters.last_seen_from
                > state.filters.last_seen_to
        ) {
            showStatus(
                'The Last Seen start date cannot be after the end date.',
                'error'
            );

            return;
        }

        state.page =
            1;

        loadFilterOptions();

        loadVisitors();
    }

    function resetFilters() {
        clearFiltersForm();

        state.filters = {
            search: '',
            country: '',
            region: '',
            city: '',
            visitor_type: '',
            device: '',
            browser: '',
            os: '',
            last_seen_from: '',
            last_seen_to: '',
        };

        state.page =
            1;

        state.sort =
            'last_seen';

        state.direction =
            'DESC';

        hideStatus();

        loadFilterOptions();
        loadVisitors();
    }

    function changeSort(
        field
    ) {
        if (!field) {
            return;
        }

        if (
            state.sort
            === field
        ) {
            state.direction =
                state.direction === 'ASC'
                    ? 'DESC'
                    : 'ASC';
        } else {
            state.sort =
                field;

            state.direction =
                'ASC';
        }

        state.page =
            1;

        loadVisitors();
    }

    function goToPreviousPage() {
        if (
            state.loading
            || state.page <= 1
        ) {
            return;
        }

        state.page -=
            1;

        loadVisitors();
    }

    function goToNextPage() {
        if (
            state.loading
            || state.page >= state.totalPages
        ) {
            return;
        }

        state.page +=
            1;

        loadVisitors();
    }

    function renderDetails(
        visitor
    ) {
        if (
            !elements.details
            || !elements.detailsContent
        ) {
            return;
        }

        const visitorId =
            getVisitorId(
                visitor
            );

        elements.detailsContent.innerHTML = `
            <div class="vi-card">
                <h3>Identity</h3>

                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <th>Database ID</th>
                            <td>${escapeHtml(visitor?.id)}</td>
                        </tr>

                        <tr>
                            <th>Visitor ID</th>
                            <td>${escapeHtml(visitorId)}</td>
                        </tr>

                        <tr>
                            <th>First Seen</th>
                            <td>${escapeHtml(visitor?.first_seen)}</td>
                        </tr>

                        <tr>
                            <th>Last Seen</th>
                            <td>${escapeHtml(visitor?.last_seen)}</td>
                        </tr>

                        <tr>
                            <th>Sessions</th>
                            <td>${escapeHtml(visitor?.sessions_count)}</td>
                        </tr>

                        <tr>
                            <th>Pageviews</th>
                            <td>${escapeHtml(visitor?.pageviews_count)}</td>
                        </tr>

                        <tr>
                            <th>Active Time</th>
                            <td>${escapeHtml(visitor?.active_seconds)}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="vi-card">
                <h3>Geolocation</h3>

                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <th>Country Code</th>
                            <td>${escapeHtml(visitor?.country_code)}</td>
                        </tr>

                        <tr>
                            <th>Country</th>
                            <td>${escapeHtml(visitor?.country_name)}</td>
                        </tr>

                        <tr>
                            <th>Region Code</th>
                            <td>${escapeHtml(visitor?.region_code)}</td>
                        </tr>

                        <tr>
                            <th>Region / Province</th>
                            <td>${escapeHtml(visitor?.region_name)}</td>
                        </tr>

                        <tr>
                            <th>City</th>
                            <td>${escapeHtml(visitor?.city)}</td>
                        </tr>

                        <tr>
                            <th>Latitude</th>
                            <td>${escapeHtml(visitor?.latitude)}</td>
                        </tr>

                        <tr>
                            <th>Longitude</th>
                            <td>${escapeHtml(visitor?.longitude)}</td>
                        </tr>

                        <tr>
                            <th>GeoIP Source</th>
                            <td>${escapeHtml(visitor?.geo_source)}</td>
                        </tr>

                        <tr>
                            <th>GeoIP Database</th>
                            <td>${escapeHtml(visitor?.geo_database_version)}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="vi-card">
                <h3>Technology</h3>

                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <th>Device</th>
                            <td>${escapeHtml(visitor?.device_type)}</td>
                        </tr>

                        <tr>
                            <th>Browser</th>
                            <td>${escapeHtml(visitor?.browser)}</td>
                        </tr>

                        <tr>
                            <th>Browser Version</th>
                            <td>${escapeHtml(visitor?.browser_version)}</td>
                        </tr>

                        <tr>
                            <th>Operating System</th>
                            <td>${escapeHtml(visitor?.os)}</td>
                        </tr>

                        <tr>
                            <th>OS Version</th>
                            <td>${escapeHtml(visitor?.os_version)}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="vi-card">
                <h3>Bot Detection</h3>

                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <th>Bot Score</th>
                            <td>${escapeHtml(visitor?.bot_score)}</td>
                        </tr>

                        <tr>
                            <th>Classification</th>
                            <td>${escapeHtml(visitor?.bot_classification)}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        `;

        setVisible(
            elements.details,
            true
        );

        elements.details.scrollIntoView({
            behavior:
                'smooth',

            block:
                'start',
        });
    }

    async function loadVisitor(
        visitorId
    ) {
        if (!visitorId) {
            return;
        }

        try {
            const payload =
                await request(
                    config.apiEndpoint
                    + '/'
                    + encodeURIComponent(
                        visitorId
                    )
                );

            if (
                !payload
                || !payload.visitor
            ) {
                throw new Error(
                    config.strings?.error
                    || 'Visitor data is unavailable.'
                );
            }

            renderDetails(
                payload.visitor
            );
        } catch (
            error
        ) {
            showStatus(
                error.message
                || config.strings?.error
                || 'Unable to load visitor.',
                'error'
            );
        }
    }

    function synchronizeTopScroll() {
        if (
            !elements.topScroll
            || !elements.topScrollInner
            || !elements.tableContainer
        ) {
            return;
        }

        const table =
            document.getElementById(
                'vi-visitors-table'
            );

        if (!table) {
            return;
        }

        elements.topScrollInner.style.width =
            table.scrollWidth + 'px';

        if (
            elements.topScroll.dataset.bound
            === '1'
        ) {
            return;
        }

        elements.topScroll.dataset.bound =
            '1';

        let syncing =
            false;

        elements.topScroll.addEventListener(
            'scroll',
            function () {
                if (syncing) {
                    return;
                }

                syncing =
                    true;

                elements.tableContainer.scrollLeft =
                    elements.topScroll.scrollLeft;

                syncing =
                    false;
            }
        );

        elements.tableContainer.addEventListener(
            'scroll',
            function () {
                if (syncing) {
                    return;
                }

                syncing =
                    true;

                elements.topScroll.scrollLeft =
                    elements.tableContainer.scrollLeft;

                syncing =
                    false;
            }
        );

        window.addEventListener(
            'resize',
            function () {
                elements.topScrollInner.style.width =
                    table.scrollWidth + 'px';
            }
        );
    }

    function bindSortEvents() {
        const headers =
            document.querySelectorAll(
                '#vi-visitors-table .vi-sortable'
            );

        headers.forEach(
            function (header) {
                header.addEventListener(
                    'click',
                    function () {
                        changeSort(
                            header.dataset.sort
                        );
                    }
                );
            }
        );
    }

    function bindEvents() {
        if (elements.refresh) {
            elements.refresh.addEventListener(
                'click',
                function () {
                    loadFilterOptions();
                    loadVisitors();
                }
            );
        }

        if (elements.export) {
            elements.export.addEventListener(
                'click',
                exportVisitors
            );
        }

        if (elements.applyFilters) {
            elements.applyFilters.addEventListener(
                'click',
                applyFilters
            );
        }

        if (elements.resetFilters) {
            elements.resetFilters.addEventListener(
                'click',
                resetFilters
            );
        }

        if (elements.previous) {
            elements.previous.addEventListener(
                'click',
                goToPreviousPage
            );
        }

        if (elements.next) {
            elements.next.addEventListener(
                'click',
                goToNextPage
            );
        }

        if (elements.tableBody) {
            elements.tableBody.addEventListener(
                'click',
                function (event) {
                    const button =
                        event.target.closest(
                            '.vi-visitor-link'
                        );

                    if (!button) {
                        return;
                    }

                    const visitorId =
                        button.dataset.visitorId
                        || '';

                    loadVisitor(
                        visitorId
                    );
                }
            );
        }

        const search =
            elements.search;

        if (search) {
            search.addEventListener(
                'keydown',
                function (event) {
                    if (
                        event.key
                        !== 'Enter'
                    ) {
                        return;
                    }

                    event.preventDefault();

                    applyFilters();
                }
            );
        }

        bindSortEvents();
    }

    function csvValue(value) {
        if (
            value === null
            || value === undefined
        ) {
            return '';
        }

        return String(value)
            .replaceAll('"', '""');
    }

    function getExportRows(visitors) {
        return visitors.map(
            function (visitor) {
                return [
                    visitor?.id ?? '',
                    getVisitorId(visitor),
                    visitor?.last_seen ?? '',
                    visitor?.first_seen ?? '',
                    visitor?.country_name
                        ?? visitor?.country_code
                        ?? '',
                    visitor?.region_name
                        ?? visitor?.region_code
                        ?? '',
                    visitor?.city ?? '',
                    visitor?.device_type ?? '',
                    visitor?.browser ?? '',
                    visitor?.browser_version ?? '',
                    visitor?.os ?? '',
                    visitor?.os_version ?? '',
                    visitor?.sessions_count ?? '',
                    visitor?.pageviews_count ?? '',
                    visitor?.bot_classification ?? '',
                ];
            }
        );
    }

    function createCsv(visitors) {
        const header = [
            'ID',
            'Visitor ID',
            'Last Seen',
            'First Seen',
            'Country',
            'Province / Region',
            'City',
            'Device',
            'Browser',
            'Browser Version',
            'OS',
            'OS Version',
            'Sessions',
            'Pageviews',
            'Type',
        ];

        const rows = [
            header,
            ...getExportRows(visitors),
        ];

        return rows
            .map(
                function (row) {
                    return row
                        .map(
                            function (value) {
                                return '"' +
                                    csvValue(value) +
                                    '"';
                            }
                        )
                        .join(',');
                }
            )
            .join('\r\n');
    }

    function downloadCsv(
        csv,
        filename
    ) {
        const blob = new Blob(
            [
                '\uFEFF' + csv,
            ],
            {
                type:
                    'text/csv;charset=utf-8;',
            }
        );

        const url =
            URL.createObjectURL(blob);

        const link =
            document.createElement('a');

        link.href = url;

        link.download =
            filename;

        document.body.appendChild(
            link
        );

        link.click();

        link.remove();

        URL.revokeObjectURL(
            url
        );
    }

    async function exportVisitors() {
        if (
            !config.apiEndpoint
        ) {
            showStatus(
                'Visitors API is not configured.',
                'error'
            );

            return;
        }

        const button =
            elements.export
            || document.getElementById(
                'vi-visitors-export'
            );

        if (button) {
            button.disabled = true;
            button.textContent = 'Exporting...';
        }

        const originalPage =
            state.page;

        const originalPerPage =
            state.perPage;

        try {
            state.page = 1;
            state.perPage = 50;

            const allVisitors = [];

            let totalPages = 1;

            while (state.page <= totalPages) {
                const url =
                    getVisitorsUrl();

                const payload =
                    await request(url);

                const visitors =
                    Array.isArray(payload?.items)
                        ? payload.items
                        : Array.isArray(payload?.data)
                            ? payload.data
                            : Array.isArray(payload?.visitors)
                                ? payload.visitors
                                : [];

                if (visitors.length === 0) {
                    break;
                }

                allVisitors.push(
                    ...visitors
                );

                totalPages =
                    Number(
                        payload?.total_pages
                        ?? payload?.meta?.total_pages
                        ?? 1
                    );

                if (state.page >= totalPages) {
                    break;
                }

                state.page += 1;
            }

            if (
                allVisitors.length === 0
            ) {
                showStatus(
                    'No visitors available for export.',
                    'error'
                );

                return;
            }

            const csv =
                createCsv(
                    allVisitors
                );

            const now =
                new Date();

            const timestamp =
                now
                    .toISOString()
                    .replaceAll(
                        ':',
                        '-'
                    )
                    .replace(
                        /\.\d{3}Z$/,
                        ''
                    );

            downloadCsv(
                csv,
                'visitor-intelligence-visitors-' +
                    timestamp +
                    '.csv'
            );

        } catch (error) {
            showStatus(
                error?.message
                || 'Unable to export visitors.',
                'error'
            );

        } finally {
            state.page = originalPage;
            state.perPage = originalPerPage;

            if (button) {
                button.disabled = false;
                button.textContent = 'Export CSV';
            }
        }
    }

    function initialize() {
        bindEvents();

        loadFilterOptions();

        loadVisitors();
    }

    if (
        document.readyState
        === 'loading'
    ) {
        document.addEventListener(
            'DOMContentLoaded',
            initialize
        );
    } else {
        initialize();
    }
})();