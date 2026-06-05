#!/usr/bin/env bash
# publish-digest.sh — flip an odditytech.news /digest entry from draft to published.
#
# Usage:
#   scripts/publish-digest.sh <slug-or-id> [--dry-run]
#
# Requires:
#   - INGEST_API_KEY in the environment (or in ../.env at the repo root)
#   - curl, jq
#
# What it does:
#   1. Looks up the digest by slug (or accepts a numeric id directly).
#   2. Verifies the row is currently a draft. Already-published rows exit non-zero.
#   3. PATCHes /api/v1/digests/{id} with {"status":"published"} — the server stamps
#      published_at = NOW() when transitioning from draft.
#   4. Confirms the published row is now publicly reachable.
#
# Reserved for CEO use. The SSCI Digest Editor agent has draft-write access only
# and must NOT call this script (see docs/digest_workflow.md §"Permissions").

set -euo pipefail

BASE_URL="${DIGEST_BASE_URL:-https://odditytech.news}"

usage() {
  cat >&2 <<EOF
Usage: $0 <slug-or-id> [--dry-run]

  <slug-or-id>   The digest slug (e.g. week-of-2026-05-29) or numeric id.
  --dry-run      Print the PATCH that would be sent, do not perform it.

Environment:
  INGEST_API_KEY   Required. Editorial bearer key (X-API-KEY header).
  DIGEST_BASE_URL  Optional. Defaults to https://odditytech.news.
EOF
  exit 2
}

[[ $# -ge 1 ]] || usage
TARGET="$1"
DRY_RUN=0
[[ "${2:-}" == "--dry-run" ]] && DRY_RUN=1

if [[ -z "${INGEST_API_KEY:-}" ]]; then
  ENV_FILE="$(cd "$(dirname "$0")/.." && pwd)/.env"
  if [[ -f "$ENV_FILE" ]]; then
    # shellcheck disable=SC1090
    set -a; . "$ENV_FILE"; set +a
  fi
fi
if [[ -z "${INGEST_API_KEY:-}" ]]; then
  echo "publish-digest: INGEST_API_KEY not set (export it or add it to .env)" >&2
  exit 1
fi

command -v jq >/dev/null || { echo "publish-digest: jq is required" >&2; exit 1; }

# Resolve id + current status. If TARGET is numeric, look it up via list+filter;
# otherwise treat it as a slug and hit the single-slug endpoint.
if [[ "$TARGET" =~ ^[0-9]+$ ]]; then
  ROW=$(curl -fsS -H "X-API-KEY: $INGEST_API_KEY" \
    "$BASE_URL/api/v1/digests/?status=draft" \
    | jq --argjson id "$TARGET" '.digests[] | select(.id == $id)')
  if [[ -z "$ROW" ]]; then
    # Maybe it's already published — fall through to the lookup-by-id error.
    ROW=$(curl -fsS -H "X-API-KEY: $INGEST_API_KEY" \
      "$BASE_URL/api/v1/digests/" \
      | jq --argjson id "$TARGET" '.digests[] | select(.id == $id)')
  fi
else
  ROW=$(curl -fsS -H "X-API-KEY: $INGEST_API_KEY" \
    "$BASE_URL/api/v1/digests/$TARGET")
fi

if [[ -z "$ROW" || "$ROW" == "null" ]]; then
  echo "publish-digest: digest '$TARGET' not found" >&2
  exit 1
fi

ID=$(echo "$ROW" | jq -r '.id')
SLUG=$(echo "$ROW" | jq -r '.slug')
STATUS=$(echo "$ROW" | jq -r '.status')

echo "publish-digest: target id=$ID slug=$SLUG status=$STATUS"

if [[ "$STATUS" == "published" ]]; then
  echo "publish-digest: already published (published_at=$(echo "$ROW" | jq -r '.published_at')) — nothing to do" >&2
  exit 1
fi

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "publish-digest: --dry-run set, would PATCH $BASE_URL/api/v1/digests/$ID with {\"status\":\"published\"}"
  exit 0
fi

PATCHED=$(curl -fsS -X PATCH \
  -H "X-API-KEY: $INGEST_API_KEY" \
  -H "Content-Type: application/json" \
  --data '{"status":"published"}' \
  "$BASE_URL/api/v1/digests/$ID")

NEW_STATUS=$(echo "$PATCHED" | jq -r '.status')
PUBLISHED_AT=$(echo "$PATCHED" | jq -r '.published_at')

if [[ "$NEW_STATUS" != "published" ]]; then
  echo "publish-digest: PATCH did not flip status (got '$NEW_STATUS')" >&2
  exit 1
fi

echo "publish-digest: published id=$ID slug=$SLUG published_at=$PUBLISHED_AT"

# Verify the row is now publicly visible (no auth header on this call).
PUBLIC_STATUS=$(curl -o /dev/null -s -w '%{http_code}' "$BASE_URL/api/v1/digests/$SLUG")
if [[ "$PUBLIC_STATUS" != "200" ]]; then
  echo "publish-digest: WARN — public GET /api/v1/digests/$SLUG returned $PUBLIC_STATUS" >&2
  exit 1
fi
echo "publish-digest: public GET 200 at $BASE_URL/digest/$SLUG"
