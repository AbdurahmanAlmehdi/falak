# syntax=docker/dockerfile:1.7
# Falak terminal relay for the local simulation (production bakes it into the control-plane image, role `relay`).
#   build context: ../agent
ARG GO_IMAGE=golang:1.25-trixie

FROM ${GO_IMAGE} AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download
COPY . ./
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    CGO_ENABLED=0 go build -trimpath -o /out/falak-relay ./cmd/falak-relay

FROM debian:trixie-slim
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates && rm -rf /var/lib/apt/lists/*
COPY --from=build /out/falak-relay /usr/local/bin/falak-relay
USER nobody
EXPOSE 8080
ENTRYPOINT ["falak-relay"]
