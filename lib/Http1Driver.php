<?php

namespace Aerys;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\IteratorStream;
use Amp\Emitter;
use Amp\Http\Status;
use Amp\Uri\InvalidUriException;
use Amp\Uri\Uri;

class Http1Driver implements HttpDriver {
    const HEADER_REGEX = "(
        ([^()<>@,;:\\\"/[\]?={}\x01-\x20\x7F]+):[\x20\x09]*
        ([^\x01-\x08\x0A-\x1F\x7F]*)[\x0D]?[\x20\x09]*[\r]?[\n]
    )x";

    /** @var \Aerys\Http2Driver|null */
    private $http2;

    /** @var \Aerys\Client */
    private $client;

    /** @var \Aerys\Options */
    private $options;

    /** @var \Aerys\TimeReference */
    private $timeReference;

    /** @var \Amp\Emitter|null */
    private $bodyEmitter;

    /** @var int */
    private $pendingResponses = 0;

    /** @var int */
    private $remainingRequests;

    /** @var callable */
    private $onMessage;

    /** @var callable */
    private $write;

    public function __construct(Options $options, TimeReference $timeReference) {
        $this->options = $options;
        $this->timeReference = $timeReference;

        $this->remainingRequests = $this->options->getMaxRequestsPerConnection();
    }

    public function setup(Client $client, callable $onMessage, callable $write) {
        $this->client = $client;
        $this->onMessage = $onMessage;
        $this->write = $write;
    }

    public function writer(Response $response, Request $request = null): \Generator {
        if ($this->http2) {
            yield from $this->http2->writer($response, $request);
            return;
        }

        $shouldClose = false;

        $protocol = $request !== null ? $request->getProtocolVersion() : "1.0";

        $status = $response->getStatus();
        $reason = $response->getReason();

        $headers = $this->filter($response, $protocol, $request ? $request->getHeaderArray("connection") : []);

        $chunked = !isset($headers["content-length"])
            && $protocol === "1.1"
            && $status >= Status::OK;

        if (!empty($headers["connection"])) {
            foreach ($headers["connection"] as $connection) {
                if (\strcasecmp($connection, "close") === 0) {
                    $chunked = false;
                    $shouldClose = true;
                }
            }
        }

        if ($chunked) {
            $headers["transfer-encoding"] = ["chunked"];
        }

        $buffer = "HTTP/{$protocol} {$status} {$reason}\r\n";
        foreach ($headers as $headerField => $headerLines) {
            if ($headerField[0] !== ":") {
                foreach ($headerLines as $headerLine) {
                    /* verify header fields (per RFC) and header values against containing \n */
                    \assert(
                        strpbrk($headerField, "\n\t ()<>@,;:\\\"/[]?={}") === false
                        && strpbrk((string) $headerLine, "\n") === false
                    );
                    $buffer .= "{$headerField}: {$headerLine}\r\n";
                }
            }
        }
        $buffer .= "\r\n";

        if ($request !== null && $request->getMethod() === "HEAD") {
            ($this->write)($buffer, true);
            while (null !== yield); // Ignore body portions written.
        } else {
            $outputBufferSize = $this->options->getOutputBufferSize();

            do {
                if (\strlen($buffer) >= $outputBufferSize) {
                    ($this->write)($buffer);
                    $buffer = "";

                    if ($this->client->getStatus() & Client::CLOSED_WR) {
                        return;
                    }
                }

                if (null === $part = yield) {
                    break;
                }

                if ($chunked && $length = \strlen($part)) {
                    $buffer .= \sprintf("%x\r\n%s\r\n", $length, $part);
                } else {
                    $buffer .= $part;
                }
            } while (true);

            if ($chunked) {
                $buffer .= "0\r\n\r\n";
            }

            ($this->write)($buffer, $shouldClose);
        }

        $this->pendingResponses--;
        $this->remainingRequests--;
    }

    public function parser(): \Generator {
        $maxHeaderSize = $this->options->getMaxHeaderSize();
        $maxBodySize = $this->options->getMaxBodySize();
        $bodyEmitSize = $this->options->getIoGranularity();
        $parser = null;
        $buffer = "";

        do {
            if ($parser !== null) { // May be set from upgrade request or receive of PRI * HTTP/2.0 request.
                /** @var \Generator $parser */
                yield from $parser; // Yield from HTTP/2 parser for duration of connection.
                return;
            }

            $headers = [];
            $contentLength = null;
            $isChunked = false;

            do {
                $buffer = \ltrim($buffer, "\r\n");

                if ($headerPos = \strpos($buffer, "\r\n\r\n")) {
                    $startLineAndHeaders = \substr($buffer, 0, $headerPos + 2);
                    $buffer = (string) \substr($buffer, $headerPos + 4);
                    break;
                }

                if (\strlen($buffer) > $maxHeaderSize) {
                    throw new ClientException(
                        "Bad Request: header size violation",
                        Status::REQUEST_HEADER_FIELDS_TOO_LARGE
                    );
                }

                $buffer .= yield;
            } while (true);

            $startLineEndPos = \strpos($startLineAndHeaders, "\r\n");
            $startLine = substr($startLineAndHeaders, 0, $startLineEndPos);
            $rawHeaders = \substr($startLineAndHeaders, $startLineEndPos + 2);

            if (!\preg_match("/^([A-Z]+) (\S+) HTTP\/(\d+(?:\.\d+)?)$/i", $startLine, $matches)) {
                throw new ClientException("Bad Request: invalid request line", Status::BAD_REQUEST);
            }

            list(, $method, $target, $protocol) = $matches;

            if ($protocol !== "1.1" && $protocol !== "1.0") {
                if ($protocol === "2.0") {
                    // Internal upgrade to HTTP/2.
                    $this->http2 = new Http2Driver($this->options, $this->timeReference);
                    $this->http2->setup($this->client, $this->onMessage, $this->write);

                    $parser = $this->http2->parser();
                    $parser->send("$startLineAndHeaders\r\n$buffer");
                    continue; // Yield from the above parser immediately.
                }

                throw new ClientException("Unsupported version {$protocol}", Status::HTTP_VERSION_NOT_SUPPORTED);
            }

            if ($rawHeaders) {
                if (\strpos($rawHeaders, "\n\x20") || \strpos($rawHeaders, "\n\t")) {
                    throw new ClientException(
                        "Bad Request: multi-line headers deprecated by RFC 7230",
                        Status::BAD_REQUEST
                    );
                }

                if (!\preg_match_all(self::HEADER_REGEX, $rawHeaders, $matches, \PREG_SET_ORDER)) {
                    throw new ClientException("Bad Request: header syntax violation", Status::BAD_REQUEST);
                }

                foreach ($matches as list(, $field, $value)) {
                    $headers[$field][] = $value;
                }

                $headers = \array_change_key_case($headers);

                $contentLength = $headers["content-length"][0] ?? null;
                if ($contentLength !== null) {
                    if (!\preg_match("/^(?:0|[1-9][0-9]*)$/", $contentLength)) {
                        throw new ClientException("Bad Request: invalid content length", Status::BAD_REQUEST);
                    }

                    $contentLength = (int) $contentLength;
                }

                if (isset($headers["transfer-encoding"])) {
                    $value = strtolower($headers["transfer-encoding"][0]);
                    if (!($isChunked = $value === "chunked") && $value !== "identity") {
                        throw new ClientException(
                            "Bad Request: unsupported transfer-encoding",
                            Status::BAD_REQUEST
                        );
                    }
                }
            }

            if ($this->options->shouldNormalizeMethodCase()) {
                $method = \strtoupper($method);
            }

            $host = $headers["host"][0] ?? ""; // Host header may be set but empty.
            if ($host === "") {
                throw new ClientException("Bad Request: invalid host header", Status::BAD_REQUEST);
            }

            try {
                if ($target === "*") {
                    $port = $this->client->getLocalPort();
                    if ($port) {
                        $uri = new Uri($host . ":" . $port);
                    } else {
                        $uri = new Uri($host);
                    }
                } elseif (\preg_match("#^https?://#i", $target)) {
                    $uri = new Uri($target);
                } else {
                    $scheme = $this->client->isEncrypted() ? "https" : "http";
                    if (($colon = \strrpos($host, ":")) !== false) {
                        $port = (int) \substr($host, $colon + 1);
                        $host = \substr($host, 0, $colon);
                    } else {
                        $port = $this->client->getLocalPort();
                    }

                    if ($port) {
                        $uri = new Uri($scheme . "://" . $host . ":" . $port . $target);
                    } else {
                        $uri = new Uri($scheme . "://" . $host . $target);
                    }
                }
            } catch (InvalidUriException $exception) {
                throw new ClientException("Bad Request: invalid target", Status::BAD_REQUEST, $exception);
            }

            // Handle HTTP/2 upgrade request.
            if ($protocol === "1.1" &&
                isset($headers["upgrade"][0], $headers["http2-settings"][0], $headers["connection"][0]) &&
                false !== stripos($headers["connection"][0], "upgrade") &&
                strtolower($headers["upgrade"][0]) === "h2c" &&
                false !== $h2cSettings = base64_decode(strtr($headers["http2-settings"][0], "-_", "+/"), true)
            ) {
                // Request instance will be overwritten below. This is for sending the switching protocols response.
                $request = new Request($this->client, $method, $uri, $headers, null, $target, $protocol);

                $this->pendingResponses++;
                $responseWriter = $this->writer(new Response(null, [
                    "connection" => "upgrade",
                    "upgrade" => "h2c",
                ], Status::SWITCHING_PROTOCOLS), $request);
                $responseWriter->send(null); // Flush before replacing

                // Internal upgrade
                $this->http2 = new Http2Driver($this->options, $this->timeReference);
                $this->http2->setup($this->client, $this->onMessage, $this->write);

                $parser = $this->http2->parser($h2cSettings, true);
                $parser->current(); // Yield from this parser after reading the current request body.

                // Not needed for HTTP/2 request.
                unset($headers["upgrade"], $headers["connection"], $headers["http2-settings"]);

                // Make request look like HTTP/2 request.
                $headers[":method"][0] = $method;
                $headers[":authority"][0] = $uri->getAuthority(false);
                $headers[":scheme"][0] = $uri->getScheme();
                $headers[":path"][0] = $target;

                $protocol = "2.0";
            }

            if (!($isChunked || $contentLength)) {
                $request = new Request(
                    $this->client,
                    $method,
                    $uri,
                    $headers,
                    new Body(new InMemoryStream),
                    $target,
                    $protocol
                );

                $this->pendingResponses++;

                $buffer .= yield ($this->onMessage)($request); // Wait for response to be fully written.

                continue;
            }

            // HTTP/1.x clients only ever have a single body emitter.
            $this->bodyEmitter = $emitter = new Emitter;

            $body = new Body(
                new IteratorStream($this->bodyEmitter->iterate()),
                function (int $bodySize) use (&$maxBodySize) {
                    if ($bodySize > $maxBodySize) {
                        $maxBodySize = $bodySize;
                    }
                }
            );

            $request = new Request($this->client, $method, $uri, $headers, $body, $target, $protocol);

            $this->pendingResponses++;

            $promise = ($this->onMessage)($request); // Do not yield promise until body is completely read.

            $body = "";

            try {
                if ($isChunked) {
                    $bodySize = 0;
                    while (true) {
                        while (false === ($lineEndPos = \strpos($buffer, "\r\n"))) {
                            if (\strlen($buffer) > 10) {
                                throw new ClientException(
                                    "Bad Request: hex chunk size expected",
                                    Status::BAD_REQUEST
                                );
                            }

                            $buffer .= yield;
                        }

                        $line = \substr($buffer, 0, $lineEndPos);
                        $buffer = \substr($buffer, $lineEndPos + 2);
                        $hex = \trim($line);
                        if ($hex !== "0") {
                            $hex = \ltrim($line, "0");

                            if (!\preg_match("/^[1-9A-F][0-9A-F]*?$/i", $hex)) {
                                throw new ClientException(
                                    "Bad Request: invalid hex chunk size",
                                    Status::BAD_REQUEST
                                );
                            }
                        }

                        $chunkLenRemaining = \hexdec($hex);

                        if ($chunkLenRemaining === 0) {
                            while (!isset($buffer[1])) {
                                $buffer .= yield;
                            }
                            $firstTwoBytes = \substr($buffer, 0, 2);
                            if ($firstTwoBytes === "\r\n") {
                                $buffer = \substr($buffer, 2);
                                break; // finished ($is_chunked loop)
                            }

                            do {
                                if ($trailerSize = \strpos($buffer, "\r\n\r\n")) {
                                    $trailers = \substr($buffer, 0, $trailerSize + 2);
                                    $buffer = \substr($buffer, $trailerSize + 4);
                                } else {
                                    $buffer .= yield;
                                    $trailerSize = \strlen($buffer);
                                    $trailers = null;
                                }
                                if ($maxHeaderSize > 0 && $trailerSize > $maxHeaderSize) {
                                    throw new ClientException(
                                        "Trailer headers too large",
                                        Status::BAD_REQUEST
                                    );
                                }
                            } while (!isset($trailers));

                            if (\strpos($trailers, "\n\x20") || \strpos($trailers, "\n\t")) {
                                throw new ClientException(
                                    "Bad Request: multi-line trailers deprecated by RFC 7230",
                                    Status::BAD_REQUEST
                                );
                            }

                            if (!\preg_match_all(self::HEADER_REGEX, $trailers, $matches)) {
                                throw new ClientException(
                                    "Bad Request: trailer syntax violation",
                                    Status::BAD_REQUEST
                                );
                            }

                            list(, $fields, $values) = $matches;
                            $trailers = [];
                            foreach ($fields as $index => $field) {
                                $trailers[$field][] = $values[$index];
                            }

                            // @TODO Alter Body to support trailer headers.
                            if ($trailers) {
                                $trailers = \array_change_key_case($trailers);

                                foreach (["transfer-encoding", "content-length", "trailer"] as $remove) {
                                    unset($trailers[$remove]);
                                }

                                if ($trailers) {
                                    $headers = \array_merge($headers, $trailers);
                                }
                            }

                            break; // finished (chunked loop)
                        }

                        if ($bodySize + $chunkLenRemaining > $maxBodySize) {
                            do {
                                $remaining = $maxBodySize - $bodySize;
                                $chunkLenRemaining -= $remaining - \strlen($body);
                                $body .= $buffer;
                                $bodyBufferSize = \strlen($body);

                                while ($bodyBufferSize < $remaining) {
                                    if ($bodyBufferSize >= $bodyEmitSize) {
                                        $emitter->emit($body);
                                        $body = '';
                                        $bodySize += $bodyBufferSize;
                                        $remaining -= $bodyBufferSize;
                                    }
                                    $body .= yield;
                                    $bodyBufferSize = \strlen($body);
                                }
                                if ($remaining) {
                                    $emitter->emit(substr($body, 0, $remaining));
                                    $buffer = substr($body, $remaining);
                                    $body = "";
                                    $bodySize += $remaining;
                                }

                                if ($bodySize !== $maxBodySize) {
                                    continue;
                                }

                                throw new ClientException("Payload too large", Status::PAYLOAD_TOO_LARGE);
                            } while ($maxBodySize < $bodySize + $chunkLenRemaining);
                        }

                        $bodyBufferSize = 0;

                        while (true) {
                            $bufferLen = \strlen($buffer);
                            // These first two (extreme) edge cases prevent errors where the packet boundary ends after
                            // the \r and before the \n at the end of a chunk.
                            if ($bufferLen === $chunkLenRemaining || $bufferLen === $chunkLenRemaining + 1) {
                                $buffer .= yield;
                                continue;
                            } elseif ($bufferLen >= $chunkLenRemaining + 2) {
                                $body .= substr($buffer, 0, $chunkLenRemaining);
                                $buffer = substr($buffer, $chunkLenRemaining + 2);
                                $bodyBufferSize += $chunkLenRemaining;
                            } else {
                                $body .= $buffer;
                                $bodyBufferSize += $bufferLen;
                                $chunkLenRemaining -= $bufferLen;
                            }

                            if ($bodyBufferSize >= $bodyEmitSize) {
                                $emitter->emit($body);
                                $body = '';
                                $bodySize += $bodyBufferSize;
                                $bodyBufferSize = 0;
                            }

                            if ($bufferLen >= $chunkLenRemaining + 2) {
                                $chunkLenRemaining = null;
                                continue 2; // next chunk (chunked loop)
                            }

                            $buffer = yield;
                        }
                    }

                    if ($body !== "") {
                        $emitter->emit($body);
                    }
                } else {
                    $bodySize = 0;

                    if ($maxBodySize < $contentLength) {
                        throw new ClientException("Payload too large", Status::PAYLOAD_TOO_LARGE);
                    }

                    $bound = $contentLength;
                    $bodyBufferSize = \strlen($buffer);

                    while ($bodySize + $bodyBufferSize < $bound) {
                        if ($bodyBufferSize >= $bodyEmitSize) {
                            $emitter->emit($buffer);
                            $buffer = '';
                            $bodySize += $bodyBufferSize;
                        }
                        $buffer .= yield;
                        $bodyBufferSize = \strlen($buffer);
                    }
                    $remaining = $bound - $bodySize;
                    if ($remaining) {
                        $emitter->emit(substr($buffer, 0, $remaining));
                        $buffer = substr($buffer, $remaining);
                    }
                }

                $this->bodyEmitter = null;
                $emitter->complete();

                $buffer .= yield $promise; // Wait for response to be fully written.
            } catch (\Throwable $exception) {
                // Catching and rethrowing to set $exception to be used in finally.
                throw $exception;
            } finally {
                if (isset($this->bodyEmitter)) {
                    $emitter = $this->bodyEmitter;
                    $this->bodyEmitter = null;
                    $emitter->fail($exception ?? new ClientException(
                        "Client disconnected",
                        Status::REQUEST_TIMEOUT
                    ));
                }
            }
        } while (true);
    }

    public function pendingRequestCount(): int {
        return $this->bodyEmitter !== null ? 1 : 0;
    }

    public function pendingResponseCount(): int {
        return $this->pendingResponses;
    }

    private function filter(Response $response, string $protocol = "1.0", array $connection = []): array {
        $headers = $response->getHeaders();

        if ($response->getStatus() < Status::OK) {
            return $headers;
        }

        $push = $response->getPush();

        if (!empty($push)) {
            $headers["link"] = [];
            foreach ($push as $url => $pushHeaders) {
                $headers["link"][] = "<$url>; rel=preload";
            }
        }

        $contentLength = $headers["content-length"][0] ?? null;
        $shouldClose = (\in_array("close", $connection))
            || (isset($headers["connection"]) && \in_array("close", $headers["connection"]));

        if ($contentLength !== null) {
            $shouldClose = $shouldClose || $protocol === "1.0";
            unset($headers["transfer-encoding"]);
        } elseif ($protocol === "1.1") {
            unset($headers["content-length"]);
        } else {
            $shouldClose = true;
        }

        $remainingRequests = $this->remainingRequests;
        if ($shouldClose || $remainingRequests <= 0) {
            $headers["connection"] = ["close"];
        } elseif ($remainingRequests < (PHP_INT_MAX >> 1)) {
            $headers["connection"] = ["keep-alive"];
            $keepAlive = "timeout={$this->options->getConnectionTimeout()}, max={$remainingRequests}";
            $headers["keep-alive"] = [$keepAlive];
        } else {
            $headers["connection"] = ["keep-alive"];
            $keepAlive = "timeout={$this->options->getConnectionTimeout()}";
            $headers["keep-alive"] = [$keepAlive];
        }

        $headers["date"] = [$this->timeReference->getCurrentDate()];

        return $headers;
    }
}
