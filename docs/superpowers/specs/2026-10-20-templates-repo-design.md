# Templates in their own repository — design

Status: draft for review (2026-10-20)

## Why

The curated catalog (46 templates) lives in `templates/` of the main repo and is baked into the control-plane image
(`control-plane/Dockerfile` → `/opt/falak/templates`). Adding or fixing one template today means a Falak release, new
images and updating every panel. The owner wants template work to ship on its own: merge a template PR, every install
sees it within the hour, no release. Template authors (the owner and outside contributors) need a local way to check a
template against Falak and boot it before opening a PR.

## Goals

- New public repo `falak-sh/templates`: the source of truth for the curated catalog. Outside PRs welcome.
- Running panels pick up merged templates by themselves (no Falak release, no image rebuild).
- One local command checks a template with Falak's own rules and boots it with Docker:
  `./test <slug> [--falak <version>]`. CI runs the same command on every PR.
- Templates declare the oldest Falak that can run them; older panels skip them instead of breaking.

## Non-goals

- Upgrading apps already deployed from a template. A catalog update only changes new deploys (sites keep their
  rendered compose and the `template` slug/version they were created with).
- Managing templates through `falak.yaml` or the API (beyond what exists).
- Deploying PRs to a real Falak server/sandbox. The bar for merging is: passes Falak's checks + boots healthy on Docker.
  The agent/edge path (Caddy, volumes on servers, deploy queue) is Falak's own tested code.
- Changing custom (organization) templates — they stay in the database, untouched.
- Signing the catalog (see Security; noted as a later step).

## Anti-goals (would be a failure even if it works)

- A second implementation of Falak's parser/validator/renderer (e.g. in Go or JS) that drifts from the panel. The
  local check must run the panel's own PHP code.
- A panel that breaks or shows an empty gallery because GitHub / the catalog URL is down.
- A template that passes CI but fails validation on a supported panel version.

## Decided

### 1. The repository: `falak-sh/templates`

```
<slug>/template.yaml
<slug>/compose.yaml
<slug>/icon.svg          (optional)
test                     the local + CI test script (bash; needs only Docker)
.github/workflows/       PR check + release
README.md / CONTRIBUTING.md
```

Same template format as today (docs/COMPOSE_TEMPLATES.md §2), plus one optional field:

```yaml
min_falak: 1.2.0   # oldest Falak release that can run this template; default: the release that introduced the remote catalog
```

The history of `templates/` moves with it (git filter-repo / subtree split), so blame and log survive.

### 2. Publishing

On every merge to `main`, a workflow builds `catalog.tar.gz` (the template directories as they are) plus
`catalog.json` (`{version, sha256, generated_at}`; `version` = a date-based counter like `2026.10.20.3`) and publishes
them as a GitHub release, so the stable URL is
`https://github.com/falak-sh/templates/releases/latest/download/catalog.json` (+ `.tar.gz`). No extra hosting.

### 3. The panel fetches the catalog

- Config `FALAK_TEMPLATES_URL` (custom.env), default the GitHub "latest" URL above; empty turns remote updates off
  (the baked copy is used). Same pattern as `FALAK_ICONS_URL`. A fork can point it at its own releases.
- A scheduled command `templates:sync` (hourly, plus run once at boot/update) downloads `catalog.json`; when its
  version is new it downloads the tarball, checks the sha256 and size, extracts into
  `storage/app/templates/<version>/` and only then switches to it (atomic: a pointer file / symlink to the current
  version; keep the previous one, delete older).
- `FilesystemCatalog` stays as is and reads from the current synced directory; when none exists (fresh install,
  remote off, every fetch failed) it reads the baked copy in the image. A failed or bad download never replaces the
  last good catalog.
- Templates whose `min_falak` is newer than the running panel are skipped (not shown, not deployable). `min_falak`
  is read before strict parsing (the parser rejects unknown keys), so a template using a newer key is skipped quietly
  on an older panel instead of being logged as broken.
- Which copy is used *(builder's call)*: the newest version among the synced copy and the baked copy (an upgraded image
  with a newer baked catalog wins over an old sync). Changing or clearing `FALAK_TEMPLATES_URL` discards the synced
  copy, so a fork never mixes with the official catalog and "empty" really means the baked copy.
- The web role runs FrankenPHP worker mode, where `FilesystemCatalog`'s parsed templates live for the whole process:
  the catalog must notice a switched version (e.g. compare the current-version pointer per request) so a sync shows up
  without a restart.
