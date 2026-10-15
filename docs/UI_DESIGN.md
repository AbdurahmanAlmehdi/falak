# Falak UI — Railway-style redesign (binding spec)

Goal: replace the stock shadcn look with a Railway-grade product. Dark-first (plus a matching light theme and
"system"), canvas-centric, calm, fast, keyboard-friendly. Every page in every module moves into the model below.

---

## 1. UX principles (Railway)

1. **The project canvas is home.** Users think in *projects* and *services*, not menus. Infrastructure (servers)
   is visible but secondary.
2. **Context stays put.** Opening a service slides a panel over the canvas; the canvas never navigates away.
   Deep links (`/projects/{p}/{env}/service/{id}/{tab}`) restore exactly that view.
3. **Live by default.** Status dots, deploy progress, logs and metrics update in place (Reverb, polling fallback).
   Never ask the user to refresh.
4. **One primary action per surface** (e.g. *Deploy*, *Create*), everything else in `⋯` menus or ⌘K.
5. **Changes are explicit.** Config edits that need a deploy show a sticky bar: *"3 changes — Deploy to apply"*.
6. **Keyboard first.** ⌘K everywhere; `g p` projects, `g s` servers, `g o` observability; `Esc` closes panels;
   `[`/`]` previous/next tab inside a panel.
7. **Quiet density.** Small type, generous spacing, few borders, no heavy cards-in-cards. Color only for status.
8. **Empty states teach.** Every empty list explains what it is and offers the one action that fills it.
9. **Destructive actions** require typing the resource name; everything else is undoable or instant.

---

## 2. Design tokens

Defined once in `resources/css/app.css` as CSS variables, mapped into Tailwind 4 `@theme`. **No hard-coded colors
in components.**

### Color — "Aurora teal"
Cool blue-green-grey neutrals with a teal accent. Text pairs meet WCAG AA (≥ 4.5:1); the brand teal `#0f8b8d` is
deepened in light mode so white button text and links pass.

| Token | Dark (default) | Light | Use |
|---|---|---|---|
| `--bg` | `#0a0f12` | `#f5f9fa` | app background |
| `--bg-canvas` | `#0c1215` | `#f0f6f7` | canvas (dotted grid `--grid`, 1px dots every 20px) |
| `--grid` | `#1c272d` | `#d5e2e5` | canvas dots |
| `--surface-1` | `#10171b` | `#ffffff` | panels, cards |
| `--surface-2` | `#162026` | `#ecf3f4` | hover, inputs, nested (elevated) |
| `--surface-3` | `#1d2a31` | `#e4eef0` | active, selected |
| `--border` | `#22313a` | `#d5e2e5` | hairlines (1px) |
| `--border-strong` | `#304450` | `#bccdd2` | focused inputs, selected cards |
| `--text` | `#eef4f5` | `#13202a` | primary text |
| `--text-muted` | `#9fb0b5` | `#556670` | secondary |
| `--text-faint` | `#8597a0` | `#5b6c75` | tertiary, placeholders (AA up to `--surface-3`) |
| decorative grey | `#66767d` | `#8a9aa2` | `--faint-soft` fills only, never text |
| `--accent` | `#4fd1c5` | `#0b7577` | primary buttons, focus ring, links (teal) |
| `--accent-hover` | `#76e0d6` | `#0a6668` | |
| `--accent-soft` | `#10302f` | `#ddf0ef` | selected nav, soft badges |
| `--accent-on-soft` | `#76e0d6` | `#0a6668` | accent text on `--accent-soft` |
| `--text-on-accent` | `#0a0f12` | `#ffffff` | text on accent / success fills |
| `--text-on-danger` | `#ffffff` | `#ffffff` | text on `--danger-fill` |
| `--selection` | `rgba(79,209,197,.28)` | `rgba(15,139,141,.22)` | text selection, terminal selection |
| `--success` | `#5ccb5f` | `#1f7a35` | active/healthy — a true green, clearly apart from the teal |
| `--warning` | `#f59e0b` | `#a85207` | building/deploying/degraded (light: darker amber, AA as text) |
| `--danger` | `#ef4444` | `#dc2626` | failed/crashed/offline: text, dots, bars |
| `--danger-fill` | `#dc2626` | `#dc2626` | danger buttons, badges, counters (fills with `--text-on-danger` on them) |
| `--info` | `#60a5fa` | `#2563eb` | queued/informational — blue, so it never reads as the accent |

