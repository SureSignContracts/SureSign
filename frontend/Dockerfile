FROM node:20-alpine AS deps
WORKDIR /app
COPY package*.json ./
RUN npm install

FROM node:20-alpine AS builder
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
ARG NEXT_PUBLIC_API_URL
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL
# Demo-only — unset (falsy) for every production build, since no build arg
# is passed in docker-compose.prod.yml. Only docker-compose.demo.yml passes
# these, baking the small identifying banner (src/components/shared/
# DemoBanner.tsx) into that build alone.
ARG NEXT_PUBLIC_DEMO_MODE
ENV NEXT_PUBLIC_DEMO_MODE=$NEXT_PUBLIC_DEMO_MODE
ARG NEXT_PUBLIC_DEMO_VERSION
ENV NEXT_PUBLIC_DEMO_VERSION=$NEXT_PUBLIC_DEMO_VERSION
# Organisation URL Branding — the registrable root domain branded
# subdomains resolve under (e.g. "suresigncontracts.app"). Build-time only:
# read directly via process.env in CustomUrlSection.tsx/
# OrganisationUrlBrandingSection.tsx, so it must be inlined here, not just
# set as a runtime environment variable.
ARG NEXT_PUBLIC_ORGANISATION_BRANDED_ROOT_DOMAIN
ENV NEXT_PUBLIC_ORGANISATION_BRANDED_ROOT_DOMAIN=$NEXT_PUBLIC_ORGANISATION_BRANDED_ROOT_DOMAIN
# Organisation URL Branding, Phase 5 (Stage 2B) — the fixed platform host
# (full origin, e.g. "https://app.suresigncontracts.app"), used by
# lib/hostContext.ts to distinguish "this IS the platform app" from an
# organisation/custom-domain host. Reuses the same FRONTEND_URL value
# already defined for the backend in docker-compose.prod.yml — no new
# Dokploy env var needed.
ARG NEXT_PUBLIC_APP_HOST
ENV NEXT_PUBLIC_APP_HOST=$NEXT_PUBLIC_APP_HOST
RUN npm run build

FROM node:20-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production

# Static-only OCI labels on the final runtime stage — see backend/Dockerfile
# for why revision/created aren't included here.
LABEL org.opencontainers.image.title="SureSign Frontend" \
      org.opencontainers.image.description="SureSign Next.js production server"

# --chown at copy time (not a runtime/startup chown) — the `node` user
# (uid 1000, already built into node:20-alpine) needs write access under
# .next/ for Next's on-disk fetch cache (this app uses `fetch(..., { next:
# { revalidate: ... } })` in layout.tsx — no next/image usage found, so no
# separate image-optimisation cache directory is needed here).
COPY --from=builder --chown=node:node /app/.next/standalone ./
COPY --from=builder --chown=node:node /app/.next/static ./.next/static
COPY --from=builder --chown=node:node /app/public ./public

EXPOSE 3000
USER node
CMD ["node", "server.js"]
