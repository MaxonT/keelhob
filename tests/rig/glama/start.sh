#!/usr/bin/env bash
# Start WordPress, force Keelhob's token to the one Glama passed in, then hand
# stdin/stdout to the bridge. Everything lives in this container; no outside
# site is touched.
set -euo pipefail
WP=/var/www/html
TOKEN="${KEELHOB_TOKEN:-}"
if [ -z "$TOKEN" ]; then
  echo "KEELHOB_TOKEN is not set" >&2
  exit 2
fi

# Keelhob stores only a sha256 of the token, so the token the harness supplies
# is written as that hash rather than minted here: set_token() would mint its
# own and the harness would be left holding one that authenticates against
# nothing.
#
# The write mirrors set_token() field for field, including clearing the legacy
# plaintext `token` and resetting `token_last_used`. There is no ::set() on
# Keelhob - the setter is update(), which merges a patch and re-encrypts the
# secret fields. Calling the wrong one fatals inside WordPress, and the only
# thing printed is "There has been a critical error on this website", which
# says nothing about which line did it.
echo "kh: writing the supplied token" >&2
wp --path="$WP" --allow-root eval '
  $plain = (string) getenv("KEELHOB_TOKEN");
  Keelhob::instance()->update([
      "token"           => "",
      "token_hash"      => hash("sha256", $plain),
      "token_last_used" => 0,
  ]);
  Keelhob_Auth::unlock();
  echo "ok";
' >&2

echo "kh: starting php -S on 127.0.0.1:8899" >&2
php -S 127.0.0.1:8899 -t "$WP" > /dev/null 2>&1 &

# Wait for it rather than sleeping a guess: a fixed sleep is either slow or
# flaky, and on a cold container it is usually both.
ready=0
for _ in $(seq 1 60); do
  if curl -fsS -o /dev/null "http://127.0.0.1:8899/"; then ready=1; break; fi
  sleep 0.5
done
if [ "$ready" != "1" ]; then
  echo "kh: WordPress never answered on 127.0.0.1:8899" >&2
  exit 1
fi
echo "kh: WordPress is up, handing over to the bridge" >&2

# ?rest_route= rather than the pretty /wp-json/ path. PHP's built-in server
# has no rewrite rules and WordPress ships with plain permalinks, so
# /wp-json/keelhob/v1/mcp never reaches the REST API: index.php renders the
# home page instead, and the harness sees a block-editor stylesheet where it
# expected JSON-RPC.
exec php /usr/local/lib/kh-glama/stdio-bridge.php \
  "http://127.0.0.1:8899/index.php?rest_route=/keelhob/v1/mcp" "$TOKEN"
