# Templates in their own repository — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship catalog templates from `falak-sh/templates` to running panels without a Falak release, with a local
`./test <slug>` that checks a template with Falak's own code and boots it on Docker.

**Architecture:** The panel gains `min_falak`, Cool Icons template icons, a catalog-only rule set, a path-capable
`templates:render`, and an hourly `templates:sync` that downloads a published catalog tarball into shared storage;
`FilesystemCatalog` reads whichever of the synced and baked copies is newer, re-checked per call. A new repo holds the
templates, a bash test script that runs the control-plane image as the checker, and PR + release workflows. Last, the
main repo drops `templates/` and bakes a pinned catalog release.

**Tech Stack:** Laravel 12 / Pest (control plane), React + vitest (icons), bash + Docker Compose (test script), GitHub
Actions.

**Spec:** `docs/superpowers/specs/2026-10-20-templates-repo-design.md`

## Global Constraints

- Repo `falak-sh/templates`; image `ghcr.io/falak-sh/falak-control-plane:<version>`.
- Default `FALAK_TEMPLATES_URL` = `https://github.com/falak-sh/templates/releases/latest/download`; empty = remote off.
- Release assets: `catalog.json` = `{"version": "...", "sha256": "...", "generated_at": "..."}` and `catalog.tar.gz`
  (template directories + a `VERSION` file at its root). Version = `YYYY.MM.DD.<run number>` (compares with
  `version_compare`).
- `min_falak`: semver `X.Y.Z`; absent = no constraint. Panel version from `ReleaseCheck::currentVersion()`; a panel with
  no version (dev) skips nothing.
- Catalog rules never apply to custom/imported organization templates (`TemplateValidator::problems()` is unchanged).
- A failed/bad download never replaces the last good catalog.
- Every catalog image: pinned tag, amd64 + arm64.
- Main repo: signed commits, explicit `git add <paths>`, gitleaks gate before push, commit trailer
  `Claude-Session: https://claude.ai/code/session_01XCgbNPAbZsBNRNSzXuJZ3H`. ShellCheck must pass (no `A && B || C`).

## Order of shipping

1. **PR A** (main repo, Tasks 1–6) keeps `templates/` baked → release **Falak v1.2.0** (the checker image the templates
   repo needs).
2. **Templates repo** (Tasks 7–9): created only after the owner says yes (public repo = outward-facing); first release.
3. **PR B** (main repo, Task 10): drop `templates/`, bake the pinned release, compatibility job.

## Review Focus

- A catalog tarball with `../` or absolute paths, symlinks, or > 20 MB → rejected, last good catalog kept (Task 4 test).
- GitHub returns 404/HTML/garbage for `catalog.json` (no release yet, rate limit) → no change, error logged, gallery
  still shows the baked catalog (Task 4 test).
- A synced catalog older than the baked one after an image upgrade → baked wins (Task 4 test).
- A template using a key the running panel doesn't know, with `min_falak` above the panel → skipped quietly, no
  warning log (Task 1 test).
- An organization's custom template whose slug later appears in the catalog → still editable and deployable with
  `source=custom` (Task 5 test).

---

### Task 1: `min_falak` + skip on older panels

**Files:**
- Modify: `control-plane/modules/Templates/src/Application/Catalog/TemplateParser.php` (KEYS, parse)
- Modify: `control-plane/modules/Templates/src/Domain/Template.php` (new `public ?string $minFalak = null` after `$iconPath`)
- Modify: `control-plane/modules/Templates/src/Infrastructure/FilesystemCatalog.php` (`load()`)
- Test: `control-plane/modules/Templates/tests/Unit/TemplateParserTest.php`, `.../tests/Feature/TemplateGalleryTest.php`

**Interfaces:**
- Produces: `Template::$minFalak` (`?string`); `FilesystemCatalog::supported(string $templateYaml, ?string $panel): bool`
  (public static — reads only `min_falak` with `Yaml::parse`, true when absent, unparsable, or panel is null).

