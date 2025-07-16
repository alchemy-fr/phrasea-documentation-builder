# syntax=docker.io/docker/dockerfile:1

FROM node:alpine3.21

WORKDIR /srv/workspace

RUN chown -R node:node /srv/workspace \
        && npm install -g pnpm@^9.3.0

RUN apk add --no-interactive jq curl bash nano

USER node
COPY --chown=node:node ./docker/entrypoint.sh /entrypoint.sh
COPY --chown=node:node ./getdoc.sh  ./
COPY --chown=node:node ./my-app  /srv/workspace/my-app

RUN chmod +x ./getdoc.sh && ./getdoc.sh

WORKDIR /srv/workspace/my-app

RUN pnpm install && pnpm build && chmod +x /entrypoint.sh

EXPOSE 3000

ENTRYPOINT exec pnpm run start
# ENTRYPOINT ["/entrypoint.sh"]
# CMD ["pnpm", "run", "start"]