- Slug collisions with organization templates *(builder's call)*: a new catalog template may now appear hourly with a
  slug an organization already uses for a custom template. Lookups that know the source pass it explicitly, so the
  custom template stays reachable and deployable; the catalog one simply appears next to it.
- Settings → Templates shows one line: catalog version and when it was last updated (or "built-in catalog").
- The fetch uses the existing outbound HTTP guards (https, timeout, max size) — reuse what `GuardedHttpFetcher`
  offers where it fits.

### 4. The image still bakes a catalog

The main repo drops `templates/` and pins a catalog release in a small lock file (version + sha256). The image build
downloads that release into `/opt/falak/templates`. Offline / air-gapped installs and first boots work as today.
Bumping the lock is an ordinary main-repo change, done when convenient (e.g. at each Falak release).

### 5. The local check uses Falak itself

The panel already has `php artisan templates:render <slug> [--domain] [--env-file]` (validate + print the rendered
compose + sample inputs). It is extended into the authoring entry point:

- accept a directory path (not just a catalog slug), so a template outside the catalog can be checked;
- run the **catalog quality rules** that today live only in `TemplateCatalogTest` (every service has a healthcheck and
  `restart: unless-stopped`, mounts are named volumes, stateful templates keep data in named volumes,
  `min_memory_mb` and `docs` set, one-line description, icon rules, `min_falak` well-formed). Move them into one
  catalog-only rule set that the command and the main-repo tests share. *(Builder's call:)* they are a separate pass,
  not part of `TemplateValidator::problems()` — custom/imported organization templates keep today's rules — and the
  running panel does not enforce them when loading the catalog (it keeps applying only today's validation), so a
  stricter rule in a newer panel never hides published templates;
- exit non-zero with readable problems.

The templates repo's `./test <slug> [--falak <version>]` then:

1. runs `ghcr.io/falak-sh/falak-control-plane:<version>` (default: latest release) with the template directory mounted,
   calling the command above → problems or the rendered compose + env file;
2. `docker compose up -d --wait` on the rendered compose (each service's healthcheck must pass, generous timeout),
   then requests every public service's port on its `health_check_path` (default `/`) from inside the compose
   network and expects an answer below 500;
3. prints a short report, tears everything down (`down -v`), exit code = pass/fail.

`--falak <version>` is the compatibility check: the same template against an older panel.

### 6. CI on pull requests

For each template changed in the PR: `./test <slug>` against the latest Falak release and against the template's
`min_falak`. Also checks every image tag is pinned and exists for amd64 + arm64 (`docker manifest inspect`). amd64 + arm64 is a hard
requirement (it already is for the catalog, docs/COMPOSE_TEMPLATES.md §2.1); the first full run during the move audits
the existing 46 and fixes any that fall short. Runs on GitHub-hosted runners; outside contributors get the same result they would locally. Merging stays the owner's call.

### 6b. A Falak change must not break the published catalog

"Supported" means every Falak release from a template's `min_falak` up (no `max_falak`). *(Builder's call:)* the
main repo's CI runs the static check (the command from §5, no boot) over the latest published catalog and over the
locked one, so a panel change that would reject or hide a published template fails the main repo's PR, not the
installs. Fix the templates first, then change the panel.

### 7. Icons

A template's `icon` may be a Cool Icons key (`slug` or `slug/variant`, coolicon.falak.sh) besides `./icon.svg`, so a
new template never needs a panel release for its logo. The existing bundled brand icons keep working. The check
rejects an icon that is neither a known key format nor a present, script-free `icon.svg`.

## Security

- The catalog is code every panel offers to deploy. Trust = review of PRs in `falak-sh/templates` + TLS to GitHub +
  sha256 from `catalog.json`. Every template still goes through the panel's compose policy at deploy (no privileged
  containers, no host mounts, …), and nothing deploys without a user clicking Deploy.
- Branch protection on `main` (required check, review) is part of setting the repo up.
- Later, if wanted: sign `catalog.json` (minisign/cosign) with a key whose public half is baked into the panel.

## Main-repo changes (summary)

- Remove `templates/`; add the catalog lock + image build step; drop `templates/**` from the images workflow filters.
- `templates:sync` + schedule, `FALAK_TEMPLATES_URL`, synced-or-baked catalog path, `min_falak` parsing + skip.
- `templates:render` → path argument + catalog quality rules (moved out of `TemplateCatalogTest`).
- Tests use small fixtures instead of the real catalog; `TemplateCatalogTest`'s list of 46 slugs goes away.
- Docs: docs/COMPOSE_TEMPLATES.md (catalog now external), docs/INSTALL.md (`FALAK_TEMPLATES_URL`), sim E2E keeps
  deploying two catalog templates from the baked copy.

## Left to the builder

- Exact lock file name/format, release version scheme details, storage layout for synced catalogs.
- How the test script reaches public ports (a curl container on the compose network is the default idea).
- Wait timeouts per template (ERPNext and Dify take minutes).
- Whether the per-template regression tests (ERPNext setup jobs, Odoo threading, Dify sandbox network) are kept as
  small checks in the templates repo or replaced by the boot test.