Each status has a `-soft` translucent fill of the same hue. Key contrasts: dark accent fill with `#0a0f12` text
10.3:1, accent link on `--surface-2` 8.9:1, on-soft 9.0:1; light accent fill with white text 5.5:1, accent link on
`--surface-2` 4.9:1, on-soft 5.7:1. White on `--danger-fill` 4.8:1 in both themes (white on the dark theme's brighter
`--danger` would be 3.8:1, so fills with text use `bg-danger-fill`; `--danger` stays for red text and markers). Light
`--warning` text 5.4:1 on white, 4.6:1 on `--surface-3`. Chart series (`--chart-1..5`): teal accent, orange, violet, blue, pink.

Brand core: `#0a0f12` + `#0f8b8d` / `#4fd1c5` + `#eef4f5`. The mark (`FalakMark`, `public/favicon.svg`) is a sphere
with two orbit bands, always in the accent teal next to the "Falak" wordmark in `--text`; at ≤ 20px the bands are
thicker. Favicons: `#0b7577` (light) / `#4fd1c5` (dark); `apple-touch-icon.png` is the dark mark on `#0a0f12`.

### Type
- UI: **Inter** (variable, `@fontsource-variable/inter`), code/logs/ids: **JetBrains Mono**
  (`@fontsource-variable/jetbrains-mono`).
- Scale: 11 (meta) · 12 (labels, table) · 13 (body, default) · 14 (panel titles) · 16 (page titles) · 20 (hero).
  Weights 400/500/600 only. Tabular numerals for metrics.

### Shape, space, motion
- Radius: 6 (inputs, buttons), 8 (cards), 12 (panels, dialogs), full (status dots, avatars).
- Spacing base 4px; page gutter 24px (16px on mobile).
- Elevation: panels `0 8px 32px rgba(0,0,0,.45)` (dark) / `0 8px 24px rgba(20,16,40,.08)` (light); floating canvas
  panels use `--elevation-float` (hairline ring + wide soft shadow). No other shadows.
- Motion (tokens `--ease-spring`, `--ease-exit`, `--duration-panel`, `--duration-panel-exit`): only `transform` and
  `opacity` animate (no layout animation), everything is instant under `prefers-reduced-motion`.

  | What | Motion |
  |---|---|
  | hover / press | 150ms ease-out (colors, borders) |
  | panel enter | `translateX(24px) scale(.985)` + opacity 0 → rest, 280ms `cubic-bezier(.22,1,.36,1)` (CSS animation, `backwards` fill → no flash on mount) |
  | panel exit | → `translateX(28px) scale(.985)` + opacity 0, 180ms `cubic-bezier(.4,0,1,1)` (Web Animations API, then unmount) |
  | stack push / pop | the layer below recedes `translateX(-22px·depth) scale(1-.028·depth)` + veil (`--panel-dim`), 280ms spring; pop reverses |
  | tabs | underline indicator slides (`translateX` + `scaleX`, 260ms spring); new content fades + rises 3px (180ms) |
  | cards | hover lifts 1px, drag lifts 2px + scale 1.01 (180ms spring) |
  | groups | collapse fades members out (180ms) then hides them; expand rises them in (240ms spring) |
  | canvas | opening a service pans the viewport (420ms) so its card isn't under the panel |
  | cards / dialogs appearing | `rise-in` 220ms spring |
- Focus: 2px `--accent` ring with 2px offset on every interactive element.

### Status language (one component: `<StatusDot>` + `<StatusBadge>`)
| State | Color | Pulse | Label |
|---|---|---|---|
| active / online / healthy / succeeded | success | – | Active / Online / Healthy / Success |
| building / deploying / provisioning / queued-running | warning | yes | Building / Deploying / Provisioning |
| queued / waiting | info | – | Queued |
| failed / crashed / offline / error | danger | – | Failed / Crashed / Offline |
| removed / inactive / skipped / cancelled | faint | – | Removed / Inactive / Cancelled |

---

## 3. Information architecture

```
Top bar:  [Falak ◆] [Org ▾] / [Project ▾] / [Environment ▾]      [⌘K Search]   [🔔] [Help] [Avatar ▾]
```
No permanent sidebar. Top-level destinations (also in ⌘K and the org menu):

