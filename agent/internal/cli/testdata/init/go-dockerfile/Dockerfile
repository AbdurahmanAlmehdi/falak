FROM golang:1.24 AS build
WORKDIR /src
COPY . .
RUN CGO_ENABLED=0 go build -o /out/inventory .

FROM gcr.io/distroless/static-debian12
COPY --from=build /out/inventory /inventory
EXPOSE 9000
ENTRYPOINT ["/inventory"]
