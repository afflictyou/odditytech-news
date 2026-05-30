# odditytech.news API — v1

Base path: `/api/v1/`
Auth (for ingestion + editorial endpoints): `X-API-KEY: <INGEST_API_KEY>` header.
Content type: `application/json` on requests with bodies and on all responses.

This file is the canonical reference for the public REST surface.

---

## Headlines — `/api/v1/headlines/`

The ingestion path used by the SSCI Integration agent. See the running endpoint
at `api/v1/headlines/index.php` for the precise contract.

- `POST /api/v1/headlines/` — auth required; creates an `active` headline.

---

## Digests — `/api/v1/digests/`  (SIG-176)

Backs the `/digest` publication. Schema lives in `digests` (see
`docs/migrations/0002_digests.sql`).

A digest is a long-form weekly synthesis post: `title`, `summary` (rendered on
the index page), `body_markdown` (the article body), `lead_cluster` (the
editorial cluster headline this digest leads with), `slug` (URL key), `status`
(`draft` | `published`), and timestamps.

### Auth model

- **Public (no `X-API-KEY`):** can only see `status=published`. Drafts are
  invisible — list/detail endpoints behave as if drafts do not exist.
- **Authenticated (valid `X-API-KEY`):** can see drafts and create/edit
  digests.

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
slugs can change, IDs are stable).

Any subset of these fields can be updated:

`title`, `summary`, `body_markdown`, `lead_cluster`, `slug`, `status`,
`published_at`.

Publish workflow:
- `PATCH {id} {"status": "published"}` flips status to `published` and, if
  `published_at` was `NULL`, stamps it to `NOW()`. Already-published rows
  keep their original `published_at`.
- To explicitly override the publish timestamp (e.g. backdating), include
  `"published_at": "YYYY-MM-DD HH:MM:SS"` in the same PATCH.

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
