# Deploying odditytech.news

Production at `https://odditytech.news` is deployed by GitHub Actions over FTPS.
Source of truth: `.github/workflows/deploy.yml` on `main`. Pipeline approved in
[SIG-109](https://paperclip.ing/SIG/issues/SIG-109); wrong-dir post-mortem in
[SIG-258](https://paperclip.ing/SIG/issues/SIG-258).

## Invariants

These two settings must hold together. Drift either of them and deploys silently
land in a phantom directory inside the FTPS user's chroot (green Action, nothing
on disk where Apache serves from).

| Setting | Required value | Why |
|---|---|---|
| GitHub Actions secret `SFTP_TARGET_DIR` | `/` | The FTPS user is **jailed** by cPanel to `~/public_html/`. Its login root IS the document root. |
| `.github/workflows/deploy.yml` step `server-dir:` | `${{ secrets.SFTP_TARGET_DIR }}/` | Resolves to `//` which the action normalises to the jail root. |

### Do **not** set `SFTP_TARGET_DIR` to an absolute path

`/home/oddimfjz/public_html`, `/public_html`, etc. are interpreted *inside* the
chroot, so uploads land at `~/public_html/home/oddimfjz/public_html/...` — a
phantom tree invisible at `https://odditytech.news/...`. The action's
"bytes transferred" log will still show green, because the upload itself
succeeded; it just went to the wrong directory.

### If the FTPS user is ever re-scoped

If a cPanel admin moves the FTPS user's jail (cPanel → FTP Accounts → Directory
column), update `SFTP_TARGET_DIR` to the jail-root-relative path of the document
root, **not** the absolute server path. Re-verify with the post-deploy check
below before merging anything sensitive.

## Post-deploy verification

After every deploy, hit the deploy probe and confirm `file_mtime` has advanced
into the deploy window:

```bash
curl -sS https://odditytech.news/api/v1/healthz.php | python -m json.tool
```

Expected shape:

```json
{
  "ok": true,
  "service": "odditytech.news/api/v1",
  "file_mtime": 1780361834,
  "php_version": "8.2.31",
  "opcache_on": true
}
```

`file_mtime` is the unix mtime of `healthz.php` on disk. Convert with
`date -d @<unix>` or `python -c "import datetime; print(datetime.datetime.utcfromtimestamp(<unix>))"`.
If it is older than the most recent deploy run, the upload landed in the wrong
directory — re-read **Invariants** above.

> **Do not trust the action's "bytes transferred" log alone.** A
> wrong-dir deploy is a green Action and a stale `file_mtime`. Always
> cross-check the served URL.

## Rollback

`git revert <sha> && git push origin main`. The next workflow run redeploys the
prior tree in ~30–60s.

## Force a full re-sync

If `.ftp-deploy-sync-state.json` ever gets out of step with reality (e.g. after
manual cPanel edits or a known-wrong-dir window), delete it from the FTPS user's
root via cPanel File Manager (Show Hidden Files on), then trigger a manual
`workflow_dispatch` run on the Deploy workflow. The action will rebuild the
state file and re-upload anything missing.

Alternative: open a one-off PR setting `dangerous-clean-slate: true` on the
FTPS step; merge, watch the deploy, then immediately PR it back to `false`. Use
only when you're sure `SFTP_TARGET_DIR` is correct, since clean-slate wipes
*everything* at `server-dir` before re-uploading.

## Credential rotation

Rotate the FTPS user password in cPanel, then update the `SFTP_PASSWORD` secret
on `afflictyou/odditytech-news`. No code change needed.

## Audit trail

PR → merge commit SHA → workflow run URL (linked from PR checks) →
cPanel raw-access SFTP log. All four correlate via commit SHA and timestamp.
