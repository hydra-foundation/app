/* QR codes.
 *
 * Any element with a data-qr attribute is drawn as a QR code of its value, on
 * page load and after every swap. The library is fetched only when a page has
 * one, from the pinned cdnjs build, and its integrity is checked: the policy
 * allows that host for scripts, and SRI holds it to this exact file. Nothing
 * inline, so a fragment swapped in needs no nonce to get its code drawn.
 */
(function () {
    'use strict';

    const LIBRARY = 'https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js';
    const INTEGRITY = 'sha384-mZT2gIty7ZDdOGkxfP6joZcYdMW1Jvj9dRlfpTmaJAKKXTqzygtB22k7FLe+KZC1';

    /* Four modules of quiet zone, which scanners need to find the edges. */
    const CELL = 4;
    const MARGIN = 4 * CELL;

    let loading = null;

    function library() {
        if (typeof window.qrcode === 'function') {
            return Promise.resolve(window.qrcode);
        }

        loading ??= new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            script.src = LIBRARY;
            script.integrity = INTEGRITY;
            script.crossOrigin = 'anonymous';
            script.onload = function () { resolve(window.qrcode); };
            script.onerror = function () {
                loading = null;
                reject(new Error('Could not load the QR library.'));
            };
            document.head.appendChild(script);
        });

        return loading;
    }

    function draw() {
        const pending = document.querySelectorAll('[data-qr]:empty');

        if (pending.length === 0) {
            return;
        }

        /* The key is on the page as text too, so a failure leaves a way through. */
        library().then(function (qrcode) {
            pending.forEach(function (element) {
                const code = qrcode(0, 'M');
                code.addData(element.getAttribute('data-qr'));
                code.make();
                element.innerHTML = code.createSvgTag({ cellSize: CELL, margin: MARGIN, scalable: true });
            });
        }, function (error) {
            console.warn(error.message);
        });
    }

    document.addEventListener('DOMContentLoaded', draw);
    document.addEventListener('htmx:after:swap', draw);
})();
