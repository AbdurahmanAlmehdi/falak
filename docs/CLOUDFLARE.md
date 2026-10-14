# Cloudflare integration

Falak can manage the DNS of your Cloudflare zones: it creates, updates and deletes the records of your services, names
new services under your own domain, and keeps everything working behind Cloudflare's proxy (orange cloud: DDoS
protection, edge cache, hidden server IP). Everything here works on Cloudflare's Free plan.

## Connect

1. **Settings → Integrations → Cloudflare → Create a token in Cloudflare.** The link opens Cloudflare's token form
   with the permissions filled in:

   | Permission | Why |
   |---|---|
   | Zone → DNS → Edit | create, update and delete records |
   | Zone → Zone → Read | find the zone and its account |
   | Zone → Zone Settings → Edit | check and fix SSL mode, minimum TLS, Always Use HTTPS |
   | Zone → Cache Purge → Purge | purge the cache after deploys |
   | Zone → Cache Rules → Edit | cache modes per domain |
   | Account → Cloudflare Tunnel → Edit | route servers through a Cloudflare Tunnel |
   | Zone → Analytics → Read | traffic analytics in the Networking tab |

   Under **Zone Resources** pick *Specific zone* and your domain.
2. Paste the token in Falak and click **Connect**. Falak verifies it with Cloudflare and stores it encrypted.
3. Click **Manage** next to the zone. New records are proxied (orange) by default; turn that off per zone, or per
   domain in the service's Networking tab.
4. Fix the zone settings Falak flags: **SSL/TLS mode Full (strict)**, **minimum TLS 1.2**, **Always Use HTTPS off**
   (Caddy already redirects to HTTPS, and Cloudflare's redirect would block Let's Encrypt).

## What Falak does in a managed zone

- **Records follow your domains.** Adding `shop.example.com` to a service creates one `A`/`AAAA` record per server it
  runs on (the load balancer of a load-balanced site), and for the `www` host when a www redirect is on. They change
  when the service moves servers and disappear when the domain or service is deleted. Every public service of a
  compose (template) site has domains of its own, with records, proxy and cache settings like any domain.
- **Only its own records.** Falak tags each record it creates (`falak:<id>` in the record's comment) and never changes
  anything else. If a name already has a record Falak did not create, the domain shows a **conflict**: delete that
  record in Cloudflare, then click **Sync**.
- **Generated names under your zone.** Settings → Domains → *Cloudflare: example.com* (or "Generate names here" on the
  zone): new services get `service.example.com`; copies in other environments get `service-staging.example.com`.
  One level below the zone, so Cloudflare's free certificate covers it.
- **Certificates.** Servers keep getting Let's Encrypt certificates over HTTP-01, which Cloudflare passes through even
  when proxied (TLS-ALPN-01 is turned off for these names, since it cannot pass the proxy). With Full (strict),
  Cloudflare checks that certificate.
- **Real visitor IPs.** Servers trust Cloudflare's IP ranges and read `CF-Connecting-IP`, so access logs, IP allow /
  deny lists and rate limits see the visitor, not Cloudflare.
- **Never managed:** the panel and the agent API hosts. Agents authenticate with mutual TLS, which Cloudflare's proxy
  would terminate, so `agents.<your domain>` must stay DNS only.

## Traffic analytics

A site's **Settings → Networking → Cloudflare traffic** block covers the site's proxied names in managed zones. It
uses Cloudflare's GraphQL Analytics API with the connection's token, and shows the last 24 hours:

- requests and visits;
- the share served from Cloudflare's cache (vs. the origin);
- bandwidth;
- threats the firewall stopped;
- requests per hour;
- top countries;
- response status classes;
- the whole zone's last 7 days as context.

Results are cached for 5 minutes; **Refresh** fetches them again (at most once a minute).

- **What the zone can read.** Falak asks Cloudflare first which datasets the zone can read and how far back. A
  dataset the plan doesn't include is left out with a note. Firewall events, for example, may need a paid plan.
- **Older tokens.** Tokens created before this feature lack **Zone → Analytics → Read**: the block then says so. To
  fix it, edit the token in Cloudflare → My Profile → API Tokens and add that permission.
- **Names left out.** Names that Cloudflare doesn't proxy (grey cloud) are not counted, and wildcard names aren't
  either.

## Cloudflare Tunnel (no open ports)

**Settings → Cloudflare → Servers → Route through a tunnel.** Falak creates a tunnel for the server in your Cloudflare
account, installs a pinned, checksum-verified `cloudflared` on it (`falak-cloudflared.service`, a dynamic user, the
token passed as a systemd credential) and keeps the tunnel's routes in line with the names the server serves.

- The server's names in your managed zones become a proxied `CNAME` to `<tunnel id>.cfargotunnel.com` — once
  `cloudflared` reports it is running, so a failed install never cuts traffic.
- Caddy stays in charge: the tunnel sends each name to Caddy on `https://localhost:443` (with the name as SNI), and
  only Let's Encrypt's `/.well-known/acme-challenge/` path to port 80, so certificates are still issued and renewed.
  Routing rules, headers and logs work as before, and logs keep the visitor's IP.
- Once the tunnel shows **healthy**, you can close ports 80 and 443 on the server's firewall: Falak's own traffic to
  the agent is outbound. Names outside your managed zones (sslip.io, other DNS providers) still need the public IP.
- **Back to public** removes `cloudflared`, deletes the tunnel and points the names at the server's IP again (open the
  ports first if you closed them).
