# falak-agent (servers), falak (CLI), falak-builder (build worker), falak-db (inside the database images,
# images/db), falak-restore (decrypts backups offline, docs/BACKUPS.md), falak-relay (terminal relay, in the
# control-plane image; docs/plans/CONSOLE.md). Zero CGO, static, stripped.
VERSION ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS := -s -w -buildid= -X github.com/OthmanHaba/falak/agent/internal/version.Version=$(VERSION)
GOFLAGS := -trimpath

AGENT_PLATFORMS   := linux-amd64 linux-arm64
CLI_PLATFORMS     := darwin-amd64 darwin-arm64 linux-amd64 linux-arm64
BUILDER_PLATFORMS := linux-amd64 linux-arm64
DBHELPER_PLATFORMS := linux-amd64 linux-arm64
RESTORE_PLATFORMS := darwin-amd64 darwin-arm64 linux-amd64 linux-arm64
RELAY_PLATFORMS   := linux-amd64 linux-arm64

BINARIES := $(foreach p,$(AGENT_PLATFORMS),bin/falak-agent-$(p)) \
            $(foreach p,$(CLI_PLATFORMS),bin/falak-$(p)) \
            $(foreach p,$(BUILDER_PLATFORMS),bin/falak-builder-$(p)) \
            $(foreach p,$(DBHELPER_PLATFORMS),bin/falak-db-$(p)) \
            $(foreach p,$(RESTORE_PLATFORMS),bin/falak-restore-$(p)) \
            $(foreach p,$(RELAY_PLATFORMS),bin/falak-relay-$(p))

.PHONY: build agent cli builder dbhelper restore relay test vet clean sizes

build: $(BINARIES)
	@$(MAKE) --no-print-directory sizes

agent: $(foreach p,$(AGENT_PLATFORMS),bin/falak-agent-$(p))
cli: $(foreach p,$(CLI_PLATFORMS),bin/falak-$(p))
builder: $(foreach p,$(BUILDER_PLATFORMS),bin/falak-builder-$(p))
dbhelper: $(foreach p,$(DBHELPER_PLATFORMS),bin/falak-db-$(p))
restore: $(foreach p,$(RESTORE_PLATFORMS),bin/falak-restore-$(p))
relay: $(foreach p,$(RELAY_PLATFORMS),bin/falak-relay-$(p))

# $(call gobuild,<cmd dir>,<os-arch>)
define gobuild
	@mkdir -p bin
	CGO_ENABLED=0 GOOS=$(word 1,$(subst -, ,$(2))) GOARCH=$(word 2,$(subst -, ,$(2))) \
		go build $(GOFLAGS) -ldflags '$(LDFLAGS)' -o $@ ./cmd/$(1)
endef

# Static pattern rules: falak-builder-*, falak-db-*, falak-restore-*, falak-relay-* and falak-agent-* must not fall
# into the falak-% CLI rule.
# <binary>.version: the version the control plane announces when it serves this build (agent upgrades).
$(foreach p,$(AGENT_PLATFORMS),bin/falak-agent-$(p)): bin/falak-agent-%: FORCE
	$(call gobuild,falak-agent,$*)
	@echo '$(VERSION)' > $@.version

$(foreach p,$(BUILDER_PLATFORMS),bin/falak-builder-$(p)): bin/falak-builder-%: FORCE
	$(call gobuild,falak-builder,$*)

$(foreach p,$(DBHELPER_PLATFORMS),bin/falak-db-$(p)): bin/falak-db-%: FORCE
	$(call gobuild,falak-db,$*)

$(foreach p,$(RESTORE_PLATFORMS),bin/falak-restore-$(p)): bin/falak-restore-%: FORCE
	$(call gobuild,falak-restore,$*)

$(foreach p,$(RELAY_PLATFORMS),bin/falak-relay-$(p)): bin/falak-relay-%: FORCE
	$(call gobuild,falak-relay,$*)

$(foreach p,$(CLI_PLATFORMS),bin/falak-$(p)): bin/falak-%: FORCE
	$(call gobuild,falak,$*)

sizes:
	@ls -l bin/ | awk 'NR>1 && $$9 !~ /\.version$$/ {printf "%-30s %6.2f MB\n", $$9, $$5/1048576}'

test:
	go test ./...

vet:
	go vet ./...

clean:
	rm -rf bin

FORCE:
