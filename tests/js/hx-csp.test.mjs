import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import assert from 'node:assert/strict';
import { before, describe, it } from 'node:test';

// The extension as it ships, loaded and registered the way htmx loads it, so
// what is tested is the wired path rather than a copy of the regex.
const EXTENSION = resolve(dirname(fileURLToPath(import.meta.url)), '../../public/js/vendor/hx-csp.js');

const PAGE_NONCE = 'r4nd0mNonceAbc+/=';
const RESPONSE_NONCE = 'serverNonce123';

let extension;

before(() => {
    globalThis.document = { querySelector: () => ({ nonce: PAGE_NONCE }) };
    // The extension compares the response origin against this one. Without it
    // the comparison throws into its own catch and nothing is ever promoted,
    // which reads exactly like a passing scrub.
    globalThis.location = { origin: 'https://hydra.test' };
    // With no CSP header the extension falls back to parsing the fragment for a
    // meta policy. These fragments carry none, so the parse finds nothing.
    globalThis.Document = { parseHTMLUnsafe: () => ({ head: { querySelector: () => null } }) };
    globalThis.htmx = {
        config: {},
        trigger: () => {},
        registerExtension: (_name, ext) => { extension = ext; },
    };

    new Function(readFileSync(EXTENSION, 'utf8'))();

    assert.ok(extension, 'the extension did not register');
    extension.init({
        attributeValue: (elt, name) => elt?.getAttribute?.(name),
        initSecurity: () => {},
    });
});

/** Run a response through the extension's after-request hook and return the text it leaves behind. */
function afterRequest(text, { nonce = null, url = 'https://hydra.test/fragment' } = {}) {
    const ctx = {
        text,
        response: {
            raw: { url },
            headers: {
                get: (name) => name === 'Content-Security-Policy' && nonce
                    ? `default-src 'self'; script-src 'nonce-${nonce}'`
                    : null,
            },
        },
    };

    extension.htmx_after_request(null, { ctx });

    return ctx.text;
}

describe('the stolen-nonce scrub', () => {
    // The page nonce cannot legitimately appear in a response: the server does
    // not know it. Its presence is an injection replaying a stolen one, and
    // every spelling below is the same attribute as far as the parser cares.
    const spellings = {
        'plain': `hx-nonce="${PAGE_NONCE}"`,
        'space before the equals': `hx-nonce ="${PAGE_NONCE}"`,
        'space after the equals': `hx-nonce= "${PAGE_NONCE}"`,
        'spaces both sides': `hx-nonce = "${PAGE_NONCE}"`,
        'a newline around the equals': `hx-nonce\n=\n"${PAGE_NONCE}"`,
        'a tab around the equals': `hx-nonce\t=\t"${PAGE_NONCE}"`,
        'single quotes': `hx-nonce='${PAGE_NONCE}'`,
        'no quotes': `hx-nonce=${PAGE_NONCE}`,
        'an uppercase attribute name': `HX-NONCE = "${PAGE_NONCE}"`,
    };

    for (const [name, attribute] of Object.entries(spellings)) {
        it(`strips a stolen nonce written with ${name}`, () => {
            const scrubbed = afterRequest(`<button hx-post="/x" ${attribute}>go</button>`);

            assert.ok(!scrubbed.includes(PAGE_NONCE), `the page nonce survived: ${scrubbed}`);
        });
    }

    it('strips a nonce the parser would have to decode first', () => {
        // &#114; is 'r'. A scrub comparing raw bytes never sees the page nonce,
        // and the parser hands the element the real thing on the way into the
        // DOM, where the gate then waves it through.
        const scrubbed = afterRequest('<button hx-post="/x" hx-nonce="&#114;4nd0mNonceAbc+/=">go</button>');

        assert.ok(!scrubbed.includes('&#114;'), `an encoded nonce survived: ${scrubbed}`);
    });

    it('strips a stolen nonce from a script tag', () => {
        const scrubbed = afterRequest(`<script nonce = "${PAGE_NONCE}">alert(1)</script>`);

        assert.ok(!scrubbed.includes(PAGE_NONCE), `the page nonce survived: ${scrubbed}`);
    });
});