- [ ] **Step 1: Failing tests**
  - `it('reads min_falak')`: parse with `min_falak: 1.2.0` → `->minFalak === '1.2.0'`; `min_falak: 1.2` → `InvalidTemplate`
    mentioning `min_falak`.
  - `it('skips templates that need a newer Falak, quietly')`: `templates_fixture_catalog` with `hello` and `future`
    (`min_falak: 9.0.0` plus an unknown key `brand_new: true`); `config(['controlplane.version' => 'v1.2.0'])`;
    `Log::spy()`; `app(Catalog::class)->all()` slugs = `['hello']`; `Log::shouldNotHaveReceived('warning')`.
    Also `FilesystemCatalog::supported("min_falak: 9.0.0", null) === true` (dev panel without a version).
- [ ] **Step 2:** `./vendor/bin/pest modules/Templates/tests --filter="min_falak|newer Falak"` → FAIL.
- [ ] **Step 3:** Add `'min_falak'` to `KEYS`, validate `/^\d+\.\d+\.\d+$/`, pass to `Template`. In `load()`, before
  `$this->parser->parse(...)`, `continue` when `! self::supported($file['template'], app(ReleaseCheck::class)->currentVersion())`
  (compare with `version_compare(ltrim($panel, 'v'), $min, '>=')`).
- [ ] **Step 4:** Same pest filter → PASS; whole `modules/Templates/tests` → PASS.
- [ ] **Step 5:** Commit `Templates: min_falak — older panels skip templates they can't run`.

### Task 2: Cool Icons keys as template icons

**Files:**
- Modify: `TemplateParser.php:80-83` (icon rule)
- Modify: `control-plane/modules/Templates/resources/js/components/template-icon.tsx` (fallback)
- Test: `tests/Unit/TemplateParserTest.php`, `resources/js/components/template-icon.test.ts`

**Interfaces:**
- Consumes: `ServiceIcon` (`resources/js/components/falak/service-icon.tsx`, prop `fallback`), the Cool Icons key
  pattern `^[a-z0-9][a-z0-9-]{0,63}(\/[a-z0-9][a-z0-9-]{0,47})?$` (copy it as `TemplateParser::ICON_PATTERN`).

- [ ] **Step 1: Failing tests** — parser accepts `icon: docker/svgl-dark` and `icon: php-fpm`, rejects `Docker`,
  `../x`, `https://x/y.svg`; vitest: `TemplateIcon` with `{type:'brand', key:'astro'}` (not in `BRANDS`) renders an
  `<img>` whose `src` is `https://coolicon.falak.sh/i/astro.svg` when `icons_url` is shared (mock `usePage`).
- [ ] **Step 2:** Run both → FAIL.
- [ ] **Step 3:** Parser: allow `./icon.svg` or `ICON_PATTERN`; error text `must be a Cool Icons key (e.g. n8n,
  docker/svgl-dark) or ./icon.svg`. `TemplateIcon`: when no `BRANDS` hit and `icon?.type === 'brand'`, return
  `<ServiceIcon name={icon.key} size={size} className={className} fallback={<LayoutTemplate … />} />`.
- [ ] **Step 4:** pest + `bun run test` → PASS; `bun run types`, `bun run lint:check` clean.
- [ ] **Step 5:** Commit `Templates: icons can be Cool Icons keys`.

### Task 3: Catalog rules + `templates:render <path>`

**Files:**
- Create: `control-plane/modules/Templates/src/Application/Catalog/CatalogRules.php`
- Modify: `control-plane/modules/Templates/src/Application/Console/RenderTemplateCommand.php`
- Modify: `control-plane/modules/Templates/tests/Feature/TemplateCatalogTest.php` (use `CatalogRules`, drop
  `CATALOG_BRAND_ICONS`)
- Create: `control-plane/modules/Templates/tests/Feature/RenderTemplateCommandTest.php`

**Interfaces:**
- Produces: `final class CatalogRules { /** @return list<string> */ public function problems(Template $template, ?string $iconFile): array }`
  — exactly the rules now inside `it('validates every catalog template')` (healthcheck + `restart: unless-stopped` per
  service, mounts are named volumes, stateful ⇒ named volumes, `min_memory_mb`, `docs`, description ≤ 120 chars,
  `./icon.svg` exists and has no `<script|on[a-z]+=|href=`), messages kept as they read today
  (`"{$slug}: services.{$name} needs a healthcheck"`, …). The brand-list rule is dropped (Task 2 made any key valid).