| Route | Page | Replaces |
|---|---|---|
| `/` → `/projects` | **Projects** dashboard (§3.1) | `dashboard.tsx` |
| `/projects/{p}/{env}` | **Project canvas** (§4) | Sites/Index, Databases/Index (per project) |
| `/projects/{p}/{env}/service/{kind}/{id}/{tab?}` | canvas + **Service panel** (§5) | Sites/*, Deployments/*, Edge/*, Processes/*, Databases/Show, Insights per site |
| `/projects/{p}/settings` | Project settings (name, environments, members access, danger) | – |
| `/servers` | **Infrastructure**: server fleet table + map of which services run where | Servers/Index |
| `/servers/{id}/{tab?}` | **Server panel** page: Overview, Metrics, Processes, Firewall, Network, Terminal, SSH keys, Recipes, PHP, Settings | Servers/Show, Network/*, Terminal/*, Recipes/* (per server) |
| `/observability` | **Observability**: Overview · Issues · Traces · Logs · Heartbeats · Alerts | Insights/*, Telemetry/*, Alerting/* |
| `/observability/issues/{id}` | Issue detail | Insights/Issue |
| `/settings/{section}` | **Account & org settings**, left-nav inside the page: Profile, Password, 2FA, Appearance, API tokens · Org: General, Members, Teams, Audit log, Source control, Cloud providers, Storage (backups), Builders, Alert channels, Observability, Recipes library | Identity/*, SourceControl, Providers, Databases/Storage, Builds/Builders, Alerting/Channels+Rules, Telemetry/Settings, Recipes/Index |
| `/notifications` | Notification center (also a popover from 🔔) | Alerting/Notifications |
| auth pages | centered minimal card on `--bg` with subtle grid | Identity/auth/* |

Legacy URLs (`/sites/{id}`, `/servers`, …) **redirect** to the new locations so existing links, CLI `open`, alert
links and API `url` fields keep working.

### 3.1 Projects dashboard
- Left **workspace sidebar** (≥ 1024px): Projects · Templates · Infrastructure · Observability · Team · Settings ·
  Documentation (each shown only with its permission). The org switcher stays in the top bar; the canvas keeps no
  permanent sidebar.
- Header: title **Projects**, search field (`/` focuses it; filters by project, description and service names),
  primary **+ New**.
- Meta row: `N Projects | Sort by: Recent activity ⌄` (recent activity · name · date created) and a grid / list toggle
  (both remembered per browser).
- **Project card**: name, ☆ star (per user, starred projects are pinned first), `⋯` (open environment, settings); a
  **dotted-grid preview** with the icons of the services of the production environment (rounded tiles, up to 6 +
  "+N"; compose templates show their logo); footer `● production · 4/4 services online` with the dot coloured by health
  (online / partial / deploying / failing) or `No services`, and the last activity time.
- **List view**: compact rows with the same information.

---

## 4. Project canvas

- Full-bleed below the top bar; `@xyflow/react` with dotted background, pan (drag / space+drag), zoom
  (⌘ scroll, `+`/`-`, *fit* button), minimap off by default. Positions persist per environment (Projects module).
- **Backdrop** (`canvas-space.tsx`): a space image (`public/images/canvas/space.{avif,webp,jpg}`, its own faint dot
  grid replaces the flow's dots in dark) with twinkling stars, a breathing nebula glow, floating moons and a light
  parallax on pan / zoom (±26px, eased). Transform and opacity only, paused while the tab is hidden, static under
  reduced motion; light mode shows a pale ink version (CSS filters) under the regular dots.
- **Service card** (fixed 240×112 so frames can be sized before measuring): icon + name (+ runtime badges), the domain
  (sites) or engine · server (databases) underneath, and the live status at the bottom (`● Online`, `● Deploying 64%`,
  coloured by tone) with the first server chip (+N). Services with persistent storage get a **volume strip** docked
  under the card (30px): compose named volumes, a database engine's data directory (`postgresql-data · db-1`), a
  site's shared directories. Hover lifts the card; selected (panel open) = accent border + glow; part of a
  multi-selection = dashed accent outline.
- **Edges** (dashed, with an arrowhead at the referenced service) show references: a site whose variables reference
  another service (§5.3), and compose `depends_on` between compose services. Edges are derived, never drawn by hand;
  they route orthogonally around cards (they may cross group frames) and turn accent when they touch the open service.
- **Groups** (§4.3) frame services: compose sites are groups of their compose services; users group anything else.
- **Toolbar** (bottom-left, vertical): snap to grid · zoom in / out / fit · undo / redo (layout changes: moves, group
  moves, joins / leaves; ⌘Z / ⇧⌘Z) · overview map. Snap and overview are remembered per browser; the viewport is
  remembered per environment for the session.
- **Selection**: ⇧-drag a box or ⌘/Ctrl-click cards → a floating bar "N services selected · **Group** · ✕".
- Opening a service pans the canvas (smoothly, only when needed) so its card stays visible left of the panel.
  Clicking empty canvas closes the panels.
- **"+ Create"** (top-right of canvas, also `⌘K → Create`, also right-click canvas) opens the **Create picker**:
  - *Git repository* → pick connection → repo → branch → preset auto-detected → servers → **Deploy**.
  - *Docker image* (runtime docker) · *Empty service*
  - *Database* → engine (PostgreSQL / MySQL / MariaDB / Redis) → server → create.
  - *Template* (placeholder "Coming soon" — compose templates land in roadmap step 4).
  Creation happens **inline in the picker** (no separate page); the new card appears on the canvas and its panel
  opens on the Deployments tab with the first deploy streaming. The picker is a centered modal (command-palette
  style); every pick step (kind, repository, engine, template) is the same keyboard list (`PickList`): type to
  filter, ↑/↓ Home/End move a sliding highlight, ↵ picks, Esc / Back go back one level and close from the first.
- Environment switcher: `production`, `staging`, … + *New environment* (empty, or *duplicate from* an existing
  environment: copies service configs and variables, not servers/targets — user picks servers per service).
- Top-right: *Activity* toggle (right rail: recent deploys/events of this env), project settings, **+ Create**.

### 4.3 Groups
- A **group** is a translucent rounded frame (`--group-bg`, `--group-border`) with a 38px header: icon, name,
  `● 3/4 online`, `⋯`. The frame always wraps its members (bounding box + 20px padding + header), so it resizes as
  members move; it is draggable as a unit (members move with it).
- **Compose sites** (templates or compose files) render as a group of their compose services: one card per compose
  service with its own status (running / healthy → Online, restarting / exited / unhealthy → crashed, nothing reported
  → the site's state), its image, public URL and named volumes; `depends_on` edges between them. Clicking the header
  opens the site's panel, clicking a compose service opens its **Services** tab. Menu: Open service, Collapse / Expand,
  Tidy up layout. Compose groups cannot be nested in user groups.
- **User groups**: select cards → **Group** (starts renaming inline), or drop a card on a frame (the frame highlights);
  drag a card out of its frame to leave. Menu: Rename, Collapse / Expand, Ungroup (cards stay where they are). A
  group whose last card leaves or is deleted disappears.
- **Collapsed** groups shrink to a 280×96 tile with the members' icons; edges to members attach to the tile.
- Persistence (Projects owns layout, per environment): a group has an anchor (`x`, `y`); member positions are
  relative to it, so moving a group is one write. Compose service positions are stored on the compose site's canvas
  service (`layout.children`, relative to its `x`/`y`, default 2-column grid) with `layout.collapsed`.

## 5. Service panel

A **floating panel** over the canvas (§5.5): inset 12px from the canvas edges below the top bar, rounded 12px,
hairline border and `--elevation-float`; width `min(940px, 58vw)`, resizable from its left edge (remembered); the
canvas stays visible and interactive on the left. Below 1024px it becomes a full-width sheet under the top bar.
Header (28px padding): 40px icon tile · **name** (20px semibold, click to rename) · status badge (+ runtime tags) ·
primary action (**Deploy** for sites, **Connect** for databases) · `⋯` (Redeploy, Rollback…, Open site ↗, Copy id,
Delete) · ✕ (Esc). Databases show engine · server under the name. Underline tabs (14px, 28px apart) with the sliding
indicator; `[` / `]` switch tabs while the panel is on top.

### 5.1 Tabs — site
| Tab | Content (from module) |
|---|---|
| **Deployments** | **Meta row**: 🌐 public domain on the left; servers (📍) and `N Replicas` on the right, muted. **Featured cards**: the deployment running now (warning tint, `DEPLOYING` / `BUILDING`, footer "⟳ Deploying · migrate"), a failure newer than the live release (danger tint), and the live release (success tint + border, `ACTIVE`, footer "✓ Deployment successful"). Each: state pill, author avatar with a trigger badge, title = commit message, subtitle "12 minutes ago via git push · main@abc1234 · 1m 14s", **View logs** (secondary) and `⋮` (View logs, Cancel, Redeploy this commit, Rollback). The attached footer expands (`⌄`) to the per-server phase timeline. Below: queued and **history** as quieter cards (`QUEUED`, `REMOVED`, `FAILED`, `ROLLED BACK`). View logs / a card opens the stacked **deployment panel** (§5.5). (Deployments) |
| **Variables** | Table (key, masked value with reveal-on-click (audited), row actions) + *New variable* inline row + **Raw editor** toggle (dotenv, monospace, diff before save) + *Expose to deploy script* per key + **reference picker** `${{ service.KEY }}` (§5.3). Shows "changes apply on next deploy" bar. (Sites env) |
| **Metrics** | CPU/mem/requests/p95/errors charts (recharts, 1h/6h/24h/7d), per server. (Telemetry) |
| **Logs** | Live log stream (Loki), search, level filter, server filter, pause/follow, click a line → trace. (Telemetry) |
| **Observability** | Issues for this site, slow routes/jobs/queries, heartbeats. (Insights) |
| **Processes** | Web process, queue workers, Horizon, Octane, daemons, cron jobs — one list with status + inline edit. (Processes) |
| **Settings** | Long scrolling page with anchored sections + left mini-nav: **Source** (repo/branch, push-to-deploy, deploy hook URL) · **Build** (mode, runtime, versions, build env prefixes) · **Deploy** (strategy, script editor with macros, retention, health check) · **Networking** (domains + TLS, test domain toggle, redirects, security rules, headers) · **Servers** (targets, leader, add/remove) · **Laravel** (scheduler/Horizon/Octane/maintenance) · **Commands** (run artisan/shell with live output) · **Danger** (delete). (Sites, Edge, Deployments settings) |

### 5.2 Deployment panel (stacked, §5.5)
Stacked over the service panel. Header: service icon · **Storefront / 4d2947b1** (service / short deployment id) ·
status badge (`Active` for the live release, `Removed` for superseded ones, else the deployment status) · `⋯`
(Redeploy, Rollback to this release, Cancel deployment, Copy deployment id) · start time with time zone
(`2026-09-28 17:35 GMT+2`) · ✕. Tabs:
- **Details**: waiting notice / error callout, summary (author, message, trigger, commit, strategy, duration,
  number) and the **phase timeline** (Build → Fetch → Prepare → Migrate → Activate → Restart → Health) per server.
- **Build Logs** / **Deploy Logs** (default: build while building, else deploy): the log table (§6 `LogViewer`, flush):
  full-width "Filter and search logs" field with a `/` hint, download, pop-out (opens the same stack maximised in a
  new tab, `&focus=1`); columns **Time (GMT+2)** · **Data**, a 3px bar per line (blue output, amber warning, red +
  faint red row for stderr/errors), wrapped lines, sticky header, group rows per `server · phase`, settings ⚙ (wrap,
  timestamps, line numbers, copy), live tail with a floating ↓ / ↑ button.
- **Network Logs**: HTTP request logs from the edge. Falak's edge does not ship per-request access logs to Loki yet,
  so the tab says so plainly (with a link to the service's Logs tab) instead of showing anything made up.

### 5.3 Variable references
`${{ <service-name>.<KEY> }}` in a site's variables resolves at deploy time to the referenced service's variable
in the **same environment** (database services expose `DATABASE_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`, plus Redis `REDIS_URL`). Resolution is owned by Projects via a contract Sites calls
when rendering a release's `.env`; unresolved references fail the deploy with a clear error. References drive
canvas edges.

References in the Variables tab link to their service: clicking it stacks that service's panel (§5.5 peek).

### 5.4 Tabs — database service
**Overview** (engine, server, connection strings with copy + reveal, private-network address) · **Databases &
users** · **Backups** (schedules, history, download, restore) · **Metrics** · **Settings**. Redis / Valkey instances:
no Databases & users tab; Backups are RDB snapshots restored into an existing instance (instance picker).

### 5.5 Panel stack
Panels are layers of one **PanelStack** (`components/falak/panel-stack.tsx`): base service panel → optionally another
service stacked on it (a referenced service) → optionally a **detail layer** contributed by a module (e.g. the
deployment panel). Depth ≥ 2 is supported; only the top layer is interactive (the ones below are `inert`, veiled and
receded so their left edge peeks out; clicking a receded layer closes what covers it).
- **URL** (deep links, back/forward): `/projects/{p}/{env}/service/{kind}/{id}/{tab}` + `?peek={kind}:{id}&peek_tab=`
  + `?{param}={record}&{param}_tab=` for the detail layer (deployments: `?logs={deployment}&logs_tab=build|deploy|
  details|network`) + `&focus=1` (maximised). Legacy `…/deployments/{deployment}` opens the deployment layer.
  Navigation is client-side (no server round trip); every change is a history entry, and back/forward between states
  of the same canvas update the stack in place so layers animate out / in.
- **Keyboard**: Esc closes the top layer (a focused text field is blurred first; open menus, selects and dialogs keep
  their own Esc); ✕ does the same. Focus moves into a new layer and returns to what opened it; Tab cycles inside the
  top layer; `/` focuses the log filter of the top layer.
- **Screen readers**: each layer is a labelled non-modal `dialog` ("Storefront service panel", "Storefront
  deployment 4d2947b1"); receded layers are hidden from the accessibility tree.

---

## 6. Components (`resources/js/components/falak/`)

Build a small, owned component set (Radix primitives underneath are fine; **no stock shadcn styling**):
`AppShell`, `TopBar`, `OrgSwitcher`, `ProjectSwitcher`, `EnvironmentSwitcher`, `CommandPalette` (restyled,
grouped results, recent items, actions with shortcuts), `Panel` (slide-over with tabs + URL sync), `Tabs`,
`Button` (primary/secondary/ghost/danger, sizes sm/md), `IconButton`, `Input`, `Textarea`, `Select`, `Combobox`,
`Switch`, `Checkbox`, `Field` (label, hint, error), `Section` (settings section with title/description/aside),
`PanelStack` + `PanelHeader` (§5.5), `StatusPill` (uppercase state pill of deployment cards),
`DataTable` (sortable, sticky header, row actions, empty/loading states), `StatusDot`, `StatusBadge`, `Tag`,
`Avatar`, `Tooltip`, `Menu` (`⋯`), `Dialog`, `ConfirmDestructive` (type-to-confirm), `Toast`, `EmptyState`,
`Skeleton`, `CodeBlock` (copy), `LogViewer` (virtualized log table, `card` / `flush`), `MetricChart`, `KeyValue`,
`Stepper`/`PhaseTimeline`, `MenuCheckboxItem`,
`ChangesBar`, `CopyButton`, `RelativeTime` (live-updating), `ServiceIcon` (framework/engine logos),
`ServiceCard` (canvas), `EmptyCanvas`.

Rules: every list has loading skeleton + empty state; every async action shows pending state on the button and a
toast on completion/failure; every form validates inline (server 422 errors mapped to fields).

---

## 7. Backend additions required by the UX

1. **Projects module** (new): `projects`, `environments` (slug, is_production, forked_from), `project_services`
   (environment_id, kind `site|database`, ref_id, x, y). Every existing site/database is migrated into a
   per-org **"Default"** project, `production` environment. Contracts: `ProjectDirectory` (projectOf(kind,id),
   servicesIn(env), environment lookups), `VariableReferences` (resolve(envId, siteId, dotenv) → dotenv/errors).
   Events: `ProjectCreated`, `EnvironmentCreated`, `ServiceLinked`, `ServiceUnlinked`. Sites created elsewhere
   (API/CLI) are linked to the org's default project (listener on `SiteCreated`); the site/database create APIs
   accept optional `project_id` + `environment_id`.
2. **Sites** exposes a creation contract (`Sites\Contracts\SiteFactory::create/duplicate`) so Projects can create
   and fork services without reaching into Sites internals; release `.env` rendering calls
   `Projects\Contracts\VariableReferences`.
3. **Databases** exposes connection variables per database for references.
4. **Read models for the canvas**: one endpoint returns all services of an environment with live status (latest
   deployment, targets, domains, servers) to render the canvas in a single request.

---

## 8. Verification

- **Playwright** (`control-plane/tests/Browser`, run against the sim): logs in, visits **every route** in both
  themes, fails on console errors / failed requests / horizontal overflow at 1440px and 390px, and saves
  screenshots to `tests/Browser/screenshots/{theme}/{route}.png` for visual review.
- Existing Pest feature tests keep passing (update Inertia component names where pages moved).
- Lighthouse-style budgets: first canvas render < 1.5s on the sim with 20 services; route JS < 250 KB gzip.

---

## 9. Shared contracts between the UI foundation and the Projects backend (fixed; build against these)

**Inertia shared prop `falak`** (added by the Projects module to every authenticated page, via a
`Kernel`-level shared-props registry so app glue doesn't import modules):
```ts
type FalakShared = {
  projects: { id: string; name: string; icon: string | null; environments: { id: string; name: string; slug: string; is_production: boolean }[] }[];
  current: { project_id: string | null; environment_id: string | null }; // from the URL or last visited
};
```

**Canvas read model** — `GET /projects/{project}/{environment}/canvas` (JSON, session auth):
```ts
type CanvasService = {
  id: string;                     // project_services.id
  kind: 'site' | 'database';
  ref_id: string;                 // site id / database id
  name: string;
  icon: string;                   // 'laravel' | 'next' | 'bun' | 'postgresql' | ... (ServiceIcon key)
  position: { x: number; y: number };
  status: 'active' | 'deploying' | 'building' | 'queued' | 'failed' | 'crashed' | 'inactive' | 'provisioning';
  status_label: string;           // "Active · 2m ago", "Deploying 64%"
  url: string | null;             // primary https URL (sites)
  subtitle: string | null;        // "PostgreSQL 17 · db-1"
  servers: { id: string; name: string; leader: boolean; online: boolean }[];
  last_deployment: { id: string; status: string; commit: string | null; message: string | null; finished_at: string | null } | null;
};
// Added for groups (§4.3):
//   CanvasService.group_id: string | null        user group; position is then relative to the group anchor
//   CanvasService.volumes: { name: string; detail: string | null }[]
//   CanvasService.compose: { template: string | null; collapsed: boolean; services: ComposeChild[] } | null
type ComposeChild = { name: string; icon: string; image: string | null; status: CanvasStatus; status_label: string;
                      url: string | null; volumes: string[]; position: { x: number; y: number } };  // relative to the card
type CanvasGroup = { id: string; name: string; position: { x: number; y: number }; collapsed: boolean };
type Canvas = {
  services: CanvasService[];
  edges: { from: string; to: string; kind: 'reference' | 'depends_on'; problem?: string }[];  // ids: project_services.id or `{id}:{compose service}`; problem: an unresolvable database host reference (amber edge, "!" mark)
  groups: CanvasGroup[];
};
```
Layout writes (Projects, `projects.manage`):
- `PATCH /projects/{project}/{environment}/services/{service}/position {x, y, group_id?}` — `group_id` (or null)
  moves the card into / out of a group.
- `PATCH …/services/{service}/layout {children?: {name: {x, y}}, collapsed?}` — compose group layout.
- `POST …/groups {name?, service_ids}` · `PATCH …/groups/{group} {name?, x?, y?, collapsed?}` · `DELETE …/groups/{group}`
  (ungroup; cards keep their place).
- `PUT|DELETE /projects/{project}/favorite` — star / unstar for the signed-in user (any project viewer).

The Projects grid (`Projects/Index` props, `GET /projects` JSON) adds per project: `favorite`, `last_activity_at`,
`production {name, slug, services, online, health}`, and `services` = icons of the production environment.

Stacked detail layers are registered by the owning module with `registerServiceLayers({id, kinds, param, fromTab?,
permission?, label, component})` (`resources/js/lib/registry.ts`); the panel context gains `openLayer(id, record,
tab?)`, `openService(service, tab?)` and `layer` (what is open on top).

**Page names** (Inertia): `Projects/Index`, `Projects/Canvas` (props: project, environment, canvas, `panel?: {kind, id, tab}`),
`Projects/Settings`. Service panel tab content is loaded as **JSON from each owning module's endpoints**
(the panel is one page; tabs fetch lazily), so modules keep owning their data and routes.