describe('promoting a response nonce', () => {
    it('rewrites a same-origin response nonce to the page nonce', () => {
        const promoted = afterRequest(
            `<button hx-get="/y" hx-nonce="${RESPONSE_NONCE}">go</button>`,
            { nonce: RESPONSE_NONCE },
        );

        assert.ok(promoted.includes(PAGE_NONCE), `not promoted: ${promoted}`);
        assert.ok(!promoted.includes(RESPONSE_NONCE), `the response nonce survived: ${promoted}`);
    });

    it('promotes however the response spelled the attribute', () => {
        const promoted = afterRequest(
            `<button hx-get="/y" hx-nonce = '${RESPONSE_NONCE}'>go</button>`,
            { nonce: RESPONSE_NONCE },
        );

        assert.ok(promoted.includes(PAGE_NONCE), `not promoted: ${promoted}`);
    });

    it('does not promote a cross-origin response', () => {
        // Its nonce says nothing about what this page should trust.
        const scrubbed = afterRequest(
            `<button hx-get="/y" hx-nonce="${RESPONSE_NONCE}">go</button>`,
            { nonce: RESPONSE_NONCE, url: 'https://elsewhere.test/fragment' },
        );

        assert.ok(scrubbed.includes(RESPONSE_NONCE), 'a cross-origin nonce was promoted');
        assert.ok(!scrubbed.includes(PAGE_NONCE), 'a cross-origin response reached the page nonce');
    });
});

/**
 * An element as the gate sees it. `own` is what the element carries itself;
 * `inherited` is what htmx's attribute lookup would find on an ancestor, which
 * is how hx-boost reaches an anchor that says nothing about htmx at all.
 */
function element({ tag = 'button', id = '', own = {}, inherited = {} } = {}) {
    const attributes = { ...own };

    return {
        tagName: tag.toUpperCase(),
        id,
        get attributes() {
            return Object.entries(attributes).map(([name, value]) => ({ name, value }));
        },
        getAttribute: (name) => attributes[name] ?? inherited[name] ?? null,
        removeAttribute: (name) => { delete attributes[name]; },
        remaining: () => Object.keys(attributes),
    };
}

describe('the initialisation gate', () => {
    it('lets an element with the page nonce through', () => {
        const elt = element({ own: { 'hx-post': '/save', 'hx-nonce': PAGE_NONCE } });

        assert.notEqual(extension.htmx_before_init(elt), false, 'a nonced element was refused');
        assert.deepEqual(elt.remaining(), ['hx-post', 'hx-nonce'], 'a nonced element was stripped');
    });

    it('refuses an element with no nonce and strips what it came with', () => {
        const elt = element({ own: { 'hx-post': '/save' } });

        assert.equal(extension.htmx_before_init(elt), false, 'an unnonced element was not refused');
        assert.deepEqual(elt.remaining(), [], 'the htmx attributes survived');
    });

    it('refuses an element whose nonce is not this page\'s', () => {
        const elt = element({ own: { 'hx-post': '/save', 'hx-nonce': RESPONSE_NONCE } });

        assert.equal(extension.htmx_before_init(elt), false, 'a mismatched nonce was not refused');
    });

    // The hole this covers: hx-boost lives on an ancestor, so the element htmx
    // is about to initialise has no hx- attribute of its own. A gate that only
    // refused what it had stripped found nothing to strip and let it through.
    it('refuses an element boosted by an ancestor that carries no nonce', () => {
        const elt = element({ tag: 'a', inherited: { 'hx-boost': 'true' } });

        assert.equal(extension.htmx_before_init(elt), false, 'a boosted element was initialised ungated');
    });

    it('lets a boosted element through when the nonce is inherited too', () => {
        const elt = element({
            tag: 'a',
            inherited: { 'hx-boost': 'true', 'hx-nonce': PAGE_NONCE },
        });

        assert.notEqual(extension.htmx_before_init(elt), false, 'an inherited nonce was not honoured');
    });

    it('gates hx-on the same way', () => {
        const nonced = element({ own: { 'hx-on:click': 'x()', 'hx-nonce': PAGE_NONCE } });
        const bare = element({ own: { 'hx-on:click': 'x()' } });

        assert.notEqual(extension.htmx_before_on_init(nonced), false);
        assert.equal(extension.htmx_before_on_init(bare), false);
    });
});

describe('content the scrub has no business touching', () => {
    for (const html of [
        '<a href="/nonce-policy?x=1&y=2">read</a>',
        '<p>the word nonce = a number used once</p>',
        '<button hx-get="/y" hx-nonce="someOtherValidNonce">go</button>',
    ]) {
        it(`leaves ${html} alone`, () => {
            assert.equal(afterRequest(html), html);
        });
    }
});
