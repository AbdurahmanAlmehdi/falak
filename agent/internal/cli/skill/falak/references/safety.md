# Safety rules for operating Falak

Falak runs people's production. Treat every write as if it were on production unless you checked it isn't
(`is_production` on the environment, the site's domain, the human's words).

## Always ask the human first

Show the exact command (or request) and what it will do, and wait for a clear yes for **that** action. A general
"go ahead" earlier in the conversation doesn't cover a destructive step you discover later.

| Action | Why |
|---|---|
| `falak db delete <instance> --confirm NAME` | Deletes the database container |
| … with `--delete-volume`, or `DELETE /api/v1/databases/instances/{id}` with `delete_volume: true` | **Irreversible data loss** |
| `DELETE /api/v1/sites/{site}` (and `delete_volumes: true`) | The site is gone at once; compose volumes deleted with the flag |
| `DELETE /api/v1/servers/{server}` | Removes a server; no confirmation field |
| `DELETE /api/v1/projects/{project}`, `…/environments/{environment}` | Removes a project / environment |
| `falak apply --allow-destroy` (`allow_destroy: true`) | Deletes units, databases and volumes the file removed |
| `falak approve <run>`, `falak apply --approve`, `POST …/approve` | Approving risky changes (major DB upgrades, public databases, fewer backups, removed production domains…) is a human decision |
| `falak reject <run>` | Cancels someone's run |
| Removing a unit, a database, backups or a production domain from falak.yaml | Detaches or deletes on the next push; lowering backup retention or turning off PITR loses recovery points |
| `falak env push`, `PUT /api/v1/sites/{site}/env` | **Replaces every variable** of the site |
| `falak secret set` on an existing name, `PUT …/secrets/{id}/value`, `POST …/rollback` | Changes what production reads at the next deploy (rotating a secret) |
| `falak secret delete … --yes`, `DELETE /api/v1/secrets/{id}` | Deployments referencing it fail |
| `falak rollback <site>` in production, `fn rollback`, `fn deploy --force` | Replaces the live release / overwrites a colleague's version |
| `falak servers stack`, `POST …/provision`, `…/reprovision`, agent upgrades | Re-provisions a server |
| Security fixes with `confirm: true` (SSH hardening, updates, reboots) | Can lock people out or reboot |
| `falak console …` (any), `falak ssh` | A shell on production, audited and recorded |
| `falak net capture --yes` | Captures may hold cookies, tokens and personal data (kept encrypted 24 h, downloads audited) |
| Firewall rules, IP blocks, protection (auto-ban) settings, private networks | Can cut off traffic or the human's own access. These are dashboard actions: describe what to change, let the human do it |
| Database restores, PITR restores, "swap" decisions | **Overwrite data.** Dashboard only (`databases.restore`); never attempt them through a console |
| Anything with `falak-ctl` on the control-plane host: `restore`, `kek rotate`, `kek import --force`, `dr setup`, `update`, `domain set`, `down` | Disaster-recovery and key operations; a lost KEK or DR passphrase makes backups unreadable. Only on the human's explicit instruction, and they run them as root |
| Creating or revoking API tokens, changing members, roles, teams | Access control is the human's |

Read-only commands (`list`, `show`, `logs`, `releases`, `plan`, `validate`, `drift`, `runs`, `config export`,
`secret list`, `whoami`, `dns/check`) need no confirmation.

## Prefer a sandbox

When you need to run something destructive, untrusted or experimental, run it in a Falak sandbox
(`falak sandbox create` / `exec`), not in a console on a server and not on the human's machine. A sandbox can't
reach the host, its sites, private networks or cloud metadata, and everything in it is gone when it is killed.

- Creating, using and killing **your own** sandboxes needs no confirmation (they're disposable). Kill them when you
  are done (`falak sandbox kill`); don't leave them running until their time limit.
- Ask before killing a sandbox someone else created (`created_by` in `falak sandbox show`), and before
  `--network internet` work that sends the human's code or data somewhere.
- Don't put long-lived secrets into a sandbox (`--env`, files). For a private repository use `--repo-secret NAME`:
  the token is used for the clone and not kept in the sandbox's environment. It needs `secrets.reveal` (the value
  reaches the sandbox) and a secret that is not sensitive; don't ask for that ability just to get around a refusal.
