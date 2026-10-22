# Falak function runtime: Go (net/http handlers). See README.md for the falak-fn-install / falak-fn-serve /
# falak-fn-run convention. Build context: runtimes/functions (this file: go/Dockerfile).
FROM golang:1.27.3-alpine AS installer

WORKDIR /src
COPY go/install/ .
RUN CGO_ENABLED=0 go test ./... && CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /falak-fn-install .

FROM golang:1.27.3-alpine

# git for modules that are not on the module proxy.
RUN apk add --no-cache git ca-certificates

COPY --from=installer /falak-fn-install /usr/local/bin/falak-fn-install
COPY go/runtime/falak_runtime.go /opt/falak-fn/go/falak_runtime.go
COPY --chmod=0755 go/bin/falak-fn-serve go/bin/falak-fn-run /usr/local/bin/

# The root filesystem is read-only at run time. The install writes only to /app (go.mod, go.sum, .falak/fn), /cache
# (modules and the build cache, shared by every Go function on the server) and /tmp. The binary is static, so
# serving needs neither the toolchain nor /cache.
ENV CGO_ENABLED=0 \
    GOFLAGS=-mod=mod \
    GOTOOLCHAIN=local \
    GOTELEMETRY=off \
    GOPATH=/tmp/go \
    GOMODCACHE=/cache/go/mod \
    GOCACHE=/cache/go/build \
    HOME=/tmp \
    TMPDIR=/tmp \
    PORT=8080 \
    FALAK_ENTRYPOINT=main.go

WORKDIR /app
USER 65534:65534
EXPOSE 8080
CMD ["falak-fn-serve"]