- Produces: `php artisan templates:render {template : catalog slug, or a directory with template.yaml}` — same options;
  for a directory it parses `template.yaml` + `compose.yaml` (+ `icon.svg`) directly; problems = validator + catalog
  rules, written to stderr; exit 1 on any.

- [ ] **Step 1: Failing tests** in `RenderTemplateCommandTest`: (a) a temp dir with the fixture template → exit 0,
  stdout contains the rendered compose with `hello.falak.test`; `--env-file` written; (b) same dir with a service missing
  its healthcheck → exit 1, stderr contains `needs a healthcheck`; (c) a dir with no `template.yaml` → exit 1.
  `TemplateCatalogTest` now calls `app(CatalogRules::class)->problems(...)` and expects `[]` for each of the 46.
- [ ] **Step 2:** pest filter → FAIL.
- [ ] **Step 3:** Implement `CatalogRules` (move the code, don't rewrite it); in the command, `is_dir($arg)` →
  read files, else catalog lookup.
- [ ] **Step 4:** `./vendor/bin/pest modules/Templates/tests` → PASS (all 46 still clean).
- [ ] **Step 5:** Commit `Templates: catalog rules shared by the tests and templates:render, which takes a directory`.

### Task 4: `templates:sync` and the synced-or-baked catalog

**Files:**
- Create: `control-plane/modules/Templates/src/Application/Catalog/CatalogSync.php`
- Create: `control-plane/modules/Templates/src/Application/Catalog/CatalogLocation.php`
- Create: `control-plane/modules/Templates/src/Application/Console/SyncTemplatesCommand.php`
- Modify: `config/templates.php` (`remote_url`, `synced_path`, `max_archive_bytes`)
- Modify: `TemplatesServiceProvider.php` (catalog binding, command, hourly schedule like `ConfigServiceProvider:114`)
- Modify: `FilesystemCatalog.php` (path per call, memo keyed by path)
- Test: `control-plane/modules/Templates/tests/Feature/CatalogSyncTest.php`

**Interfaces:**
- Config: `'remote_url' => rtrim((string) env('FALAK_TEMPLATES_URL', 'https://github.com/falak-sh/templates/releases/latest/download'), '/')`,
  `'synced_path' => storage_path('app/templates')`, `'max_archive_bytes' => 20 * 1024 * 1024`.
- `CatalogLocation::current(): array{path: string, version: ?string, source: 'remote'|'built-in', synced_at: ?string}`
  — reads `{synced_path}/current.json` (`{version, url, synced_at}`); uses it only when its `url` equals `remote_url`
  and its version is `version_compare`-newer than `{catalog_path}/VERSION` (missing VERSION = older than anything);
  else `catalog_path`.
- `CatalogSync::sync(): string` — returns `'disabled' | 'unchanged' | 'updated:<version>'`, throws
  `RuntimeException` on a bad download (command prints it, exits 1, logs a warning). Steps: remote off → delete
  `synced_path` contents, `'disabled'`. `current.json` url ≠ `remote_url` → delete synced copies first. GET
  `{remote_url}/catalog.json` (Laravel `Http`, timeout `templates.fetch.timeout`, redirects allowed — GitHub
  redirects release downloads); same version as current → `'unchanged'`. GET the tarball (reject > `max_archive_bytes`),
  compare `hash('sha256')`, extract with `PharData` into `{synced_path}/{version}.tmp` rejecting entries that are links,
  absolute, or contain `..`; require ≥ 1 `*/template.yaml`; rename to `{version}`; write `current.json` atomically
  (temp file + rename); delete versions other than the new and previous one.
- `FilesystemCatalog`: constructor takes `Closure(): string $path` instead of `string`; `load()` memoizes per path,
  so a switched version shows up in a long-lived FrankenPHP worker.
- Schedule: hourly only (no extra run at boot — the baked catalog covers the first hour; planner's call).
- Shared page prop for Task 5: `CatalogLocation::current()` minus `path`.

- [ ] **Step 1: Failing tests** (use `Http::fake` with a tarball built in the test from the fixture template):
  `it('downloads a new catalog and serves it')`, `it('keeps the last good catalog on a bad checksum / 404 / HTML body
  / oversize / path traversal / symlink entry')` (dataset), `it('prefers a newer baked catalog')`,
  `it('drops the synced copy when FALAK_TEMPLATES_URL changes or is cleared')`, `it('reports unchanged')`,
  `it('picks up a sync without rebuilding the catalog')` (same `Catalog` instance, `all()` before and after sync differ).
- [ ] **Step 2:** `./vendor/bin/pest modules/Templates/tests/Feature/CatalogSyncTest.php` → FAIL.
- [ ] **Step 3:** Implement as specified.
- [ ] **Step 4:** Test file → PASS; whole suite (`./vendor/bin/pest --parallel`) → PASS.
- [ ] **Step 5:** Commit `Templates: hourly catalog sync from falak-sh/templates releases (FALAK_TEMPLATES_URL)`.

### Task 5: Settings line + slug collisions

**Files:**
- Modify: `control-plane/modules/Templates/src/Http/Controllers/CustomTemplateController.php` (Settings page props)
- Modify: `control-plane/modules/Templates/resources/js/pages/Settings.tsx`
- Modify: `control-plane/modules/Templates/src/Application/Actions/SaveCustomTemplate.php:101`
- Test: `tests/Feature/CustomTemplatesTest.php`

**Interfaces:**
- Consumes: `CatalogLocation::current()`.
- Produces: Inertia prop `catalog: {version: ?string, source: 'remote'|'built-in', synced_at: ?string}`.

- [ ] **Step 1: Failing tests:** Settings page has `catalog.source === 'built-in'` on a fresh install; a custom template
  `hello` saved, then the catalog gains `hello` (fixture) → updating the custom one (with its `id`) succeeds, and
  `POST .../templates/hello/deploy` with `source=custom` deploys the custom one. Creating a *new* custom `hello` still
  fails with today's message.
- [ ] **Step 2:** → FAIL.
- [ ] **Step 3:** Settings shows one muted line under the heading: `Catalog {version} · updated {relative synced_at}`
  or `Built-in catalog`. `SaveCustomTemplate` rejects a catalog slug only when the organization has no custom template
  with that slug yet.
- [ ] **Step 4:** pest + `bun run types` → PASS.
- [ ] **Step 5:** Commit `Templates: show the catalog version; catalog slugs no longer lock existing custom templates`.

### Task 6: Docs + PR A

**Files:** `docs/COMPOSE_TEMPLATES.md` (§2: catalog lives in falak-sh/templates, `min_falak`, icon keys),
`docs/INSTALL.md` (`FALAK_TEMPLATES_URL`, next to "Canvas icons"), the spec + this plan.

- [ ] **Step 1:** Write the docs. `./vendor/bin/pint --test`, `bun run format:check` clean.
- [ ] **Step 2:** Commit, verify signatures, gitleaks, push branch `feat/templates-remote-catalog`, open PR A.
  Release v1.2.0 after the owner merges (same flow as v1.1.0).

### Task 7: Create `falak-sh/templates` (owner approval first)

- [ ] **Step 1:** Ask the owner to confirm creating the public repo.
- [ ] **Step 2:** `git subtree split --prefix=templates -b templates-split` in the main repo; new repo with that
  history at its root; add `README.md` (what it is, how to add a template, `./test`), `CONTRIBUTING.md` (format link to
  docs/COMPOSE_TEMPLATES.md §2, the rules from `CatalogRules`, amd64+arm64, pinned tags), `LICENSE` (same as main).
- [ ] **Step 3:** Push; ask the owner to turn on branch protection (required check `test`, one review).

### Task 8: `./test`

**Files:** `test` (bash, executable) in falak-sh/templates.

**Interface:** `./test <slug>... [--falak <version>] [--static] [--keep]`; exit 0 only if every slug passes.
`--static` = steps 1–2 only (no boot); `--keep` = leave the stack up for poking.

Per slug:
1. `docker run --rm -v "$PWD/<slug>:/t:ro" -v "$tmp:/out" ghcr.io/falak-sh/falak-control-plane:${version}
   php artisan templates:render /t --env-file=/out/.env > "$tmp/compose.yaml"` (default version: latest GitHub release
   of falak-sh/falak; `--falak` overrides). Non-zero → print stderr, fail.
2. Image check: `docker compose -f "$tmp/compose.yaml" --env-file "$tmp/.env" config --images` → each must not end in
   `:latest`/have no tag, and `docker buildx imagetools inspect` must list `linux/amd64` and `linux/arm64`.
3. `docker compose -p "tpl-<slug>" … up -d --wait --wait-timeout ${TIMEOUT:-900}`.
4. For each public service in `template.yaml` (read with `yq` if present, else from the rendered file's labels — pick
   one in the script): `docker run --rm --network tpl-<slug>_default curlimages/curl:8.11.1 -s -o /dev/null -w '%{http_code}'
   http://<service>:<port><health_check_path or />` → must be < 500. Check the rendered compose's network name first;
   if Falak's render references an external environment network, the script creates it before `up`.
5. Always `down -v` in a trap unless `--keep`. Print `PASS <slug>` / `FAIL <slug>: <reason>`.

- [ ] **Step 1:** Write the script; `shellcheck test` clean.
- [ ] **Step 2:** `./test uptime-kuma` and `./test umami` locally → PASS; break a healthcheck in a copy → FAIL with a
  readable reason.
- [ ] **Step 3:** `./test --static $(ls -d */ | tr -d /)` → all 46 PASS (fix or report any amd64-only images — that
  is the audit from the spec).
- [ ] **Step 4:** Commit.

### Task 9: Workflows

**Files:** `.github/workflows/test.yml`, `.github/workflows/release.yml` in falak-sh/templates.

- [ ] **Step 1: `test.yml`** (pull_request): changed slugs = top-level dirs touched in the diff; job `test` runs
  `./test <changed>` against latest Falak, then again with `--falak <min_falak>` for slugs that set it (skip when equal).
  No changed template → `./test --static` over all.
- [ ] **Step 2: `release.yml`** (push to main): `./test --static` over all (fail = no release); version
  `$(date -u +%Y.%m.%d).${GITHUB_RUN_NUMBER}`; `echo $version > VERSION`; `tar czf catalog.tar.gz VERSION */`;
  `catalog.json` with version, `sha256sum` and UTC time; `gh release create "v$version" catalog.tar.gz catalog.json
  --latest`.
- [ ] **Step 3:** Push; open a throwaway PR touching one template → `test` green; merge → a release with both assets;
  `curl -sL https://github.com/falak-sh/templates/releases/latest/download/catalog.json` shows it.
- [ ] **Step 4:** On a local panel (v1.2.0 code): `php artisan templates:sync` → `updated:<version>`; Settings shows it.

### Task 10: PR B — main repo drops `templates/`

**Files:**
- Delete: `templates/`
- Modify: `control-plane/Dockerfile` — `ARG TEMPLATES_VERSION=<first release>` + `ADD --checksum=sha256:<sha>
  https://github.com/falak-sh/templates/releases/download/v${TEMPLATES_VERSION}/catalog.tar.gz /tmp/catalog.tar.gz`,
  extract into `/opt/falak/templates` (replaces `COPY templates/`); remove `!templates/` from
  `Dockerfile.dockerignore`.
- Modify: `.github/workflows/images.yml` (drop `templates/**` filters), `.github/workflows/control-plane.yml` (new
  step after Tests: download the latest catalog and the Dockerfile's pinned one, run `php artisan templates:render
  <dir> > /dev/null` for every directory, fail on any).
- Modify: `TemplateCatalogTest.php` → delete (its job is now the compatibility step + templates repo); move any test
  still needing real templates to `templates_fixture_catalog`. Local dev keeps `catalog_path` =
  `base_path('../templates')` (now absent = empty built-in catalog) and gets templates with `php artisan
  templates:sync`; say so in the README dev section.
- Check: `sim/e2e-deploy.sh` stage `templates` still finds `uptime-kuma` and `umami` (the sim image bakes the pinned
  catalog).

- [ ] **Step 1:** Make the changes; `./vendor/bin/pest --parallel` → PASS; `docker build` the control-plane image
  locally and `docker run … php artisan tinker --execute 'echo count(app(\Falak\Templates\Application\Catalog\Catalog::class)->all());'` → 46.
- [ ] **Step 2:** Commit, signatures, gitleaks, push, PR B. After merge it ships with the next Falak release.
