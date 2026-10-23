<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Http;

use Amp\Socket\InternetAddress;

use function Amp\ByteStream\buffer;
use function Amp\Socket\connect;
use function Amp\Socket\listen;

final readonly class LocalHttpRequests
{
    /** @param int<0, 65535> $port */
    public function __construct(public int $port) {}

    public static function createOnFreePort(): self
    {
        $probe = listen('127.0.0.1:0');
        $address = $probe->getAddress();
        $probe->close();
        \assert($address instanceof InternetAddress);

        return new self($address->getPort());
    }

    public static function extractBody(string $response): string
    {
        return explode("\r\n\r\n", $response, 2)[1] ?? '';
    }

    public function sendRequest(string $method, string $path, ?string $token = null): string
    {
        $authorization = $token === null ? '' : \sprintf("Authorization: Bearer %s\r\n", $token);
        $socket = connect('tcp://127.0.0.1:' . $this->port);
        $socket->write(\sprintf("%s %s HTTP/1.1\r\nHost: localhost\r\n%sContent-Length: 0\r\nConnection: close\r\n\r\n", $method, $path, $authorization));

        return buffer($socket);
    }
}
