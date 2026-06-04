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
