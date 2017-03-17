<?php

namespace Amp\Socket\Internal;

use Amp\{ Deferred, Loop, Promise };
use Amp\Socket\{ ConnectException, CryptoException, function cryptoEnable };

/** @internal */
function connect(string $uri, array $options): \Generator {
    list($scheme, $host, $port) = parseUri($uri);

    $context = [];

    $uris = [];

    if ($port === 0 || @\inet_pton($uri)) {
        // Host is already an IP address or file path.
        $uris = [$uri];
    } else {
        // Host is not an IP address, so resolve the domain name.
        $records = yield \Amp\Dns\resolve($host);
        foreach ($records as $record) {
            if ($record[1] === \Amp\Dns\Record::AAAA) {
                $uris[] = \sprintf("%s://[%s]:%d", $scheme, $record[0], $port);
            } else {
                $uris[] = \sprintf("%s://%s:%d", $scheme, $record[0], $port);
            }
        }
    }

    $flags = \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT;
    $timeout = 42; // <--- timeout not applicable for async connects

    foreach ($uris as $builtUri) {
        try {
            $context = \stream_context_create($context);
            if (!$socket = @\stream_socket_client($builtUri, $errno, $errstr, $timeout, $flags, $context)) {
                throw new ConnectException(\sprintf(
                    "Connection to %s failed: [Error #%d] %s",
                    $uri,
                    $errno,
                    $errstr
                ));
            }

            \stream_set_blocking($socket, false);
            $timeout = (int) ($options["timeout"] ?? 10000);

            $deferred = new Deferred;
            $watcher = Loop::onWritable($socket, [$deferred, 'resolve']);

            $promise = $deferred->promise();

            yield $timeout > 0 ? Promise\timeout($promise, $timeout) : $promise;
        } catch (\Exception $e) {
            continue; // Could not connect to host, try next host in the list.
        } finally {
            if (isset($watcher)) {
                Loop::cancel($watcher);
            }
        }

        return $socket;
    }

    if ($socket) {
        throw new ConnectException(\sprintf("Connecting to %s failed: timeout exceeded (%d ms)", $uri, $timeout));
    } else {
        throw $e;
    }
}

/** @internal */
function cryptoConnect(string $uri, array $options): \Generator {
    $socket = yield from connect($uri, $options);
    if (empty($options["peer_name"])) {
        $options["peer_name"] = \parse_url($uri, PHP_URL_HOST);
    }
    yield cryptoEnable($socket, $options);
    return $socket;
}

/** @internal */
function parseUri(string $uri): array {
    if (\stripos($uri, "unix://") === 0 || \stripos($uri, "udg://") === 0) {
        list($scheme, $path) = \explode("://", $uri, 2);
        return [$scheme, \ltrim($path, "/"), 0];
    }

    // TCP/UDP host names are always case-insensitive
    if (!$uriParts = @\parse_url(\strtolower($uri))) {
        throw new \Error(
            "Invalid URI: {$uri}"
        );
    }

    $scheme = $uriParts["scheme"] ?? "tcp";
    $host =   $uriParts["host"] ?? "";
    $port =   $uriParts["port"] ?? 0;

    if (!($scheme === "tcp" || $scheme === "udp")) {
        throw new \Error(
            "Invalid URI scheme ({$scheme}); tcp, udp, unix or udg scheme expected"
        );
    }

    if (empty($host) || empty($port)) {
        throw new \Error(
            "Invalid URI ({$uri}); host and port components required"
        );
    }

    if (\strpos($host, ":") !== false) { // IPv6 address
        $host = \sprintf("[%s]", \trim($host, "[]"));
    }

    return [$scheme, $host, $port];
}

/** @internal */
function onCryptoWatchReadability($watcherId, $socket, $cbData) {
    /** @var \Amp\Deferred $deferred */
    list($deferred, $method) = $cbData;
    $result = \stream_socket_enable_crypto($socket, $enable = true, $method);
    if ($result === true) {
        Loop::cancel($watcherId);
        $deferred->resolve($socket);
    } elseif ($result === false) {
        Loop::cancel($watcherId);
        $deferred->fail(new CryptoException(
            "Crypto negotiation failed: " . (\feof($socket) ? "Connection reset by peer" : \error_get_last()["message"])
        ));
    }
}