- Copy results out (`falak sandbox cp`, `git diff`) before killing it.

## Never print secrets

- Don't echo, log or paste: API tokens, `credentials.json`, `.env` contents, secret values, deploy hook URLs
  (`/api/deploy/{token}` is a credential), console relay URLs (they carry a ticket), database passwords, generated
  template inputs, DR passphrases, KEK files.
- Pass tokens through `FALAK_TOKEN` in the environment; avoid `--token` on the command line.
- `falak env pull` writes to stdout by default: always use `--file PATH` (mode 0600) and delete the file when done.
  `--json` on `env pull` prints the whole `.env` too.
- `falak secret set` reads the value from stdin; never put it in an argument, a heredoc you show, or a committed file.
  `falak secret list` never shows values, which is all you normally need.
- Don't call `POST /api/v1/secrets/{id}/reveal` unless the human asked for that specific value.
- falak.yaml must reference secrets (`${{ secrets.NAME }}`); a literal credential in `env:` is a leak into git.
  `falak init` already turns secret-looking values into references; keep it that way.
- When reporting errors, quote the message, not the request body.

## Prefer machine-readable output and exit codes

- `--json` on every command you parse. `falak logs --json` is NDJSON. `deploy --wait --json` streams lines to
  stderr and prints the final deployment JSON on stdout.
- Branch on exit codes: `3` deployment failed, `4` function conflict, `5` invalid falak.yaml, `6` waits for an
  approval, `7` drifted, `2` usage (your mistake: read `falak <command> -h`), `1` anything else.
- A `403` means the token lacks the ability or the user's role lacks the permission. Report which; don't retry with
  another token you happen to find.

## Idempotent, low-risk patterns

- **Look, then act**: `sites show` / `releases` / `db list` / `plan` before any change; `drift` before applying over
  someone's manual edits.
- **Config as code is the idempotent path**: `falak validate` → `falak plan` → `falak apply`. The file states the desired
  result, so re-planning an applied file shows no changes, and only units whose files or config changed redeploy.
  `falak config export` writes today's state, which plans empty.
- **Deploys are safe to repeat** but not free: one `falak deploy --wait`, read the result, fix, then deploy again.
  While a deployment is `waiting`, triggering again updates that same deployment instead of queuing another.
- `falak secret set` is an upsert (a new version each time); `falak env push` is a full replace; plan accordingly.
- `falak init` never overwrites without `--force`; `falak net capture -o` never overwrites a file; `fn deploy`
  refuses to overwrite a newer version (exit 4) unless `--force`.
- Use `--wait` (with `apply --timeout`) instead of polling loops; if you poll the API, poll slowly (2-5 s) and stop
  on a terminal status.
- On `429`, wait and retry once; on `5xx` during a deploy, check the deployment's status before triggering again.

## Least privilege

Ask for a token with only what the job needs (Settings → API tokens → abilities), and an expiry:

| Job | Abilities |
|---|---|
| Read-only inspection | `sites.view`, `servers.view`, `deployments.view`, `telemetry.view`, `projects.view` (+ `databases.view`, `functions.view`, `config.view`) |
| CI deploys of existing sites | `sites.view`, `deployments.view`, `deployments.create` (+ `deployments.rollback` if it rolls back) |
| Config as code without approvals | `projects.view`, `config.view`, `config.apply`, `secrets.view` (+ `secrets.manage` to set secrets) |
| Functions | `functions.view`, `functions.deploy`, `deployments.view`, `deployments.create`, `telemetry.view` |

Leave out `*`, `config.approve`, `config.destroy`, `databases.manage`, `sites.delete`, `servers.delete`,
`secrets.reveal`, `terminal.open`, `network.capture` unless the human decided the agent should have them.
