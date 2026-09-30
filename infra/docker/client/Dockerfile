FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends \
    fp-compiler fp-units-fcl fp-units-net fp-units-misc lcl-gtk2 lcl-units lcl-utils \
    lazarus-ide-gtk2 make xvfb xauth imagemagick ca-certificates \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /game
COPY apps/client apps/client
COPY packages/protocol-pascal packages/protocol-pascal
RUN mkdir -p build/client/linux apps/client/lib \
    && lazbuild --widgetset=gtk2 apps/client/GrimHollow.lpi
CMD ["/game/build/client/linux/grim-hollow"]
