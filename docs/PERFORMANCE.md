# Workspace performance

These are request and lifecycle improvements, not measured throughput claims.

## Home

Home receives the navigation's chat list as props. It makes no separate `stats`
or `greeting` request. This avoids a second chat-store read, document aggregates
and full model-status construction on that screen. Both routes remain available
to existing API clients.

## Settings

Models initially loads core settings. History, personal knowledge, connectors,
extensions, keys and diagnostics load on first entry into their section.
Independent optional resources load concurrently. `useEvaSettings` owns form
state; section components render it.

A full status response containing model information no longer triggers another
initial model-discovery request. Editing the endpoint still discovers models
on the new server.

There is no idle settings polling. During indexing, a visible settings page
checks progress every ten seconds with an in-flight guard. Hidden tabs skip
those checks.

## Index progress

`GET /api/index/status` reads the user's indexing flags and timestamps. It keeps
abandoned-worker recovery but does not initialize knowledge, count documents,
read chats, discover models or run OCR diagnostics. Documents uses this route
instead of full system status too.

## Chat resources

The wrapper destroys its renderer when replaced or unmounted. Destruction
hands an active request to the existing durable background flow, removes the
page-hide listener, aborts the browser stream, stops speech, clears its timer,
cancels scheduled rendering and unmounts the actions app. Background-status
polls skip hidden tabs.

The background-task list polls active runs every ten seconds, slows idle lists
to once a minute and skips hidden tabs. It also prevents overlapping requests.

## Verification

Compare route counts in the browser Network panel. Measure server and provider
latency separately. Mocked browser tests prove routing and behavior, not real
inference speed. Workspace tests verify absent home diagnostics and deferred
advanced settings. Controller tests assert that progress does not call expensive
services.

## Settings requests

Capability validation can read a proposed Ollama endpoint directly. It no longer
writes and restores the user configuration just to test model roles. Cancelled
HTTP requests are not retried. Independent settings resources are split into
focused composables and loaded on demand.
