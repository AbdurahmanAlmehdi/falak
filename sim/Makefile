# Falak local end-to-end simulation.
#   make up [METRICS=mimir] [SIM_AGENT_ARCH=amd64]
#   make e2e
#   make enroll TOKEN=<install-token> [SERVERS="srv-app-1 srv-db-1"]
#   make logs [S=control-plane]
#   make down | make reset (keeps the download/package caches) | make reset-all (also wipes the caches)
#   make clean-cache | make cache-stats

METRICS ?= victoriametrics
COMPOSE := docker compose -p falak-sim --env-file sim.env --env-file .data/secrets.env
ALL_PROFILES := --profile victoriametrics --profile mimir
export SIM_METRICS_PROFILE := $(METRICS)
ifdef SIM_AGENT_ARCH
export SIM_AGENT_ARCH
endif

.PHONY: templates up down logs reset reset-all clean-cache cache-volumes cache-stats enroll e2e e2e-deploy ps build shell ca token enroll-all

# Persistent caches (apt, runtime downloads + PPA, Docker Hub, GHCR, builder). External volumes, so
# `make reset` (down -v) keeps them; `make clean-cache` / `make reset-all` remove them.
CACHE_VOLUMES := falak-sim-cache-apt falak-sim-cache-downloads falak-sim-cache-hub falak-sim-cache-ghcr falak-sim-cache-builder
# falak-builder's buildx builder "falak" (docker-container driver, created from inside the builder container, so
# the host CLI does not know it by name) and its BuildKit state volume, on the host daemon.
BUILDX_CONTAINER := buildx_buildkit_falak0
BUILDX_STATE := buildx_buildkit_falak0_state

# Sim-only secrets, generated once per sim (removed by `make reset`).
.data/secrets.env:
	@mkdir -p .data
	@{ echo "APP_KEY=base64:$$(openssl rand -base64 32)"; \
	   echo "REVERB_APP_ID=falak-sim"; \
	   echo "REVERB_APP_KEY=$$(openssl rand -hex 10)"; \
	   echo "REVERB_APP_SECRET=$$(openssl rand -hex 10)"; \
	   echo "FALAK_LOCAL_BUILDER_TOKEN=kbt_$$(openssl rand -hex 24)"; } > $@
	@chmod 600 $@

# The pinned template catalog (sim-prefetch reads ../templates; the image bakes the same release).
templates:
	@../tools/fetch-templates.sh

cache-volumes:
	@for v in $(CACHE_VOLUMES); do docker volume inspect $$v >/dev/null 2>&1 || docker volume create $$v >/dev/null; done

build: .data/secrets.env templates
	$(COMPOSE) --profile $(METRICS) build

up: .data/secrets.env cache-volumes templates
	@t0=$$(date +%s); \
	$(COMPOSE) --profile $(METRICS) up -d --build --wait --wait-timeout 420 || exit 1; \
	echo "make up: healthy in $$(( $$(date +%s) - t0 ))s"
	@$(COMPOSE) --profile $(METRICS) ps --format 'table {{.Service}}\t{{.Status}}'
	@echo
	@echo "control plane: https://localhost:$$(grep ^SIM_EDGE_HTTPS_PORT sim.env | cut -d= -f2) (edge CA: make ca -> .data/edge-root.crt)"
	@echo "grafana:       http://localhost:$$(grep ^FALAK_GRAFANA_PORT sim.env | cut -d= -f2) (admin/admin)"

ps:
	$(COMPOSE) $(ALL_PROFILES) ps

down:
	$(COMPOSE) $(ALL_PROFILES) down --remove-orphans

reset:
	$(COMPOSE) $(ALL_PROFILES) down -v --remove-orphans
	rm -rf .data

# Stops the sim (volumes in use cannot be removed) and wipes every cache: the next `make up` + E2E run is cold.
clean-cache: .data/secrets.env
	$(COMPOSE) $(ALL_PROFILES) down --remove-orphans
	$(drop-caches)

# `make reset` + `make clean-cache`: a completely cold sim.
reset-all: .data/secrets.env
	$(COMPOSE) $(ALL_PROFILES) down -v --remove-orphans
	$(drop-caches)
	rm -rf .data

define drop-caches
	docker rm -f $(BUILDX_CONTAINER) >/dev/null 2>&1 || true
	docker volume rm -f $(CACHE_VOLUMES) $(BUILDX_STATE) >/dev/null
endef

cache-stats:
	@docker system df -v | grep -E '^(VOLUME NAME|falak-sim-cache-|$(BUILDX_STATE))' || true

logs:
	$(COMPOSE) $(ALL_PROFILES) logs -f --tail=200 $(S)

ca:
	@mkdir -p .data
	$(COMPOSE) cp edge:/falak/edge-pki/root.crt .data/edge-root.crt
	@echo "curl --cacert sim/.data/edge-root.crt https://localhost:$$(grep ^SIM_EDGE_HTTPS_PORT sim.env | cut -d= -f2)/up"

shell:
	$(COMPOSE) exec $(or $(S),srv-app-1) bash

enroll:
	@test -n "$(TOKEN)" || { echo "usage: make enroll TOKEN=<install-token> [SERVERS=...]"; exit 2; }
	./enroll.sh "$(TOKEN)" $(SERVERS)

# Dev shortcut until the Servers UI exists: mint a token via Fleet's Enrollment contract.
token:
	@./mint-token.sh

# Mint one token per server and enroll all three.
enroll-all:
	@for s in srv-app-1 srv-app-2 srv-db-1; do ./enroll.sh "$$(./mint-token.sh)" $$s || exit 1; done

e2e:
	./e2e.sh

# Full product E2E: falak:admin -> REST API -> real provisioning -> build -> multi-server deploy,
# release, rollback, auto-rollback, Bun site, APM/Insights/Loki checks. Run after `make up`.
e2e-deploy:
	./e2e-deploy.sh
