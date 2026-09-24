import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import assert from 'node:assert/strict';
import { beforeEach, describe, it } from 'node:test';

const SCRIPT = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../public/js/qr.js'), 'utf8');

let listeners;
let appended;
let pending;

function element(value) {
    return { innerHTML: '', getAttribute: (name) => (name === 'data-qr' ? value : null) };
}

function fakeLibrary() {
    return () => {
        let data = null;

        return {
            addData: (value) => { data = value; },
            make: () => {},
            createSvgTag: () => `<svg data-for="${data}"></svg>`,
        };
    };
}

function fire(name) {
    (listeners[name] ?? []).forEach((listener) => listener());
}

/* Lets the library promise settle before the assertions look. */
const settled = () => new Promise((done) => setTimeout(done, 0));

beforeEach(() => {
    listeners = {};
    appended = [];
    pending = [];
    globalThis.window = {};
    globalThis.console = { ...console, warn: () => {} };
    globalThis.document = {
        addEventListener: (name, listener) => { (listeners[name] ??= []).push(listener); },
        querySelectorAll: () => pending,
        createElement: () => ({}),
        head: { appendChild: (script) => { appended.push(script); } },
    };
    new Function(SCRIPT)();
});

describe('qr.js', () => {
    it('loads nothing on a page without a code', () => {
        fire('DOMContentLoaded');

        assert.equal(appended.length, 0);
    });

    it('loads the pinned library once, held to its hash', async () => {
        pending = [element('otpauth://a'), element('otpauth://b')];

        fire('DOMContentLoaded');
        fire('htmx:after:swap');

        assert.equal(appended.length, 1);
        assert.match(appended[0].src, /^https:\/\/cdnjs\.cloudflare\.com\/ajax\/libs\/qrcode-generator\/1\.4\.4\//);
        assert.match(appended[0].integrity, /^sha384-/);
        assert.equal(appended[0].crossOrigin, 'anonymous');

        window.qrcode = fakeLibrary();
        appended[0].onload();
        await settled();

        assert.equal(pending[0].innerHTML, '<svg data-for="otpauth://a"></svg>');
        assert.equal(pending[1].innerHTML, '<svg data-for="otpauth://b"></svg>');
    });

    it('draws a swapped-in code with the library already loaded', async () => {
        window.qrcode = fakeLibrary();
        pending = [element('otpauth://c')];

        fire('htmx:after:swap');
        await settled();

        assert.equal(appended.length, 0);
        assert.equal(pending[0].innerHTML, '<svg data-for="otpauth://c"></svg>');
    });

    it('tries again after a failed load', async () => {
        pending = [element('otpauth://d')];

        fire('DOMContentLoaded');
        appended[0].onerror();
        await settled();
        fire('htmx:after:swap');

        assert.equal(pending[0].innerHTML, '');
        assert.equal(appended.length, 2);
    });
});
