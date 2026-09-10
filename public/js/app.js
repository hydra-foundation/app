/* Server directives.
 *
 * htmx 4 reads no response header — HX-Redirect, HX-Push-Url and the rest are
 * all gone — so the only thing a server can reach the client with is the body
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

        /* Only set if the directive survived the swap — the handler above reads
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
