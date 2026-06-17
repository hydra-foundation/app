// Application-wide htmx behaviour.

// htmx ignores non-2xx responses by default. Hydra returns 422 for validation
// failures together with the re-rendered fragment, so opt 422 into swapping
// while leaving genuine errors (other 4xx/5xx) to fail as normal.
document.addEventListener('htmx:beforeSwap', (e) => {
    if (e.detail.xhr.status === 422) {
        e.detail.shouldSwap = true;
        e.detail.isError = false;
    }
});
