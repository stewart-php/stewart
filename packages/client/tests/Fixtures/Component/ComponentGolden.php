<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Fixtures\Component;

use LogicException;
use Stewart\Support\Json\JsonDecoder;

final readonly class ComponentGolden
{
    /**
     * @param array<string, mixed>|null $request
     * @param array<string, mixed>|null $reply
     * @param array<string, mixed>|null $event
     */
    private function __construct(
        public ?array $request,
        public ?array $reply,
        public ?array $event,
    ) {}

    public static function loadGolden(string $name): self
    {
        $golden = JsonDecoder::decodeJson((string) file_get_contents(self::getDirectory() . '/' . $name . '.json'));

        if (!\is_array($golden)) {
            throw new LogicException(\sprintf('Golden "%s" is not a JSON object.', $name));
        }

        return new self(
            self::readObject($golden, 'request'),
            self::readReply($golden),
            self::readObject($golden, 'event'),
        );
    }

    public static function getDirectory(): string
    {
        return __DIR__ . '/Protocol';
    }

    public function requireRequestType(): string
    {
        $type = $this->request['type'] ?? null;

        return \is_string($type) ? $type : throw new LogicException('The golden holds no request.');
    }

    /** @return array<string, mixed> */
    public function requireReply(): array
    {
        return $this->reply ?? throw new LogicException('The golden holds no result or error.');
    }

    /** @return array<string, mixed> */
    public function requireEvent(): array
    {
        return $this->event ?? throw new LogicException('The golden holds no event.');
    }

    /**
     * @param array<array-key, mixed> $golden
     * @return array<string, mixed>|null
     */
    private static function readReply(array $golden): ?array
    {
        if (\array_key_exists('result', $golden)) {
            return ['type' => 'result', 'success' => true, 'result' => $golden['result']];
        }

        $error = self::readObject($golden, 'error');

        return $error === null ? null : ['type' => 'result', 'success' => false, 'error' => $error];
    }

    /**
     * @param array<array-key, mixed> $golden
     * @return array<string, mixed>|null
     */
    private static function readObject(array $golden, string $key): ?array
    {
        $value = $golden[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!\is_array($value)) {
            throw new LogicException(\sprintf('Golden key "%s" is not an object.', $key));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
