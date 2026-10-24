# Falak edge: Caddy terminating TLS for the panel (Let's Encrypt, or Caddy's internal CA with
# FALAK_TLS=internal), serving the agent mTLS API with the Fleet-issued certificate, proxying Reverb
# websockets, and (optionally) Grafana and the OTLP ingress. Build context: deploy/edge.
FROM caddy:2.11.4-builder-alpine AS build
RUN xcaddy build v2.11.4 --with github.com/mholt/caddy-l4@v0.1.2 --with github.com/mholt/caddy-ratelimit@v0.1.0

FROM caddy:2.11.4-alpine
ARG FALAK_VERSION=dev
LABEL org.opencontainers.image.title="falak-edge" \
      org.opencontainers.image.description="Falak edge (Caddy): ACME TLS, agent mTLS, Reverb websocket proxy" \
      org.opencontainers.image.version="${FALAK_VERSION}"
# caddy-l4: with FALAK_LOCAL_EDGE=1 the :443/:80 listener wrappers pass every host the panel does not
# serve to the local server's edge (PROXY protocol v2); jq collects the keep-list from `caddy adapt`.
# caddy-ratelimit: per-address limits on the sign-in, sign-up and enrollment endpoints (Caddyfile, falak_routes).
COPY --from=build /usr/bin/caddy /usr/bin/caddy
RUN apk add --no-cache openssl curl jq
COPY Caddyfile /etc/caddy/Caddyfile
COPY --chmod=0755 entrypoint.sh /usr/local/bin/falak-edge-entrypoint
RUN mkdir -p /etc/caddy/trust /etc/caddy/sites /etc/caddy/global
HEALTHCHECK --interval=10s --timeout=5s --start-period=20s --retries=6 \
  CMD curl -fsS -o /dev/null http://localhost:2019/config/ || exit 1
ENTRYPOINT ["falak-edge-entrypoint"]
