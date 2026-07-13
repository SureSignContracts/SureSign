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
