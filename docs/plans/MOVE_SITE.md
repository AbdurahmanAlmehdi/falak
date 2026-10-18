# Move a site to another server (plan, v0.23.1)

The ask: an easy way to move sites off the control-plane machine (or any server) onto a new one.

## What exists (2026-10-18)
- `SetSiteTargets`: add/remove servers and change the leader; the edge, processes and DB networks follow.
- `MoveVolume`: non-compose volumes, services stop → archive → restore on the target → switch → redeploy → delete source.
- `CloneVolume`: any volume, across servers through object storage.
- `RestoreBackup`: a database backup into a database of another (compatible) instance.
- Recovery runner: the same steps for a *lost* server (sites → databases → volumes → deploy → domains).

## The move (one wizard, one operation with steps, like ServerRecovery)
1. **Check**: the target server is ready, has the runtime/engine, enough free memory and disk (CapacityReport);
   object storage is configured when there is state to carry; shows the expected downtime (stateful parts only).
2. **Add target**: `SetSiteTargets` adds the new server as a member (the old one stays leader, traffic unchanged);
   deploy to it. Stateless sites stop here, apart from step 5.
3. **Volumes**: each attached volume moves (`MoveVolume`; compose volumes: clone + switch the stack's volume to
   the copy, then delete the source — new, shares MoveVolume's steps). Services are down from archive to redeploy.
4. **Databases** on the old server that only this site uses: new instance on the target (same engine/version),
   backup → `RestoreBackup`, the site's env/links switch to it, redeploy. Shared databases are listed and left alone
   (move them separately).
5. **Cut over**: the new server becomes leader, the old target is removed. Managed Cloudflare records move
   automatically; other domains show the record to change (and the old server keeps proxying until DNS moves —
   a temporary upstream on the old edge pointing at the new server, removed after 48 h or when DNS matches).
   Generated `sslip.io` domains change with the IP: say so, offer a test domain/custom domain instead.
6. **Clean up**: delete the old copies only after the site is healthy on the new server; keep the old volume
   snapshot/backups for 7 days.

Failures stop the move with the old server still serving (every step before the cutover is additive).

## UI
Site → Settings → "Move to another server"; server page → "Move all sites" (runs the moves one by one). The CP
machine's server page links to it ("Move these sites to their own server").

## Not in scope
Live replication / zero-downtime database moves; moving between organizations.
