# Builds the public SureSign User Guide (docs/ + mkdocs.yml at repo root)
# into a static site, then serves it from a minimal Nginx image. No
# source-code bind mounts in production — matches backend/frontend's
# image-based-only convention (see docker-compose.prod.yml header comment).
#
# Build context must be the repo root (not docker/docs/), since mkdocs.yml
# and docs/ both live there — see the `build:` block in
# docker-compose.prod.yml.

FROM python:3.12-slim AS build

LABEL org.opencontainers.image.title="SureSign Docs Builder"

WORKDIR /site
RUN pip install --no-cache-dir mkdocs mkdocs-material

COPY mkdocs.yml ./mkdocs.yml
COPY docs/ ./docs/

RUN mkdocs build --strict

FROM nginx:1.31-alpine

LABEL org.opencontainers.image.title="SureSign Docs" \
      org.opencontainers.image.description="Public SureSign User Guide — static site"

COPY --from=build /site/site /usr/share/nginx/html
COPY docker/docs/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
