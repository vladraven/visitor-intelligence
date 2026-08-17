(function(window, document) {
    'use strict';

    const context =
        window.viContext || {};

    if (
        !context.endpoint
        || !context.visitorId
        || !context.sessionId
    ) {
        return;
    }

    const ConsentState = {
        UNKNOWN: 'UNKNOWN',
        GRANTED: 'GRANTED',
        DENIED: 'DENIED'
    };

    const DEFAULTS = {
        heartbeatInterval: 10000,
        activityTimeout: 15000,
        batchSize: 10,
        maxQueueSize: 100,
        flushInterval: 15000,
        requestTimeout: 10000,
        maxPayloadBytes: 60000
    };

    const config =
        Object.assign(
            {},
            DEFAULTS,
            window.viCollectorConfig || {}
        );

    const eventTypes = {
        HEARTBEAT: 'heartbeat',
        PAGE_VISIBLE: 'page_visible',
        PAGE_HIDDEN: 'page_hidden',
        PAGE_LEAVE: 'page_leave'
    };

    let queue = [];

    let flushInProgress =
        false;

    let flushTimer =
        null;

    let heartbeatTimer =
        null;

    let activityTimer =
        null;

    let currentConsent =
        readConsent();

    let lastActivityAt =
        Date.now();

    let lastHeartbeatAt =
        Date.now();

    let pageVisible =
        document.visibilityState === 'visible';

    let pageStartedAt =
        Date.now();

    let pageLeaveSent =
        false;

    let currentPageviewId =
        normalizeUuid(
            context.pageviewId
        );

    function normalizeUuid(value) {
        if (
            typeof value !== 'string'
            || value.trim() === ''
        ) {
            return null;
        }

        const normalized =
            value.trim();

        const pattern =
            /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

        return pattern.test(
            normalized
        )
            ? normalized
            : null;
    }

    function uuid() {
        if (
            window.crypto
            && typeof window.crypto.randomUUID === 'function'
        ) {
            return window.crypto.randomUUID();
        }

        if (
            window.crypto
            && typeof window.crypto.getRandomValues === 'function'
        ) {
            const bytes =
                new Uint8Array(16);

            window.crypto.getRandomValues(
                bytes
            );

            bytes[6] =
                (bytes[6] & 0x0f)
                | 0x40;

            bytes[8] =
                (bytes[8] & 0x3f)
                | 0x80;

            return [
                hex(bytes, 0, 4),
                hex(bytes, 4, 6),
                hex(bytes, 6, 8),
                hex(bytes, 8, 10),
                hex(bytes, 10, 16)
            ].join('-');
        }

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'
            .replace(
                /[xy]/g,
                function(character) {
                    const random =
                        Math.random() * 16
                        | 0;

                    const value =
                        character === 'x'
                            ? random
                            : (
                                random & 0x3
                            ) | 0x8;

                    return value.toString(
                        16
                    );
                }
            );
    }

    function hex(
        bytes,
        start,
        end
    ) {
        let value = '';

        for (
            let index = start;
            index < end;
            index++
        ) {
            value +=
                bytes[index]
                    .toString(16)
                    .padStart(
                        2,
                        '0'
                    );
        }

        return value;
    }

    function readConsent() {
        try {
            const value =
                window.localStorage.getItem(
                    'vi_consent'
                );

            if (
                value === ConsentState.GRANTED
                || value === ConsentState.DENIED
            ) {
                return value;
            }
        } catch (error) {
            return ConsentState.UNKNOWN;
        }

        return ConsentState.UNKNOWN;
    }

    function writeConsent(value) {
        try {
            window.localStorage.setItem(
                'vi_consent',
                value
            );
        } catch (error) {
        }
    }

    window.ViConsent = {
        grant: function() {
            currentConsent =
                ConsentState.GRANTED;

            writeConsent(
                currentConsent
            );

            if (
                heartbeatTimer === null
                && flushTimer === null
            ) {
                startTimers();
            }

            flush();
        },

        revoke: function() {
            currentConsent =
                ConsentState.DENIED;

            writeConsent(
                currentConsent
            );

            queue = [];

            stopTimers();
        },

        getState: function() {
            return currentConsent;
        }
    };

    function isDenied() {
        return (
            currentConsent ===
            ConsentState.DENIED
        );
    }

    function isTrackingAllowed() {
        return !isDenied();
    }

    function registerActivity() {
        if (
            !isTrackingAllowed()
        ) {
            return;
        }

        lastActivityAt =
            Date.now();

        if (
            !pageVisible
        ) {
            return;
        }

        scheduleActivityReset();
    }

    function scheduleActivityReset() {
        if (
            activityTimer !== null
        ) {
            window.clearTimeout(
                activityTimer
            );
        }

        activityTimer =
            window.setTimeout(
                function() {
                    activityTimer =
                        null;
                },
                config.activityTimeout
            );
    }

    [
        'mousemove',
        'mousedown',
        'keydown',
        'scroll',
        'click',
        'touchstart',
        'pointerdown'
    ].forEach(
        function(eventName) {
            window.addEventListener(
                eventName,
                registerActivity,
                {
                    passive: true
                }
            );
        }
    );

    function isRecentlyActive(now) {
        return (
            now - lastActivityAt
        ) <= config.activityTimeout;
    }

    function formatUtcTimestamp(timestamp) {
        const date =
            new Date(
                timestamp
            );

        if (
            Number.isNaN(
                date.getTime()
            )
        ) {
            return null;
        }

        return (
            date.getUTCFullYear()
            + '-'
            + String(
                date.getUTCMonth() + 1
            ).padStart(2, '0')
            + '-'
            + String(
                date.getUTCDate()
            ).padStart(2, '0')
            + ' '
            + String(
                date.getUTCHours()
            ).padStart(2, '0')
            + ':'
            + String(
                date.getUTCMinutes()
            ).padStart(2, '0')
            + ':'
            + String(
                date.getUTCSeconds()
            ).padStart(2, '0')
        );
    }

    function createEvent(
        type,
        payload
    ) {
        const occurredAt =
            formatUtcTimestamp(
                Date.now()
            );

        if (
            !occurredAt
        ) {
            return null;
        }

        return {
            event_id:
                uuid(),

            pageview_id:
                currentPageviewId,

            event_type:
                type,

            occurred_at:
                occurredAt,

            schema_version:
                'v1',

            payload:
                payload
                && typeof payload === 'object'
                    ? payload
                    : {}
        };
    }

    function pushEvent(
        type,
        payload,
        options
    ) {
        options =
            options || {};

        if (
            !isTrackingAllowed()
        ) {
            return false;
        }

        const event =
            createEvent(
                type,
                payload
            );

        if (
            event === null
        ) {
            return false;
        }

        queue.push(
            event
        );

        enforceQueueLimit();

        if (
            !options.deferFlush
            && queue.length >= config.batchSize
        ) {
            flush();
        }

        return true;
    }

    function enforceQueueLimit() {
        if (
            queue.length <=
            config.maxQueueSize
        ) {
            return;
        }

        queue =
            queue.slice(
                queue.length
                - config.maxQueueSize
            );
    }

    function calculateActiveDelta(now) {
        if (
            !pageVisible
        ) {
            return 0;
        }

        if (
            !isRecentlyActive(now)
        ) {
            return 0;
        }

        const elapsed =
            Math.floor(
                (
                    now
                    - lastHeartbeatAt
                ) / 1000
            );

        if (
            elapsed <= 0
        ) {
            return 0;
        }

        return Math.min(
            elapsed,
            Math.max(
                1,
                Math.ceil(
                    config.heartbeatInterval
                    / 1000
                ) + 1
            )
        );
    }

    function sendHeartbeat() {
        if (
            !isTrackingAllowed()
            || !pageVisible
        ) {
            return;
        }

        const now =
            Date.now();

        const activeDelta =
            calculateActiveDelta(
                now
            );

        lastHeartbeatAt =
            now;

        if (
            activeDelta <= 0
        ) {
            return;
        }

        pushEvent(
            eventTypes.HEARTBEAT,
            {
                active_delta:
                    activeDelta,

                visible:
                    true
            }
        );
    }

    function startTimers() {
        stopTimers();

        heartbeatTimer =
            window.setInterval(
                sendHeartbeat,
                config.heartbeatInterval
            );

        flushTimer =
            window.setInterval(
                flush,
                config.flushInterval
            );
    }

    function stopTimers() {
        if (
            heartbeatTimer !== null
        ) {
            window.clearInterval(
                heartbeatTimer
            );

            heartbeatTimer =
                null;
        }

        if (
            flushTimer !== null
        ) {
            window.clearInterval(
                flushTimer
            );

            flushTimer =
                null;
        }

        if (
            activityTimer !== null
        ) {
            window.clearTimeout(
                activityTimer
            );

            activityTimer =
                null;
        }
    }

    function buildPayload(events) {
        return JSON.stringify(
            {
                visitor_id:
                    context.visitorId,

                session_id:
                    context.sessionId,

                tracking_mode:
                    context.trackingMode
                    || 'full',

                events:
                    events
            }
        );
    }

    function takeBatch() {
        if (
            queue.length === 0
        ) {
            return [];
        }

        return queue.slice(
            0,
            config.batchSize
        );
    }

    function removeBatch(batch) {
        if (
            batch.length === 0
        ) {
            return;
        }

        const ids =
            new Set(
                batch.map(
                    function(event) {
                        return event.event_id;
                    }
                )
            );

        queue =
            queue.filter(
                function(event) {
                    return !ids.has(
                        event.event_id
                    );
                }
            );
    }

    function getPayloadSize(events) {
        try {
            return new Blob(
                [
                    buildPayload(
                        events
                    )
                ]
            ).size;
        } catch (error) {
            return Infinity;
        }
    }

    function takeSafeBatch() {
        const batch = [];

        for (
            let index = 0;
            index < queue.length
                && index < config.batchSize;
            index++
        ) {
            const candidate =
                batch.concat(
                    queue[index]
                );

            if (
                getPayloadSize(
                    candidate
                ) > config.maxPayloadBytes
            ) {
                break;
            }

            batch.push(
                queue[index]
            );
        }

        if (
            batch.length > 0
        ) {
            return batch;
        }

        return [
            queue[0]
        ];
    }

    function flush() {
        if (
            flushInProgress
            || queue.length === 0
            || !isTrackingAllowed()
        ) {
            return;
        }

        const batch =
            takeSafeBatch();

        if (
            batch.length === 0
        ) {
            return;
        }

        const payloadString =
            buildPayload(
                batch
            );

        flushInProgress =
            true;

        if (
            navigator.sendBeacon
            && document.visibilityState === 'hidden'
        ) {
            sendWithBeacon(
                batch,
                payloadString
            );

            return;
        }

        sendWithFetch(
            batch,
            payloadString
        );
    }

    function sendWithBeacon(
        batch,
        payloadString
    ) {
        try {
            const blob =
                new Blob(
                    [
                        payloadString
                    ],
                    {
                        type:
                            'application/json'
                    }
                );

            const accepted =
                navigator.sendBeacon(
                    context.endpoint,
                    blob
                );

            if (
                accepted
            ) {
                removeBatch(
                    batch
                );
            }

            flushInProgress =
                false;
        } catch (error) {
            flushInProgress =
                false;
        }
    }

    function sendWithFetch(
        batch,
        payloadString
    ) {
        const controller =
            typeof AbortController === 'function'
                ? new AbortController()
                : null;

        let timeout =
            null;

        if (
            controller
        ) {
            timeout =
                window.setTimeout(
                    function() {
                        controller.abort();
                    },
                    config.requestTimeout
                );
        }

        const options = {
            method:
                'POST',

            headers: {
                'Content-Type':
                    'application/json'
            },

            body:
                payloadString,

            keepalive:
                true
        };

        if (
            controller
        ) {
            options.signal =
                controller.signal;
        }

        fetch(
            context.endpoint,
            options
        )
            .then(
                function(response) {
                    if (
                        !response.ok
                    ) {
                        throw new Error(
                            'Telemetry request failed with HTTP status '
                            + response.status
                        );
                    }

                    removeBatch(
                        batch
                    );
                }
            )
            .catch(
                function() {
                }
            )
            .finally(
                function() {
                    if (
                        timeout !== null
                    ) {
                        window.clearTimeout(
                            timeout
                        );
                    }

                    flushInProgress =
                        false;

                    if (
                        queue.length >=
                        config.batchSize
                    ) {
                        window.setTimeout(
                            flush,
                            0
                        );
                    }
                }
            );
    }

    function handleVisibilityChange() {
        const now =
            Date.now();

        if (
            document.visibilityState === 'hidden'
        ) {
            if (
                pageVisible
            ) {
                const activeDelta =
                    calculateActiveDelta(
                        now
                    );

                if (
                    activeDelta > 0
                ) {
                    pushEvent(
                        eventTypes.HEARTBEAT,
                        {
                            active_delta:
                                activeDelta,

                            visible:
                                true,

                            final:
                                true
                        },
                        {
                            deferFlush:
                                true
                        }
                    );
                }

                pageVisible =
                    false;

                pushEvent(
                    eventTypes.PAGE_HIDDEN,
                    {
                        visible:
                            false
                    },
                    {
                        deferFlush:
                            true
                    }
                );

                flush();
            }

            return;
        }

        if (
            document.visibilityState === 'visible'
        ) {
            pageVisible =
                true;

            lastActivityAt =
                now;

            lastHeartbeatAt =
                now;

            if (
                isTrackingAllowed()
            ) {
                pushEvent(
                    eventTypes.PAGE_VISIBLE,
                    {
                        visible:
                            true
                    }
                );
            }
        }
    }

    function handlePageLeave() {
        if (
            pageLeaveSent
            || !isTrackingAllowed()
        ) {
            return;
        }

        pageLeaveSent =
            true;

        const now =
            Date.now();

        if (
            pageVisible
        ) {
            const activeDelta =
                calculateActiveDelta(
                    now
                );

            if (
                activeDelta > 0
            ) {
                pushEvent(
                    eventTypes.HEARTBEAT,
                    {
                        active_delta:
                            activeDelta,

                        visible:
                            true,

                        final:
                            true
                    },
                    {
                        deferFlush:
                            true
                    }
                );
            }
        }

        pushEvent(
            eventTypes.PAGE_LEAVE,
            {
                duration_seconds:
                    Math.max(
                        0,
                        Math.floor(
                            (
                                now
                                - pageStartedAt
                            ) / 1000
                        )
                    )
            },
            {
                deferFlush:
                    true
            }
        );

        flush();
    }

    function handlePageShow() {
        pageLeaveSent =
            false;

        pageStartedAt =
            Date.now();

        lastActivityAt =
            pageStartedAt;

        lastHeartbeatAt =
            pageStartedAt;

        pageVisible =
            document.visibilityState === 'visible';

        if (
            pageVisible
            && isTrackingAllowed()
        ) {
            pushEvent(
                eventTypes.PAGE_VISIBLE,
                {
                    visible:
                        true,

                    initial:
                        true
                }
            );
        }
    }

    function handleNavigation() {
        if (
            !isTrackingAllowed()
        ) {
            return;
        }

        const now =
            Date.now();

        if (
            pageVisible
            && !pageLeaveSent
        ) {
            const activeDelta =
                calculateActiveDelta(
                    now
                );

            if (
                activeDelta > 0
            ) {
                pushEvent(
                    eventTypes.HEARTBEAT,
                    {
                        active_delta:
                            activeDelta,

                        visible:
                            true,

                        final:
                            true
                    },
                    {
                        deferFlush:
                            true
                    }
                );
            }

            pushEvent(
                eventTypes.PAGE_LEAVE,
                {
                    duration_seconds:
                        Math.max(
                            0,
                            Math.floor(
                                (
                                    now
                                    - pageStartedAt
                                ) / 1000
                            )
                        ),

                    navigation:
                        true
                },
                {
                    deferFlush:
                        true
                }
            );
        }

        pageLeaveSent =
            false;

        pageStartedAt =
            now;

        lastActivityAt =
            now;

        lastHeartbeatAt =
            now;

        pushEvent(
            eventTypes.PAGE_VISIBLE,
            {
                visible:
                    document.visibilityState === 'visible',

                navigation:
                    true
            },
            {
                deferFlush:
                    true
            }
        );

        flush();
    }

    function patchHistoryMethod(
        methodName
    ) {
        if (
            !window.history
            || typeof window.history[
                methodName
            ] !== 'function'
        ) {
            return;
        }

        const original =
            window.history[
                methodName
            ];

        window.history[
            methodName
        ] =
            function() {
                const result =
                    original.apply(
                        this,
                        arguments
                    );

                handleNavigation();

                return result;
            };
    }

    patchHistoryMethod(
        'pushState'
    );

    patchHistoryMethod(
        'replaceState'
    );

    window.addEventListener(
        'popstate',
        handleNavigation
    );

    document.addEventListener(
        'visibilitychange',
        handleVisibilityChange
    );

    window.addEventListener(
        'pagehide',
        handlePageLeave,
        {
            capture:
                true
        }
    );

    window.addEventListener(
        'beforeunload',
        handlePageLeave,
        {
            capture:
                true
        }
    );

    window.addEventListener(
        'pageshow',
        handlePageShow
    );

    handlePageShow();

    if (
        isTrackingAllowed()
    ) {
        startTimers();
    }

})(window, document);