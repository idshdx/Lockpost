FROM --platform=linux/amd64 nginx:1.18.0-alpine

ARG APP_ENV=dev

RUN apk add --no-cache curl vim

COPY ./nginx.conf /etc/nginx/nginx.conf
COPY default.conf /etc/nginx/conf.d/default.conf
COPY upstream-${APP_ENV}.conf /etc/nginx/conf.d/upstream.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]