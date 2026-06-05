# /digest weekly publication workflow

End-to-end loop for the public `/digest` publication on odditytech.news, from
SSCI corpus to a published page. Covers the SSCI Digest Editor's authoring
flow, the CEO review handoff, and the publish step.

References:
- API contract: [`docs/api.md`](api.md) §"Digests — `/api/v1/digests/`"
- Schema: [`docs/migrations/0002_digests.sql`](migrations/0002_digests.sql)
- Scoping plan: [SIG-174 #document-plan](/SIG/issues/SIG-174#document-plan) §2
- Routine spec: [SIG-183](/SIG/issues/SIG-183)
- Editor hire: [SIG-182](/SIG/issues/SIG-182)

---

## Roles

| Role | Agent | What they can do | What they cannot do |
| --- | --- | --- | --- |
| Editor | SSCI Digest Editor | Read SSCI corpus (`/api/v1/headlines/`, `research_log.json`). `POST /api/v1/digests/` and `PATCH /api/v1/digests/{id}` for drafts. | Cannot publish — must not send `status=published`. |
| Publisher | CEO | Reviews the draft, requests changes, or publishes. | — |

The endpoint accepts `status=published` from any holder of `INGEST_API_KEY`,
so this division of labour is currently enforced by agent instructions rather
than by API permissions. If the policy needs to be hardened, scope a CTO task
to add a separate "publisher" key or per-agent ACL.

---

## Cadence

Friday 17:00 America/Los_Angeles. A Paperclip routine on the SSCI Digest
Editor fires the assembly issue each week (cron `0 17 * * 5`,
`America/Los_Angeles`). Concurrency policy is `skip_if_active` — a still-open
prior draft is left alone, not duplicated. See [SIG-183](/SIG/issues/SIG-183)
for the routine registration.

---

## 1. Routine fires → assembly issue

The routine creates an issue titled "Assemble digest for week ending {date}"
assigned to the SSCI Digest Editor. The Editor agent picks it up on its next
heartbeat.

## 2. Editor assembles the draft

For the trailing 7-day window the Editor:

1. Pulls headlines via `GET /api/v1/headlines/?since=…&until=…`.
2. Identifies clusters per the workflow in [SIG-170 #document-plan](/SIG/issues/SIG-170#document-plan) §1.
3. Selects a lead cluster + supporting clusters.
4. Drafts a 700–1000 word editorial in markdown.

## 3. Editor posts the draft

```sh
curl -X POST \
  -H "X-API-KEY: $INGEST_API_KEY" \
  -H "Content-Type: application/json" \
  --data @draft.json \
  https://odditytech.news/api/v1/digests/
```

`draft.json` carries `title`, `body_markdown`, `summary`, `lead_cluster`, and
optionally `slug`. Omit `status` (the endpoint defaults to `draft`); explicitly
including `status=draft` is also fine. **Never send `status=published`** —
that is the CEO's prerogative.

The response is the persisted row, including the resolved `slug` and `id`.

## 4. Editor opens the CEO review issue

The Editor creates a child Paperclip issue assigned to CEO:

- **Title:** "Review digest: {slug}"
- **Body:** must contain
  - the rendered draft markdown,
  - a preview link: `https://odditytech.news/digest/{slug}?preview=draft`
    (auth required to render — drafts are 404 to anonymous callers),
  - two CTAs:
    - **Approve & publish:** run `scripts/publish-digest.sh {slug}` from a
      checkout of this repo on a host with `INGEST_API_KEY` in env.
    - **Request changes:** comment on the issue with the change list; the
      Editor will revise via `PATCH /api/v1/digests/{id}` and update the
      issue.

The child issue should be created via the Paperclip API with
`parentId` set to the routine's run issue, so review threads are linked back
to the weekly run.

## 5. CEO reviews

- **Preview the draft:** open `https://odditytech.news/digest/{slug}?preview=draft`
  while signed in. Unauthenticated visitors get 404 — drafts never leak.
- **Approve:** run `scripts/publish-digest.sh {slug}` (see below).
- **Request changes:** comment on the review issue with the change list.

## 6. Publish

The publish helper is a small bash/curl wrapper at
[`scripts/publish-digest.sh`](../scripts/publish-digest.sh).

```sh
# Dry run — shows the PATCH it would send, no state change.
scripts/publish-digest.sh week-of-2026-05-29 --dry-run

# Real publish.
scripts/publish-digest.sh week-of-2026-05-29
```

What it does:

1. Resolves the slug to a row, confirms the row is currently a draft.
2. `PATCH /api/v1/digests/{id}` with `{"status":"published"}`. The server
   stamps `published_at = NOW()` on the draft → published transition (it does
   not overwrite a non-NULL `published_at`).
3. Verifies the row is now publicly reachable via unauthenticated
   `GET /api/v1/digests/{slug}`.

Exit non-zero on: missing `INGEST_API_KEY`, slug not found, already
published, PATCH failure, or unauthenticated GET not returning 200.

Numeric ids are also accepted: `scripts/publish-digest.sh 42`.

---

## Reverting / unpublishing

There is no `--unpublish` flag. To pull a row back to draft:

```sh
curl -X PATCH \
  -H "X-API-KEY: $INGEST_API_KEY" \
  -H "Content-Type: application/json" \
  --data '{"status":"draft"}' \
  https://odditytech.news/api/v1/digests/{id}
```

`published_at` is left as-is when going back to draft. Re-publishing the same
row preserves the original `published_at` (the endpoint only stamps when
`published_at` is NULL). To force a new publish timestamp, include
`"published_at": "YYYY-MM-DD HH:MM:SS"` in the PATCH explicitly.

---

## Operational notes

- **Where the key lives.** `INGEST_API_KEY` lives in the server `.env` (loaded
  by `api/v1/digests/index.php`). The same key is used by SSCI Integration
  for `/api/v1/headlines/`. The CEO's local checkout reads it from a `.env`
  at the repo root or directly from the environment.
- **Skip if active.** The routine uses `skip_if_active` — if the previous
  week's run issue is still `in_progress` or `in_review`, the new trigger is
  recorded as skipped and no duplicate issue is created. Resolve the prior
  issue before expecting the next run.
- **Catch-up.** `skip_missed`. If the server is down on Friday, the missed
  run is dropped — the Editor does not double-fire on Saturday recovery.
- **Manual fire.** The CEO can manually fire the routine via the routine's
  manual-run endpoint (see Paperclip routines reference).
