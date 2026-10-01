import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import assert from 'node:assert/strict';
import { beforeEach, describe, it } from 'node:test';

// app.js as it ships. Its stream client wires itself up on load, so each test
// loads it afresh into a fake page and drives the EventSource it opens.
const SCRIPT = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../public/js/app.js'), 'utf8');

let documentListeners;
let elements;
let sources;
let timers;

class FakeEventSource {
    static CONNECTING = 0;
    static OPEN = 1;
    static CLOSED = 2;

    constructor(url) {
        this.url = url;
        this.readyState = FakeEventSource.CONNECTING;
        this.listeners = {};
        sources.push(this);
    }

    addEventListener(name, handler) {
        (this.listeners[name] ??= []).push(handler);
    }

    close() {
        this.readyState = FakeEventSource.CLOSED;
    }

    emit(name, event = {}) {
        (this.listeners[name] ?? []).forEach((handler) => handler(event));
    }

    open() {
        this.readyState = FakeEventSource.OPEN;
        this.emit('open');
    }
}

/** An element listening on $topics, recording the sse:* events it is sent. */
function listening(topics) {
    const element = {
        received: [],
        getAttribute: (name) => (name === 'data-stream' ? topics : null),
        dispatchEvent(event) { this.received.push({ type: event.type, detail: event.detail }); },
    };
    elements.push(element);

    return element;
}

function resyncs(element) {
    return element.received.filter((event) => event.detail?.event === 'resync').length;
}

/** Lets the fetch and json() promises of a connect() settle. */
async function settle() {
    for (let i = 0; i < 5; i++) {
        await Promise.resolve();
    }
}

async function runTimers() {
    const due = timers.splice(0);
    due.forEach((callback) => callback());
    await settle();
}

beforeEach(async () => {
    documentListeners = {};
    elements = [];
    sources = [];
    timers = [];

    globalThis.document = {
        addEventListener: (name, handler) => { (documentListeners[name] ??= []).push(handler); },
        querySelectorAll: (selector) => (selector === '[data-stream]' ? elements : []),
        querySelector: () => null,
    };
    globalThis.window = { addEventListener: () => {}, location: { assign: () => {} }, history: {} };
    globalThis.EventSource = FakeEventSource;
    globalThis.CustomEvent = class { constructor(type, init) { this.type = type; this.detail = init?.detail; } };
    globalThis.fetch = async () => ({ status: 200, ok: true, json: async () => ({ url: '/stream?token=t' }) });
    globalThis.setTimeout = (callback) => { timers.push(callback); return timers.length; };
    globalThis.clearTimeout = () => {};

    listening('module.users');
    new Function(SCRIPT)();
    await settle();
});

describe('the stream client', () => {
    it('does not resync on the first open: the page was just rendered', () => {
        sources[0].open();

        assert.equal(resyncs(elements[0]), 0);
    });

    it('resyncs every topic when a stream the browser reconnected opens again', () => {
        const source = sources[0];
        source.open();

        // A dropped connection: the browser retries the same token itself.
        source.readyState = FakeEventSource.CONNECTING;
        source.emit('error');
        source.open();

        assert.equal(resyncs(elements[0]), 1);
        assert.equal(elements[0].received[0].type, 'sse:module.users');
    });

    it('resyncs when a fresh token reopens a stream whose token expired', async () => {
        sources[0].open();

        // The hub closes an expired stream; the retry is told 204 and CLOSED.
        sources[0].readyState = FakeEventSource.CLOSED;
        sources[0].emit('error');
        await runTimers();

        assert.equal(sources.length, 2, 'a fresh token opens a second stream');
        sources[1].open();

        assert.equal(resyncs(elements[0]), 1);
    });

    it('does not resync when a swap only widened the grant', async () => {
        sources[0].open();

        listening('module.jobs');
        documentListeners['htmx:after:swap'].forEach((handler) => handler({}));
        await settle();

        assert.equal(sources.length, 2, 'the new topic needs a new stream');
        sources[1].open();

        assert.equal(elements.reduce((sum, element) => sum + resyncs(element), 0), 0);
    });
});
