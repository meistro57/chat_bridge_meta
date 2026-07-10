<div align="center">

# 🔮 Chat Bridge — Meta Bridge Research Edition

### _A conversational research interface into the Meta Bridge consciousness-literature corpus_
### --The results of when a Steel Fabricator gets his hands on AI and finds Github.--
https://quantummindsunited.com/

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![React](https://img.shields.io/badge/React-18-61DAFB?logo=react&logoColor=black)](https://react.dev)
[![Tailwind](https://img.shields.io/badge/Tailwind-v3-38BDF8?logo=tailwind-css&logoColor=white)](https://tailwindcss.com)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?logo=docker&logoColor=white)](https://www.docker.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![Made with Love](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F-red)](https://github.com/meistro57/chat_bridge_meta)

**Current Version:** 0.8.0

**Talk to AI personas that can search a 300K+ point research corpus of channeled, hermetic, gnostic, vedic, and other consciousness-exploration traditions — grounded in the actual source material, not general-knowledge guesses.**

[What This Is](#-what-is-this) • [Meta Bridge Research Tool](#-meta-bridge-research-tool) • [Installation](#-installation) • [Docker Setup](#-docker-deployment) • [Documentation](#-documentation)

---

</div>

## 🌟 What Is This?

`chat_bridge_meta` is a purpose-repointed deployment of [Chat Bridge](https://github.com/meistro57/chat_bridge) — the full AI conversation orchestration platform is still under the hood, but this instance exists for one specific job: **questioning and researching the [Meta Bridge](https://github.com/meistro57/meta-bridge) Qdrant collections.**

Meta Bridge and [MisfitCrew](https://github.com/meistro57/MisfitCrew) own ingestion into a large, ongoing corpus of consciousness-exploration literature — channeled material (Seth, Ra/Law of One, Dolores Cannon, Bashar), Hermetic, Gnostic, Vedic, Norse, Sufi, and other traditions — distilled into claims, chunked source text, source/book metadata, cross-source synthesized reflections, and MisfitCrew pattern reports. This Chat Bridge instance never writes to that corpus. It's a **read-only research front end**: talk to a persona, ask it a question, and it can reach into the corpus mid-conversation to ground its answer in the actual source material.

Everything else Chat Bridge normally does — personas, multi-agent conversations, RAG over your own chat history, Discord/Discourse streaming, the full admin/diagnostics suite — is still here and still works. It's just not the point of *this* deployment.

---

## 🔍 Meta Bridge Research Tool

The core feature: a `search_meta_bridge` tool available to any AI persona with tool-calling enabled (`AI_TOOLS_ENABLED=true`). During a conversation, the AI can call it the same way it calls `search_conversations` or `get_contextual_memory` — the model decides when a question warrants a corpus lookup, runs the search, and folds the results back into its answer with source grounding instead of paraphrasing from general training data.

It searches five collections, selectable per call:

| Collection | Qdrant Name | What's In It |
| --- | --- | --- |
| `claims` (default) | `mb_claims` | Distilled canonical statements extracted from the corpus |
| `chunks` | `mb_chunks` | Raw source-text excerpts (paragraph/chapter-level) |
| `sources` | `mb_sources` | Book/source-level metadata (title, author, tradition) |
| `reflections` | `meta_reflections` | Synthesized cross-source findings (named vector `summary_vec`) |
| `misfit_reports` | `misfit_reports` | MisfitCrew's synthesized cross-source pattern reports (named vector `summary_vec`) |
| `vectoreology_findings` | `vectoreology_findings` | [Vectoreologist](https://github.com/meistro57/vectoreologist)'s topology findings — clusters, semantic bridges/moats, and density anomalies mined from the corpus's embedding space |

**How it works under the hood** (`app/Services/MetaBridge/MetaBridgeSearchService.php`):

- Query text is embedded with the same model the rest of the meta-bridge ecosystem uses (`google/gemini-embedding-001`, 3072-dim), so queries land in the same vector space as the ingested data — no separate embedding pipeline to maintain.
- Each collection search is independently scoped and score-thresholded (`MB_QDRANT_SCORE_THRESHOLD`, default `0.5`).
- The two "synthesis" collections (`reflections`, `misfit_reports`) use named vectors and default to `summary_vec` for general topical search.
- `vectoreology_findings` is the odd one out: it stores vectors as a placeholder dim-1 value (it was never meant to be embedding-searched), so it's queried differently from the rest — by Qdrant payload filter (`type`, `is_anomaly`, `confidence`) plus an in-memory keyword match against the `subject`/`reasoning_chain` payload fields, not vector similarity.
- Every search is cached in Redis, keyed by collection + query + every parameter that affects the result (limit, threshold, filters). Agents commonly re-ask similar things across turns in the same conversation, so a cache hit skips both the embedding call and the Qdrant round trip. TTL and store are configurable (`MB_QDRANT_CACHE_*` below); a cache-layer failure (e.g. Redis unreachable) degrades to querying Qdrant directly rather than breaking the tool call.
- Failures degrade gracefully — a failed embed or a Qdrant error returns an empty result set with a logged warning, never a hard crash mid-conversation.
- This service has **no write path**. It only ever calls Qdrant's search endpoint. Ingestion, embedding generation, and collection maintenance all live in the `meta-bridge` and `MisfitCrew` repos, not here.

**Relevant `.env` configuration:**

```env
# Meta Bridge — read-only cross-collection RAG against the meta-bridge Qdrant collections
# (same Qdrant instance/host as QDRANT_HOST, just different collection names)
MB_QDRANT_COLLECTION_CLAIMS=mb_claims
MB_QDRANT_COLLECTION_CHUNKS=mb_chunks
MB_QDRANT_COLLECTION_SOURCES=mb_sources
MB_QDRANT_COLLECTION_REFLECTIONS=meta_reflections
MB_QDRANT_COLLECTION_MISFIT_REPORTS=misfit_reports
MB_QDRANT_REFLECTION_VECTOR=summary_vec
MB_QDRANT_MISFIT_REPORTS_VECTOR=summary_vec
MB_QDRANT_SCORE_THRESHOLD=0.5
MB_QDRANT_COLLECTION_VECTOREOLOGY_FINDINGS=vectoreology_findings

# Redis cache for search_meta_bridge results (speeds up repeated agent queries)
MB_QDRANT_CACHE_ENABLED=true
MB_QDRANT_CACHE_STORE=redis
MB_QDRANT_CACHE_TTL_SECONDS=300

# Must match the embedding model meta-bridge ingested with
OPENROUTER_EMBEDDING_MODEL=google/gemini-embedding-001
EMBEDDING_VECTOR_SIZE=3072
```

### Using it

1. Create or pick a persona at `/personas` (a "research assistant" style system prompt works well — something like *"When asked about consciousness, channeled material, or spiritual traditions, use search_meta_bridge to ground your answer in the corpus before answering."*)
2. Start a conversation at `/chat/create` with `AI_TOOLS_ENABLED=true`
3. Ask a question that the persona would need corpus grounding for — it will call `search_meta_bridge` autonomously, pick the right collection, and cite what it found
4. You can also drive it more directly through the **AI Chatbot** ("Ask the Archive") at `/transcript-chat` if you'd rather query directly than go through a persona conversation

---

## ✨ Also Included (Full Chat Bridge Platform)

Since this is a full Chat Bridge deployment, all of the underlying platform capabilities are available too:

<table>
<tr>
<td width="50%">

### 🎭 **Persona System**

- Custom system prompts & guidelines per persona
- Built-in AI Persona Creator Bot
- Default temperature controls (0.0–2.0)
- Provider/model-agnostic design
- Shared library of pre-configured personas

</td>
<td width="50%">

### 💬 **Conversation Engine**

- Real-time streaming via WebSockets (Reverb)
- Automated multi-turn dialogues, manual stop/resume
- Smart stop-word detection with thresholds
- Per-session agent memory controls
- Optional Discord/Discourse broadcast per conversation

</td>
</tr>
<tr>
<td width="50%">

### 🧠 **RAG Intelligence**

- Qdrant vector database for your own chat history (`chat_messages` collection)
- Semantic message search, sub-10ms retrieval
- Session-level memory tuning per chat

</td>
<td width="50%">

### 🛠️ **MCP Tool Calling**

- `search_meta_bridge` — the corpus research tool described above
- `search_conversations`, `get_contextual_memory`, `get_recent_chats`, `get_conversation` — search your own chat history
- `fetch_url` — pull in external web content mid-conversation
- Agentic loop: the AI decides which tools to call and when

</td>
</tr>
<tr>
<td width="50%">

### 📊 **Analytics Suite**

- 7-day activity trends, persona stats
- Full read-only SQL playground
- CSV export

</td>
<td width="50%">

### 🐛 **Debug & Admin Tools**

- Laravel Telescope, Debugbar
- System Diagnostics dashboard (`/admin/system`)
- Redis dashboard, Performance monitor
- MCP traffic watch (`/admin/mcp-utilities`)

</td>
</tr>
</table>

For the exhaustive list of everything the base platform supports, see **[FEATURES.md](FEATURES.md)**.

---

## 🛠️ Tech Stack

- **Backend:** Laravel 12.x, PHP 8.2+, PostgreSQL 16, Redis, Qdrant
- **Real-time:** Laravel Reverb (WebSockets)
- **Frontend:** React 18, Inertia.js 2.0, Tailwind CSS v3, Vite 7
- **AI Integration:** Neuron AI (multi-provider), Saloon PHP, 8+ AI provider support (OpenAI, Anthropic, Gemini, OpenRouter, DeepSeek, Bedrock, Ollama, LM Studio)

---

## 📋 Requirements

- PHP >= 8.2
- Composer
- Node.js >= 18
- NPM or Yarn
- Docker (recommended) or SQLite/PostgreSQL locally
- A reachable Qdrant instance with the `mb_claims`, `mb_chunks`, `mb_sources`, `meta_reflections`, `misfit_reports`, and `vectoreology_findings` collections already populated (via `meta-bridge` / `MisfitCrew` / `vectoreologist` ingestion — this repo does not create them)

---

## 🚀 Installation

### 1. Clone the Repository

```bash
git clone https://github.com/meistro57/chat_bridge_meta.git
cd chat_bridge_meta
```

### 2. Install Dependencies

```bash
composer install
npm install
```

### 3. Environment Setup

```bash
cp .env.example .env
php artisan key:generate
```

Fill in the `MB_QDRANT_*` variables shown above to point at your existing meta-bridge collections, and `QDRANT_HOST`/`QDRANT_PORT` to point at the same Qdrant instance.

### 4. Configure Database

```bash
touch database/database.sqlite   # if using SQLite
php artisan migrate --force
```

### 5. Build Assets & Start

```bash
npm run build
chmod +x start-services.sh
./start-services.sh
```

Or run manually:

```bash
php artisan serve
php artisan queue:work
php artisan reverb:start
php artisan schedule:work
```

---

## 🐳 Docker Deployment

```bash
cp .env.docker .env
# configure MB_QDRANT_* and your AI provider keys in .env
docker compose up -d
```

Access:
- Web: `http://localhost:20000` (or whatever `APP_PORT` is set to)
- Qdrant dashboard: `http://localhost:16333/dashboard` (adjust for your `QDRANT_PORT`)

Common commands:

```bash
make up             # Start all services
make down            # Stop all services
make logs            # View all logs
make shell           # Open shell in app container
docker compose ps    # Check container status
```

Full rebuild + validation pass:

```bash
./refresh.sh          # full rebuild, startup checks, tests, Codex verification
./refresh.sh --quick  # skip image rebuild
```

For detailed Docker documentation, see **[DOCKER.md](DOCKER.md)**. For the base RAG system (your own chat history, separate from the Meta Bridge tool), see **[RAG_GUIDE.md](RAG_GUIDE.md)**.

---

## 🧪 Testing

```bash
php artisan test
./run_tests.sh   # auto-detects Docker, runs inside app container when available
```

Or use the System Diagnostics panel at `/admin/system` to run tests via the web interface.

---

## 🐛 Troubleshooting

### `search_meta_bridge` returns no results
1. Confirm Qdrant is reachable: check `/admin/redis` and the system diagnostics panel, or hit `QDRANT_HOST:QDRANT_PORT/collections` directly.
2. Confirm the collection names in `.env` (`MB_QDRANT_COLLECTION_*`) actually match what exists in Qdrant — a rename on the meta-bridge side won't automatically propagate here.
3. Confirm your embedding model matches what meta-bridge ingested with (`OPENROUTER_EMBEDDING_MODEL=google/gemini-embedding-001`, `EMBEDDING_VECTOR_SIZE=3072`) — a mismatched model produces vectors in the wrong space and every search will silently return nothing above threshold.
4. Try lowering `MB_QDRANT_SCORE_THRESHOLD` temporarily to confirm it's a threshold issue vs. a connectivity issue.
5. Check `storage/logs/laravel.log` for `Meta Bridge search failed` or `Meta Bridge search threw an exception` entries — the service logs warnings rather than throwing, so failures are visible there even when the conversation UI just shows an empty tool result.

### General platform issues (queue, WebSocket, Discord/Discourse, database)

See the full troubleshooting section in the base Chat Bridge docs — this deployment shares the same underlying app, so those fixes all apply unchanged.

---

## 📚 Documentation

| Document | Description |
| --- | --- |
| **[MCP.md](MCP.md)** | MCP server integration guide (includes `search_meta_bridge` tool contract) |
| **[FEATURES.md](FEATURES.md)** | Complete base-platform feature list |
| **[DOCKER.md](DOCKER.md)** | Docker deployment guide |
| **[RAG_GUIDE.md](RAG_GUIDE.md)** | RAG & AI memory guide (your own chat history) |
| **[ROADMAP.md](ROADMAP.md)** | Future development plans |

### Related repos

- **[meta-bridge](https://github.com/meistro57/meta-bridge)** — owns ingestion, embedding, and collection maintenance for the corpus this tool searches
- **[MisfitCrew](https://github.com/meistro57/MisfitCrew)** — generates the cross-source pattern reports in `misfit_reports`
- **[vectoreologist](https://github.com/meistro57/vectoreologist)** — generates the topology findings in `vectoreology_findings`
- **[chat_bridge](https://github.com/meistro57/chat_bridge)** — the original, general-purpose deployment of this same platform

---

## 📄 License

MIT — see [LICENSE](https://opensource.org/licenses/MIT).

---

<div align="center">

Made with ❤️ by a steel fabricator who found Qdrant.

</div>
