// Application-wide htmx behaviour.

// Nothing here yet. htmx 4 swaps 4xx and 5xx responses like any other, so the
// 422-validation flow needs no opt-in: htmx fires htmx:response:error and then
// swaps regardless, and only HX-Refresh/HX-Redirect/HX-Location skip the swap.
// The htmx 2 hook that used to live here listened for htmx:beforeSwap and read
// detail.xhr — htmx 4 renamed every event to a colon-namespaced form
// (htmx:before:swap) and is fetch-based, so it had stopped firing entirely.
