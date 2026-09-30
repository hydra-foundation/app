/* Server directives.
 *
 * htmx 4 reads no response header. HX-Redirect, HX-Push-Url and the rest are
 * all gone, so the only thing a server can reach the client with is the body
 * it swaps. Hydra\Http\HtmxResponse writes directives into that body as one
 * hidden marker element and this applies them. A retarget needs nothing here:
 * it is an out-of-band element, which htmx understands on its own.
 *
 * Travels with app.css if the framework ever grows a way to publish assets.
 */
(function () {
    'use strict';

    const REDIRECT = 'data-hydra-redirect';
    const PUSH = 'data-hydra-push-url';
    const REPLACE = 'data-hydra-replace-url';
    const MARKER = '[' + REDIRECT + '],[' + PUSH + '],[' + REPLACE + ']';

    /* Consumed, not just read: a marker left in the document would be applied
       again by the next swap that lands somewhere else. */
    function take(scope) {
        const marker = scope?.querySelector(MARKER);

        if (!marker) {
            return null;
        }

        marker.remove();

        return marker;
    }

    function navigate(url) {
        window.location.assign(url);
    }

    /* Caught before the swap so the element that made the request keeps what it
       was showing while the browser leaves the page. Cancelling here skips every
       swap the response would have made. */
    document.addEventListener('htmx:before:swap', function (event) {
        const text = event.detail?.ctx?.text;

        if (typeof text !== 'string' || !text.includes(REDIRECT)) {
            return;
        }

        const url = take(new DOMParser().parseFromString(text, 'text/html').body)
            ?.getAttribute(REDIRECT);

        if (url) {
            event.preventDefault();
            navigate(url);
        }
    });

    document.addEventListener('htmx:after:swap', function () {
        const marker = take(document);

        if (!marker) {
            return;
        }

        /* Only set if the directive survived the swap: the handler above reads
           an htmx internal, and a redirect must not be lost if it is renamed. */
        const url = marker.getAttribute(REDIRECT);

        if (url) {
            navigate(url);

            return;
        }

        const push = marker.getAttribute(PUSH);
        const replace = marker.getAttribute(REPLACE);

        if (push) {
            window.history.pushState({}, '', push);
        }

        if (replace) {
            window.history.replaceState({}, '', replace);
        }
    });
})();

/* Live updates.
 *
 * An element that says data-stream="module.users" (space-separated for more)
 * hears that topic: every broadcast on it arrives as a DOM event,
 * sse:module.users, dispatched on the element, so hx-trigger="sse:module.users"
 * can refetch whatever it shows. The event's detail is {event, data}.
 *
 * One connection per page carries every topic the page asks for. Its token
 * comes from /stream/token, never from the page, and a fresh one is fetched
 * whenever the hub closes the stream: that is how an expired token (the hub
 * answers 204) and a restarted hub are both recovered from. After the hub
 * reconnects to Redis it sends hub.resync, passed on as {event: 'resync'} to
 * every topic, since events may have been missed.
 *
 * Nothing here errors when there is no hub, as under `php -S`: after five
 * closes in a row without the stream ever opening, it stops trying.
 */
(function () {
    'use strict';

    const ATTR = 'data-stream';
    const GIVE_UP_AFTER = 5;
    const MAX_DELAY = 30000;

    let source = null;
    let topics = [];
    let failures = 0;
    let timer = null;
    let stopped = false;

    function topicsOf(element) {
        return (element.getAttribute(ATTR) || '').split(/\s+/).filter(Boolean);
    }

    function wanted() {
        const all = new Set();

        document.querySelectorAll('[' + ATTR + ']').forEach(function (element) {
            topicsOf(element).forEach(function (topic) { all.add(topic); });
        });

        return Array.from(all).sort();
    }

    /* Looked up at dispatch rather than remembered: a listening element that
       an htmx swap replaced is a new element, and the old one is gone. */
    function dispatch(topic, detail) {
        document.querySelectorAll('[' + ATTR + ']').forEach(function (element) {
            if (topicsOf(element).includes(topic)) {
                element.dispatchEvent(new CustomEvent('sse:' + topic, { detail: detail }));
            }
        });
    }

    function close() {
        clearTimeout(timer);

        if (source) {
            source.close();
            source = null;
        }
    }

    function retry() {
        close();
        failures += 1;

        if (failures >= GIVE_UP_AFTER) {
            stopped = true;

            return;
        }

        timer = setTimeout(connect, Math.min(1000 * 2 ** (failures - 1), MAX_DELAY));
    }

    async function connect() {
        close();
        topics = wanted();

        if (stopped || topics.length === 0) {
            return;
        }

        let response;

        try {
            response = await fetch('/stream/token?topics=' + encodeURIComponent(topics.join(',')), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
        } catch (error) {
            retry();

            return;
        }

        /* Signed out, or asking for a topic this user may not hear: another
           try would be told the same. */
        if (response.status === 401 || response.status === 403 || response.status === 422) {
            stopped = true;

            return;
        }

        if (!response.ok) {
            retry();

            return;
        }

        const grant = await response.json();

        source = new EventSource(grant.url);

        source.addEventListener('open', function () { failures = 0; });

        topics.forEach(function (topic) {
            source.addEventListener(topic, function (event) {
                let detail = {};

                try {
                    detail = JSON.parse(event.data);
                } catch (error) {
                    return;
                }

                dispatch(topic, detail);
            });
        });

        source.addEventListener('hub.resync', function () {
            topics.forEach(function (topic) { dispatch(topic, { event: 'resync', data: {} }); });
        });

        /* CONNECTING is the browser retrying a dropped connection with the
           same token, which it may; CLOSED is a refusal it will not retry. */
        source.addEventListener('error', function () {
            if (source && source.readyState === EventSource.CLOSED) {
                retry();
            }
        });
    }

    /* A swap that brings in a topic the stream does not carry needs a wider
       grant. One that only drops topics does not: the extras cost nothing. */
    document.addEventListener('htmx:after:swap', function () {
        if (wanted().some(function (topic) { return !topics.includes(topic); })) {
            failures = 0;
            stopped = false;
            connect();
        }
    });

    window.addEventListener('pagehide', function () {
        stopped = true;
        close();
    });

    /* Back from the back/forward cache: the stream was closed on the way out. */
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            failures = 0;
            stopped = false;
            connect();
        }
    });

    connect();
})();
