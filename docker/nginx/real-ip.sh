#!/bin/sh
# Recovering the client address from behind the host's reverse proxy.
#
# Run by the nginx image's entrypoint (from /docker-entrypoint.d/) before nginx
# starts, under docker-compose.prod.yml only. Caddy on the host forwards to the
# port nginx publishes on 127.0.0.1, and every such connection reaches nginx
# from the Docker network's gateway, which is this container's default route.
# So that one address is the proxy, and nothing needs configuring: the
# gateway is read here at start, whatever subnet Docker gave the network.
#
# Believing it is safe because the port is bound to loopback: only a process
# on the host can arrive through it. NGINX_TRUSTED_PROXY overrides the
# address, for a proxy that reaches nginx some other way. Never set it to the
# whole subnet: every container on the network would be believed too.
#
# With this in place REMOTE_ADDR is the resolved client, so the application's
# own TRUSTED_PROXIES should stay empty: resolving the chain twice only adds a
# second place for it to go wrong.
set -eu

proxy="${NGINX_TRUSTED_PROXY:-$(ip route | awk '/^default/ { print $3; exit }')}"

if [ -z "$proxy" ]; then
    echo "real-ip: no default route and no NGINX_TRUSTED_PROXY; refusing to start" >&2
    exit 1
fi

cat > /etc/nginx/conf.d/real-ip.conf <<CONF
set_real_ip_from $proxy;
real_ip_header X-Forwarded-For;
# Walk the chain from the right, skipping hops in the trusted range, and stop
# at the first address that is not one of ours. Without this, a client that
# prepends its own hop is handed back its own forgery.
real_ip_recursive on;
CONF

echo "real-ip: believing X-Forwarded-For from $proxy" >&2
