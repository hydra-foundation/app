//==========================================================
// hx-csp.js
//
// CSP enforcement extension for htmx.
//
// Provides three layers of Content Security Policy integration:
//
// 1. Nonce gating: gates htmx attribute processing behind CSP
//    nonces to prevent HTML injection attacks. Every htmx element
//    must carry an hx-nonce attribute matching the page nonce or
//    its htmx attributes are stripped. Fail closed if no page
//    nonce is found. Also re-checks nonce presence before internal eval
//    to also cover extension eval use like hx-live.
//    Nonce source: script[nonce].nonce property on page load; a
//    swapped fragment's own nonce comes from either policy header
//    (enforcing or report-only) and is rewritten to match.
//
// 2. Trusted Types: creates an 'htmx' TT policy (passthrough;
//    trust established by the nonce gate). Add trusted-types htmx
//    to your CSP to enforce that only htmx touches DOM sinks.
//    Fail closed if policy creation is blocked by CSP.
//
// 3. Safe eval: set config.safeEval:true to replace htmx's
//    Function/AsyncFunction with nonce-based script injection,
//    enabling hx-on:/hx-vals js:/hx-confirm js: without
//    unsafe-eval in your CSP.
//
// Usage:
//   <meta name="htmx-config" content='extensions:"hx-csp"'>
//   <meta name="htmx-config" content='extensions:"hx-csp",safeEval:true'>
//   <script src="hx-csp.js"></script>
//
//   Server stamps hx-nonce on every htmx element:
//   <button hx-post="/save" hx-nonce="<csp-nonce>">Save</button>
//==========================================================
(() => {

    let pageNonce = null;
    let ttPolicy = null;
    let internalApi = null;

    function getNonce(elt) {
        return internalApi?.attributeValue(elt, 'hx-nonce');
    }

    function checkNonce(elt) {
        if (!pageNonce) return false;
        let eltNonce = getNonce(elt);
        if (eltNonce !== pageNonce && stripHxAttributes(elt, eltNonce)) return false;
    }

    // Anchors to script-src/default-src to avoid matching nonces in other CSP directives
    function extractNonceFromCSP(csp) {
        return csp?.match(/(?:script-src|default-src)[^;]*'nonce-([^']+)'/i)?.[1] ?? null;
    }

    // Both policy headers, because report-only is the mode a policy is rolled
    // out in and its nonces are just as real. Reading only the enforcing header
    // leaves every fragment carrying a nonce this page will not recognise, so
    // the gate strips the very content the rollout was meant to observe.
    function extractNonceFromHeaders(headers) {
        return extractNonceFromCSP(headers?.get('Content-Security-Policy'))
            ?? extractNonceFromCSP(headers?.get('Content-Security-Policy-Report-Only'));
    }

    // Fallback: parse raw response HTML and extract nonce from meta CSP tag in <head>.
    // Only used for full-page responses with no CSP header.
    function extractNonceFromMetaTag(text) {
        let doc = Document.parseHTMLUnsafe(ttPolicy.createHTML(text));
        let meta = doc.head?.querySelector('meta[http-equiv="Content-Security-Policy"]');
        return extractNonceFromCSP(meta?.content);
    }

    // Matches a nonce attribute by NAME, not by the value we happen to be
    // looking for. Anchoring on `nonce=<value>` only ever caught one spelling:
    // HTML allows whitespace either side of the '=' and three ways to quote the
    // value, so `hx-nonce = "..."` walked straight past the scrub below, and
    // whoever holds a stolen nonce is the one choosing how to spell it.
    // The unquoted alternative keeps '=', which the parser appends to the value
    // even though the spec calls it an error. Excluding it truncated a base64
    // nonce at its own padding, which is a bypass wearing the fix's clothes.
    // The characters it does exclude cannot appear in base64, so no nonce can
    // hide behind one.
    const NONCE_ATTRIBUTE = /(\s(?:[a-z0-9-]*-)?nonce)(\s*=\s*)("[^"]*"|'[^']*'|[^\s"'<>`]*)/gi;

    // A nonce the server wrote is base64. Anything else cannot be one this page
    // will honour, and that is the point: `&#114;4nd0m...` is how a stolen
    // nonce is spelled to survive a scrub that compares raw bytes, and the
    // parser decodes it back into the real thing on the way into the DOM.
    const NONCE_SHAPED = /^[A-Za-z0-9+/=_-]*$/;

    // The value as the parser will read it. An unquoted one is already bare.
    function attributeValue(raw) {
        return /^["']/.test(raw) ? raw.slice(1, -1) : raw;
    }

    // Rewrites responseNonce -> replacement in raw HTML before DOM parsing,
    // covering hx-nonce and script nonce attributes in one pass.
    // Pass replacement='' to strip nonce attributes entirely (stolen-nonce scrub).
    // A nonce-shaped value that is not the one asked about is left alone; one
    // that is not nonce-shaped is dropped, since nothing legitimate writes a
    // nonce the parser has to decode first.
    function rewriteNoncesInText(text, responseNonce, replacement = pageNonce) {
        return text.replace(NONCE_ATTRIBUTE, (attribute, name, _equals, raw) => {
            let value = attributeValue(raw);

            if (value === responseNonce) {
                return replacement ? `${name}="${replacement}"` : '';
            }

            return NONCE_SHAPED.test(value) ? attribute : '';
        });
    }

    // Strips all hx- attributes, fires htmx:security:strip, returns true if anything stripped.
    function stripHxAttributes(elt, eltNonce) {
        let stripped = [];
        for (let attr of [...elt.attributes]) {
            if (attr.name.startsWith('hx-') || (htmx.config.prefix && attr.name.startsWith(htmx.config.prefix))) {
                stripped.push(attr.name);
                elt.removeAttribute(attr.name);
            }
        }
        if (!stripped.length) return false;
        let tag = elt.tagName?.toLowerCase();
        let id = elt.id ? `#${elt.id}` : '';
        let reason = eltNonce == null ? 'missing-nonce' : 'nonce-mismatch';
        console.error(`htmx: [hx-csp] blocked <${tag}${id}>: ${eltNonce == null ? 'no hx-nonce attribute' : 'nonce mismatch (possible injection)'}`, { elt, reason });
        htmx.trigger(elt, 'htmx:security:strip', { reason, stripped });
        return true;
    }

    htmx.registerExtension('hx-csp', {

        init: (api) => {
            internalApi = api;

            // .nonce property stays readable after browsers blank the attribute
            // to prevent CSS exfiltration attacks.
            pageNonce = document.querySelector('script[nonce]')?.nonce || null;

            if (!pageNonce) {
                console.error('htmx: [hx-csp] no page nonce found, blocking all htmx. Add a nonce to your script tags.');
                return;
            }

            // Passthrough TT policy (trust established by nonce gate).
            // Fail closed if 'htmx' is not in the trusted-types CSP whitelist.
            try {
                ttPolicy = typeof trustedTypes !== 'undefined'
                    ? trustedTypes.createPolicy('htmx', { createHTML: s => s, createScript: s => s })
                    : { createHTML: s => s, createScript: s => s };
            } catch (e) {
                console.error("htmx: [hx-csp] TrustedTypes policy 'htmx' blocked, add 'htmx' to trusted-types CSP directive. Blocking all htmx.");
                pageNonce = null;
                return;
            }

            let counter = 0;
            let NativeFunction = Function;
            let NativeAsyncFunction = Object.getPrototypeOf(async function(){}).constructor;
            // Cache compiled script-injected functions by "keys|body" so each unique
            // expression is only injected once (critical for hx-live which re-evaluates
            // the same expressions on every DOM/input tick.
            let safeEvalCache = new Map();

            function makeGatedConstructor(isAsync) {
                return function(...keys) {
                    let body = keys.pop();
                    return {
                        call: (thisArg, ...values) => {
                            if (getNonce(thisArg) !== pageNonce) {
                                let tag = thisArg?.tagName?.toLowerCase();
                                let id = thisArg?.id ? `#${thisArg.id}` : '';
                                console.error(`htmx: [hx-csp] blocked eval on <${tag}${id}>: nonce mismatch`, { elt: thisArg });
                                htmx.trigger(thisArg, 'htmx:security:violation', { reason: 'nonce-mismatch-at-eval' });
                                return;
                            }
                            if (htmx.config.safeEval) {
                                let cacheKey = keys.join(',') + '|' + body;
                                let compiled = safeEvalCache.get(cacheKey);
                                if (!compiled) {
                                    let fn = `__htmx_eval_${++counter}`;
                                    let script = document.createElement('script');
                                    script.nonce = pageNonce;
                                    script.textContent = ttPolicy.createScript(`window.${fn} = ${isAsync ? 'async ' : ''}function(${keys.join(',')}) { ${body} }`);
                                    document.head.appendChild(script);
                                    script.remove();
                                    compiled = window[fn];
                                    delete window[fn];
                                    safeEvalCache.set(cacheKey, compiled);
                                }
                                return compiled.call(thisArg, ...values);
                            }
                            let Ctor = isAsync ? NativeAsyncFunction : NativeFunction;
                            return new Ctor(...keys, body).call(thisArg, ...values);
                        }
                    };
                };
            }

            api.initSecurity(ttPolicy, makeGatedConstructor(false), makeGatedConstructor(true));
        },

        htmx_before_init: checkNonce,

        htmx_before_on_init: checkNonce,

        // Rewrites response nonces to pageNonce in raw HTML before fragment parsing.
        // Always scrubs stolen pageNonce. Only promotes response nonce for verified same-origin.
        htmx_after_request: (elt, detail) => {
            if (!pageNonce) return false;
            let ctx = detail.ctx;

            // Always scrub stolen pageNonce from any response. The server cannot know the
            // page nonce, so its presence indicates a stolen-nonce injection attempt.
            ctx.text = rewriteNoncesInText(ctx.text, pageNonce, '');

            // Only promote response nonce for verified same-origin responses
            let responseURL = ctx?.response?.raw?.url;
            if (!responseURL) return;  // can't verify origin; scrub only, no promotion
            try { if (new URL(responseURL).origin !== location.origin) return; }
            catch (_) { return; }

            let responseNonce = extractNonceFromHeaders(ctx?.response?.headers)
                             ?? extractNonceFromMetaTag(ctx?.text);
            if (responseNonce && responseNonce !== pageNonce) {
                ctx.text = rewriteNoncesInText(ctx.text, responseNonce);
            }
        },

        // Blocks boosted form submissions where an unnonced submitter overrides formaction.
        // Also blocks js:/javascript: action URLs (entity encoding doesn't neutralise these
        // so they may survive template rendering and execute unexpectedly.
        htmx_config_request: (elt, detail) => {
            if (!pageNonce) return false;
            let action = detail.ctx?.request?.action;
            if (action && /^(js|javascript):/i.test(action)) {
                console.error(`htmx: [hx-csp] blocked js:/javascript: action URL on <${elt.tagName.toLowerCase()}${elt.id ? '#'+elt.id : ''}>`, { elt });
                htmx.trigger(elt, 'htmx:security:violation', { reason: 'javascript-url', action, ctx: detail.ctx });
                detail.cancelled = true;
                return false;
            }
            let submitter = detail.ctx?.sourceEvent?.submitter;
            if (!elt._htmx?.boosted || !submitter?.getAttribute('formaction')) return;
            if (getNonce(submitter) !== pageNonce) {
                let id = submitter?.id ? `#${submitter.id}` : '';
                console.error(`htmx: [hx-csp] blocked boosted form: unnonced submitter${id} overrode formaction`);
                htmx.trigger(elt, 'htmx:security:violation', { reason: 'unnonced-submitter', submitter, ctx: detail.ctx });
                detail.cancelled = true;
                return false;
            }
        }
    });

})();
