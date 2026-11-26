<?php
/**
 * stdio <-> HTTP bridge for Keelhob, for harnesses that only speak stdio.
 *
 * Keelhob's MCP server is an HTTP endpoint inside WordPress. Glama's build
 * harness starts a process and talks to it on stdin/stdout, so something has
 * to sit between the two. This is that something, and it is deliberately dumb:
 * read one JSON-RPC message per line, POST it, write the reply back as one
 * line. No session handling, because the endpoint is stateless.
 *
 *   php kh-bridge.php <endpoint-url> <bearer-token>
 */
// Anything PHP prints on stdout corrupts the JSON-RPC stream, and a stray
// deprecation notice is exactly how that happens: curl_close() is deprecated
// from PHP 8.5, and on a default CLI the notice landed in the middle of a
// response. Errors go to stderr, where a harness can still collect them.
ini_set('display_errors', 'stderr');

$url   = $argv[1] ?? '';
$token = $argv[2] ?? '';
if ($url === '' || $token === '') {
    fwrite(STDERR, "usage: kh-bridge.php <url> <token>\n");
    exit(2);
}

$in = fopen('php://stdin', 'r');
while (($line = fgets($in)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $line,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    // No curl_close(): it has done nothing since PHP 8.0 and is deprecated
    // from 8.5. The handle is freed when $ch goes out of scope.

    if ($body === false) {
        fwrite(STDERR, "bridge: $err\n");
        continue;
    }

    // The endpoint answers either plain JSON or an SSE frame. Take the last
    // "data:" line when it is SSE, otherwise the body as it stands.
    $out = trim((string) $body);
    if (strpos($out, 'data:') !== false) {
        $data = '';
        foreach (explode("\n", $out) as $l) {
            if (strncmp($l, 'data:', 5) === 0) {
                $data = trim(substr($l, 5));
            }
        }
        $out = $data;
    }

    // A notification gets an empty 202. Writing a blank line would break the
    // framing, so say nothing, which is what the protocol expects.
    if ($out === '') {
        continue;
    }

    fwrite(STDOUT, $out . "\n");
    fflush(STDOUT);
}
