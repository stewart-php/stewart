<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\Json\JsonFragment;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Support\Json\JsonEncoder;

final readonly class AppIdsFragment implements JsonFragment
{
    private function __construct(public AppIdCollection $collection) {}

    public static function fromCollection(AppIdCollection $appIds): self
    {
        return new self($appIds);
    }

    public static function fromDecodedValue(mixed $value, string $path, WireMapper $mapper): static
    {
        return new self(AppIdCollection::fromIds($mapper->decodeObjectList(AppId::class, $value, $path)));
    }

    public function encodeToJson(WireMapper $mapper): string
    {
        return JsonEncoder::encodeToJson($this->collection->toStrings());
    }
}
