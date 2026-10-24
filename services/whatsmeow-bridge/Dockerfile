# Bridge do AtendeFlow sobre o whatsmeow.
#
# O whatsmeow exige Go 1.26+ (go.mod do modulo: go 1.26.0).
#
# Build:
#   docker build -t atendeflow/whatsmeow-bridge ./services/whatsmeow-bridge
# Run:
#   docker run -d --name wa-bridge -p 8090:8090 \
#     -v atendeflow-wa-bridge:/data \
#     -e BRIDGE_TOKEN=seu-token \
#     atendeflow/whatsmeow-bridge

FROM golang:1.26-alpine AS builder

WORKDIR /src

# Dependencias primeiro (cache do Docker).
COPY go.mod ./
RUN go mod download

COPY . .

# go.sum e gerado no build (a versao do whatsmeow esta fixada no go.mod).
RUN go mod tidy && CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/bridge .

FROM alpine:3.22

RUN apk add --no-cache ca-certificates tzdata ffmpeg && \
    adduser -D -u 10001 bridge

COPY --from=builder /out/bridge /usr/local/bin/bridge

RUN mkdir -p /data && chown -R bridge:bridge /data
USER bridge
WORKDIR /data

ENV BRIDGE_ADDR=":8090" \
    BRIDGE_DATA_DIR="/data"

EXPOSE 8090

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8090/health || exit 1

ENTRYPOINT ["/usr/local/bin/bridge"]
