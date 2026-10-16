# Tintype capture image (Chromium). Every published tag is immutable: the
# digest is recorded on each build and baselines are tied to it.
# Base pinned by digest — bump deliberately, then rebaseline.
FROM mcr.microsoft.com/playwright:v1.64.1-noble@sha256:7852dc5a7d72f5fe458a597d690f6c42fbc8e9e26ddfa95f8e6ef552558d9bbf

ARG UBUNTU_SNAPSHOT=20261001T000000Z
ENV TZ=UTC \
    LANG=en_US.UTF-8 \
    LC_ALL=en_US.UTF-8 \
    NODE_ENV=production \
    TINTYPE_CAPTURE_IMAGE=chromium

# Fonts come from a dated Ubuntu snapshot so rebuilds are reproducible.
RUN apt-get update --snapshot "${UBUNTU_SNAPSHOT}" \
 && apt-get install -y --no-install-recommends --snapshot "${UBUNTU_SNAPSHOT}" \
      fontconfig locales tzdata \
      fonts-noto-core fonts-noto-cjk fonts-noto-color-emoji \
      fonts-liberation fonts-dejavu-core \
 && locale-gen en_US.UTF-8 \
 && rm -rf /var/lib/apt/lists/*
COPY packages/capture/docker/fonts.conf /etc/fonts/local.conf
RUN fc-cache -f

WORKDIR /app
RUN npm install -g pnpm@12.14.0
COPY package.json pnpm-workspace.yaml pnpm-lock.yaml tsconfig.base.json ./
COPY packages/diff/package.json packages/diff/
COPY packages/capture/package.json packages/capture/
RUN pnpm install --filter "@tintype/capture..." --frozen-lockfile --prod=false
COPY packages/diff packages/diff
COPY packages/capture packages/capture
RUN pnpm --filter @tintype/diff build && pnpm --filter @tintype/capture build

USER pwuser
ENTRYPOINT ["node", "/app/packages/capture/dist/cli.js"]
CMD ["--help"]