- The token needs **Account → Cloudflare Tunnel → Edit** (add it to the same token).
- One tunnel per server: a site on several servers is routed through the tunnel of the first one (the leader).

## Cache, purge and protection

- **Cache mode per domain** (Networking tab → the domain's ⋯ menu): *Standard* (Cloudflare's default: static files),
  *Everything* (HTML too, one day at the edge — for static and SPA sites) or *Bypass*. Falak writes them as Cache Rules
  it tags `falak:cache:<domain>`; your own Cache Rules in the zone stay untouched.
- **Purge after every deploy.** A successful deploy or a rollback purges the site's names (domains, www hosts and a
  template's public domains), so visitors get the new release right away. *Purge Cloudflare cache* in the domain
  menu purges on demand.
- **Under Attack mode** (Settings → Cloudflare, per zone): every visitor gets a short browser check first. Turning it
  off restores the security level you had before.
- **Origin lock-down** (Settings → Cloudflare → Servers → Web ports):
  - *Cloudflare only*: ports 80 and 443 accept Cloudflare's IP ranges only, so nobody can bypass the proxy with the
    server's IP. Names that are not proxied (sslip.io, DNS only) stop working on that server.
  - *Closed (tunnel)*: no inbound web traffic at all; the server is reached through its Cloudflare Tunnel only. If the
    tunnel stops, the ports fall back to Cloudflare only; taking the server off the tunnel opens them again.
  - SSH stays open either way.

## Rate limits

Falak's edge on your servers is stock Caddy, which has no rate limiting, so rate limits are Cloudflare rules: they
only apply to names Cloudflare **proxies** (orange cloud).

- **Per domain** (Networking tab → the domain's ⋯ menu → *Rate limit…*): optional path prefix, a number of requests per
  window, then *Block* for a while, or *Managed challenge*. Below Enterprise a challenge has no duration: Cloudflare
  challenges each request over the limit, and a visitor who passes starts counting from zero again (Enterprise sets a
  duration for both). Counted per visitor IP (and Cloudflare data center).
- Falak writes them as rate limiting rules it tags `falak:ratelimit:<organization>:<domain>`; your own rules in the zone
  stay untouched (sent back as they are), and so do the rules of other organizations or other Falak installs sharing the
  zone. Switching a domain to DNS only takes its rule out of the zone (the setting is kept for when it is proxied
  again, and the rule comes back with the orange cloud); removing the domain removes its rule. Changes to one zone
  run one at a time.
- **What the plan allows** (read from the zone; Cloudflare's [rate limiting
  rules](https://developers.cloudflare.com/waf/rate-limiting-rules/)): Free: **one rule per zone**, a 10-second
  window, a 10-second block, and the path is the only request field it can match (no host), so it applies to every
  proxied name of the zone: the other domains of the zone show a *zone-wide rate limit* warning, and a rule without a
  path is refused when the panel itself is in the zone. Pro: 2 rules, host + path, windows up to 1 minute, blocks up
  to 1 hour. Business: 5 rules, up to 10 minutes / 1 day. Enterprise: Falak allows up to 100 rules, windows up to
  65,535 s and blocks up to 1 day; the actual rule count depends on the Enterprise contract (Cloudflare refuses what
  it doesn't allow).
- The token needs **Zone → Zone WAF → Edit** (Falak shows Cloudflare's error otherwise).

## Limits on the Free plan

- Requests through the proxy are limited to **100 MB** (large uploads to Nextcloud or Paperless need DNS only).
- The free certificate covers `example.com` and `*.example.com`, not deeper names like `a.b.example.com`.
- Only HTTP(S) goes through the proxy; other ports need DNS only.

## Stop

- **Stop managing** a zone: Falak stops changing it; the records it created stay, so services keep resolving.
- **Disconnect**: every zone of the connection is released the same way, and the token is deleted from Falak.

## Coming next

"Sign in with Cloudflare" instead of pasting a token.
