# Glama build harness

Two small scripts that let **[Glama](https://glama.ai/mcp/servers/calflint/keelhob)**
run its automated safety and quality checks against Keelhob.

Nothing here ships. `bin/build-zip.sh` archives a fixed path list —
`keelhob.php uninstall.php readme.txt includes skills assets` — so `tests/`
never reaches an installed site.

## Why this exists

Glama starts a container, runs one command, and talks **MCP over stdin and
stdout**. Keelhob does not fit that shape: it is a WordPress plugin, and its
MCP server is an HTTP endpoint *inside* a WordPress install. There is no
process to start and no stdio to talk to.

So the container carries its own throwaway WordPress — the same recipe as
`tests/rig/build-rig.sh`, on SQLite — and these two scripts join the ends:

| File | What it does |
|---|---|
| `start.sh` | Writes the supplied token into the install, starts PHP's built-in server, waits for it to answer, then hands stdin/stdout to the bridge. |
| `stdio-bridge.php` | Reads one JSON-RPC message per line, POSTs it to the endpoint, writes the reply back as one line. |

## The two things that are easy to get wrong

**The token is written as a hash, not minted.** Keelhob stores only
`sha256(token)`. Calling `Keelhob::set_token()` would mint a *new* token and
Glama would be left holding the wrong one, authenticating against nothing. So
`start.sh` writes the hash of the token Glama passed in, mirroring
`set_token()` field for field — clearing the legacy plaintext `token` and
resetting `token_last_used` as well.

**The setter is `update()`, not `set()`.** `Keelhob` has no `set()`; `update()`
takes a patch, merges it over the current settings and re-encrypts the secret
fields. The first build failed on exactly this, and the only thing WordPress
printed was *"There has been a critical error on this website"*, which names
neither the method nor the line.

**Nothing may print to stdout except JSON-RPC.** One stray PHP notice in the
middle of the stream corrupts it, and the failure looks like a protocol bug
rather than a warning. This bit once: `curl_close()` is deprecated from PHP
8.5, and the notice landed inside a response. The bridge sets
`display_errors = stderr` and no longer calls it.

## Using it outside Glama

`stdio-bridge.php` is not Glama-specific. Any client that speaks only stdio can
reach a Keelhob site through it:

```bash
php tests/rig/glama/stdio-bridge.php \
  https://your-site.example/wp-json/keelhob/v1/mcp kh_xxxxxxxx
```

Most clients do not need this — `claude mcp add --transport http` talks to the
endpoint directly, and that remains the documented path.

## The build configuration

The build steps and start command are **not** in this repository: Glama
generates its own Dockerfile from a form on the server's admin page, and does
not accept one of yours. The values that fill that form are kept with the
project's other private operational notes.
