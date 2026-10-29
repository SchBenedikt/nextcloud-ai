# EVA documentation

Start with the workflow you need. Guides explain the app as you use it;
reference pages describe the complete configuration and API surface.

## Use EVA

- [Quick start](QUICKSTART.md): connect a model and ask your first question.
- [User guide](USER-GUIDE.md): conversations, sources, exports and documents.
- [AI providers](PROVIDERS.md): cloud services, local servers and credentials.
- [Workspace settings](WORKSPACE.md): where to find each setting.
- [Files and direct discovery](AGENTIC-FILE-DISCOVERY.md): indexed knowledge and live VFS access.
- [Troubleshooting](TROUBLESHOOTING.md): connection, indexing and chat problems.
- [Privacy](PRIVACY.md) and [security](SECURITY.md): data flows and permissions.

## Operate and extend EVA

- [Configuration](CONFIGURATION.md), [FAQ](FAQ.md) and [features](FEATURES.md).
- [Performance](PERFORMANCE.md): request costs, polling and resource ownership.
- [Observability](OBSERVABILITY.md): native aggregate OpenMetrics exported by EVA.
- [Migration notes](WORKSPACE-MIGRATION.md): what changed in the focused workspace.
- [Architecture](ARCHITECTURE.md), [development](DEVELOPMENT.md) and [CI](CI.md).
- [API](API.md), [OpenAPI](openapi.yaml), [plugins](PLUGIN_API.md) and [connectors](CONNECTORS.md).
- [Talk indexing](TALK-INDEXING.md), [terminal tools](TERMINAL_TOOLS.md) and [browser rendering](BROWSER-RENDERING.md).

## Quality and optional scaling

- [Quality evaluation](QUALITY.md): synthetic chunking/retrieval regression method and thresholds.
- [Retrieval benchmark](RETRIEVAL-BENCHMARK.md): frozen corpus, exact reference and ranking metrics.
- [Backend migration ledger](BACKEND-ROADMAP.md): current implementation stages, native integration and rollout gates.
- [Indexing architecture decision](INDEXING-ARCHITECTURE.md): SQL-first generation indexing by default, with Qdrant as an optional scale adapter.
- [Vector storage](VECTOR-STORAGE.md): generation identity, additive schema and operating boundaries.
- [Generation embeddings](GENERATION-EMBEDDINGS.md): pinned provider/model contracts, proven cache reuse and explicit native checks.
- [Generation chunks](GENERATION-CHUNKS.md): separate SQL rows, source stamps and atomic upsert intent.
- [Generation builds](GENERATION-BUILDS.md): explicit background processing, durable checkpoints, personal opt-out and native pause/resume controls.
- [Qdrant](QDRANT.md): optional server configuration and deployment.
- [Vector outbox](VECTOR-OUTBOX.md): durable intent, ownership fencing and delivery invariants.

The ledger is the current migration plan. Superseded review snapshots and the
older 2.0 roadmap remain available in Git history; current guidance lives here.
