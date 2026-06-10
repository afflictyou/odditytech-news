# odditytech.news API — v1

Base path: `/api/v1/`
Auth (for ingestion + editorial endpoints): `X-API-KEY: <INGEST_API_KEY>` header.
Auth (publish PATCH on `/api/v1/digests/`): `X-API-KEY: <PUBLISHER_API_KEY>` header.
See "Auth model" in each endpoint for which key is accepted where.
Content type: `application/json` on requests with bodies and on all responses.

This file is the canonical reference for the public REST surface.

---

## Headlines — `/api/v1/headlines/`

The ingestion path used by the SSCI Integration agent. See the running endpoint
at `api/v1/headlines/index.php` for the precise contract.

- `POST /api/v1/headlines/` — auth required; creates an `active` headline.
- `PATCH /api/v1/headlines/?id={id}` — auth required; partial update of a
  whitelisted set of columns on an existing row. Currently writable:
  `canonical_paper_url` (SIG-263 / SIG-177), `source_url` and `source_name`
  (SIG-341, gate-catch provenance fix), `title` and `summary` (SIG-343,
  gate-catch framing fix). Sending a non-whitelisted field returns
  `400 No writable fields supplied` with the live `allowed` list.

---

## Digests — `/api/v1/digests/`  (SIG-176)

Backs the `/digest` publication. Schema lives in `digests` (see
`docs/migrations/0002_digests.sql`).

A digest is a long-form weekly synthesis post: `title`, `summary` (rendered on
the index page), `body_markdown` (the article body), `lead_cluster` (the
editorial cluster headline this digest leads with), `slug` (URL key), `status`
(`draft` | `published`), and timestamps.

### Auth model

Two distinct keys are accepted on this endpoint (SIG-290 two-key boundary):

- **Public (no `X-API-KEY`):** can only see `status=published`. Drafts are
  invisible — list/detail endpoints behave as if drafts do not exist.
- **Editor (`INGEST_API_KEY`):** can `GET` drafts, `POST` new digests, and
  `PATCH` content fields. **Cannot** flip status to `published` — returns
  `403` once `PUBLISHER_API_KEY` is configured.
- **Publisher (`PUBLISHER_API_KEY`):** can `GET` drafts and `PATCH
  {"status":"published"}` only. **Cannot** `POST`. **Cannot** PATCH content
  fields or `status=draft` — returns `403` for any write that isn't a publish.

See [`docs/digest_workflow.md`](digest_workflow.md) §"Two-key boundary" for
the full role table and provisioning steps.

### `POST /api/v1/digests/` — create

**Auth required.** Creates a draft (or a published row if `status=published`
is provided in the body).

Request body:

| Field           | Type   | Required | Notes                                                                 |
| --------------- | ------ | -------- | --------------------------------------------------------------------- |
| `title`         | string | yes      | Max 500 chars.                                                        |
| `body_markdown` | string | yes      | Article body, markdown.                                               |
| `summary`       | string | no       | Short summary used on the `/digest` index page.                       |
| `slug`          | string | no       | If omitted, slugified from `title`. Auto-suffixed `-2`, `-3`, … on collision. |
| `lead_cluster`  | string | no       | Editorial cluster label.                                              |
| `status`        | string | no       | `draft` (default) or `published`. If `published`, `published_at` is stamped to now. |

Response: `201 Created` with the persisted row (see schema below).

### `GET /api/v1/digests/` — list

| Query param | Notes                                                                                          |
| ----------- | ---------------------------------------------------------------------------------------------- |
| `status`    | `draft` or `published`. **Public requests are server-side coerced to `published`.** Authenticated requests may pass `draft`. |
| `order`     | `published_at` (asc) or `-published_at` (desc, default). `created_at` / `-created_at` also accepted. |

Response: `200 OK` with `{ "digests": [ row, … ], "count": N }`. Capped at 100 rows.

### `GET /api/v1/digests/{slug}` — fetch one

Path param is the `slug` (string).

- `200 OK` with the row when the digest exists and is visible to the caller.
- `404 Not Found` if the slug doesn't exist **or** if the row is a draft and
  the caller is unauthenticated. (Drafts return 404, never 403, so existence
  isn't leaked.)

### `PATCH /api/v1/digests/{id}` — update

**Auth required.** Path param is the integer `id` (slug is not accepted here —
slugs can change, IDs are stable). Note: this differs from
`PATCH /api/v1/headlines/?id={id}`, which uses a query-string id — digests are
addressed by URL path.

Any subset of these fields can be updated:

`title`, `summary`, `body_markdown`, `lead_cluster`, `slug`, `status`,
`published_at`.

Publish workflow:
- `PATCH {id} {"status": "published"}` flips status to `published` and, if
  `published_at` was `NULL`, stamps it to `NOW()`. Already-published rows
  keep their original `published_at`.
- To explicitly override the publish timestamp (e.g. backdating), include
  `"published_at": "YYYY-MM-DD HH:MM:SS"` in the same PATCH. Editor key must
  do this in a separate PATCH that does **not** set `status=published`, since
  the Editor key is forbidden from publishing once `PUBLISHER_API_KEY` is
  configured. Conversely, the publisher key cannot set `published_at`.
- Publish PATCH must use `PUBLISHER_API_KEY` (X-API-KEY header). The Editor
  key (`INGEST_API_KEY`) returns `403 Editor key cannot publish` for any PATCH
  body containing `"status":"published"`.

Response: `200 OK` with the updated row. `404` if id not found.
`409 Conflict` if changing `slug` to a value already used by another row.

### Digest row shape

```json
{
  "id": 42,
  "slug": "week-of-2026-05-25",
  "title": "This week in superconductors",
  "summary": "Three labs replicated the …",
  "body_markdown": "## Lead\n\n…",
  "lead_cluster": "Room-temperature superconductors",
  "status": "published",
  "published_at": "2026-05-29 17:00:00",
  "created_at": "2026-05-29 14:12:08",
  "updated_at": "2026-05-29 17:00:00"
}
```

---

## Migrations

SQL migrations live in `docs/migrations/`. They are excluded from the FTPS
deploy and must be run manually against `oddimfjz_headlinestore`:

```
mysql -u oddimfjz_agent -p oddimfjz_headlinestore < docs/migrations/0002_digests.sql
```

Migrations are written to be idempotent (`CREATE TABLE IF NOT EXISTS`, etc.)
so re-runs against an already-migrated DB are safe no-ops.
# odditytech.news REST API

Base URL: `https://odditytech.news`
All endpoints require the `X-API-Key` header (matched against `INGEST_API_KEY` from the server `.env`).

## `GET /api/v1/headlines/`

Returns active headlines, newest first by default.

### Query parameters

| Name                  | Format                                  | Description                                                                                          |
| --------------------- | --------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `since`               | `YYYY-MM-DD`                            | Inclusive lower bound on `published_at`. Interpreted as start-of-day UTC.                            |
| `until`               | `YYYY-MM-DD`                            | Inclusive upper bound on `published_at`. Interpreted as end-of-day UTC.                              |
| `order`               | `published_at[:asc\|:desc]`             | Sort key. Direction defaults to `:desc`. Only `published_at` is sortable today.                      |
| `canonical_paper_url` | string                                  | Exact-match filter to pull every article syndicating the same preprint/release (SIG-177).            |

Malformed dates and unknown sort fields return **400** with a JSON `error` body — never 500 and never the unfiltered set.

### Response

```json
{
  "headlines": [
    {
      "id": 1234,
      "title": "…",
      "summary": "…",
      "source_url": "https://…",
      "source_name": "…",
      "category": "…",
      "tags": "tag1,tag2",
      "published_at": "2026-05-29T18:42:00Z",
      "canonical_paper_url": "https://arxiv.org/abs/…"
    }
  ],
  "count": 1
}
```

- `published_at` is always serialized as an ISO-8601 UTC string with a trailing `Z`. Clients render relative timestamps from this absolute value.
- `canonical_paper_url` is `null` for rows ingested before SIG-177 / SIG-180.
- Results are capped at 500 rows per call. The SSCI Digest Editor's weekly window comfortably fits inside that cap; if it ever does not, add pagination before raising the cap.

### Examples

Weekly slice for the SSCI Digest Editor:

```sh
curl -H "X-API-Key: $INGEST_API_KEY" \
  'https://odditytech.news/api/v1/headlines/?since=2026-05-23&until=2026-05-30&order=published_at:desc'
```

Oldest-first within a window (rare; useful for replays):

```sh
curl -H "X-API-Key: $INGEST_API_KEY" \
  'https://odditytech.news/api/v1/headlines/?since=2026-05-23&until=2026-05-30&order=published_at:asc'
```

All articles syndicating a single preprint:

```sh
curl -H "X-API-Key: $INGEST_API_KEY" \
  'https://odditytech.news/api/v1/headlines/?canonical_paper_url=https://arxiv.org/abs/2405.12345'
```

## `POST /api/v1/headlines/`

Ingest a single headline. Used by the SSCI Researcher pipeline.

### Request body (JSON)

| Field                 | Required | Notes                                                                            |
| --------------------- | -------- | -------------------------------------------------------------------------------- |
| `title`               | yes      | Truncated server-side to 500 chars.                                              |
| `summary`             | yes      |                                                                                  |
| `source_url`          | yes      | Truncated server-side to 2083 chars.                                             |
| `source_name`         | yes      | Truncated server-side to 255 chars.                                              |
| `category`            | yes      | Truncated server-side to 100 chars.                                              |
| `tags`                | no       | Array (or comma-separated string). Each entry is slugified, alias-resolved against the canonical vocabulary (SIG-181), and deduplicated before storage. Stored as a comma-separated slug list, ≤500 chars. |
| `published_at`        | no       | `YYYY-MM-DD HH:MM:SS` (UTC). Defaults to server time at ingest.                  |
| `canonical_paper_url` | no       | Preprint / DOI / institutional release URL for dedup (SIG-177). ≤512 chars.      |

#### Tag normalization (SIG-181)

The `tags` field is alias-resolved at ingest. Aliases collapse to their canonical slug; unmapped tags pass through unchanged. So:

```jsonc
// client sends
{ "tags": ["Neuromorphic", "memristor", "AI", "LLM"] }
// persisted (alias-resolved + slugified + deduped)
"tags": "neuromorphic,ai,llm"
```

The full canonical vocabulary lives in `docs/canonical_tags.md`. The runtime alias map is `config/canonical_tags.json`. Use `GET /api/v1/tags` (below) to introspect the canonical list programmatically.

### Response

`201 Created`

```json
{ "success": true, "id": 12345 }
```

Validation failures return `400` with a JSON `error` body naming the missing field.

---

## `GET /api/v1/tags` (SIG-181)

Returns the canonical tag vocabulary so downstream consumers (notably the SSCI Digest Editor) can introspect it programmatically.

Public — no `X-API-Key` required. The vocabulary is the same data already published in `docs/canonical_tags.md`.

### Query parameters

| Name              | Format | Description                                                          |
| ----------------- | ------ | -------------------------------------------------------------------- |
| `include_aliases` | `1`    | Include the array of alias slugs that resolve to each canonical.     |

### Response

```json
{
  "canonical_tags": [
    {
      "slug": "neuromorphic",
      "display": "Neuromorphic",
      "description": "Brain-inspired hardware: memristors, spiking chips, photonic neurons, AI-substrate hardware."
    }
  ],
  "count": 32
}
```

With `?include_aliases=1`, each entry also carries an `aliases` array:

```json
{
  "slug": "neuromorphic",
  "display": "Neuromorphic",
  "description": "…",
  "aliases": ["ai-hardware","bio-inspired","hardware","memristor","neuromorphic","photonic-computing","spiking-neural-network","stdp"]
}
```

### Example

```sh
curl 'https://odditytech.news/api/v1/tags?include_aliases=1' | jq .
```
