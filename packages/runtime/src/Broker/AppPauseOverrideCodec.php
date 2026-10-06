<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use JsonException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\App\AppPauseOverride;
use Stewart\Runtime\Json\ClassShapeReader;
use Stewart\Runtime\Json\Collection\ValueConverterCollection;
use Stewart\Runtime\Json\IsoInstantConverter;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Support\Json\JsonDecoder;

final readonly class AppPauseOverrideCodec
{
    private const int DEPTH = 8;

    public function __construct(private WireMapper $appPauseOverrideWireMapper) {}

    public static function createOverrideWireMapper(): WireMapper
    {
        return new WireMapper(new ClassShapeReader(ValueConverterCollection::keyedByHandledClass([new IsoInstantConverter()])));
    }

    /** @throws JsonException|StewartException */
    public function encodeOverride(AppPauseOverride $override): string
    {
        return $this->appPauseOverrideWireMapper->encodeObject(new StoredAppPauseOverride($override->paused, $override->since, $override->source));
    }

    /** @throws JsonException|StewartException */
    public function decodeOverride(AppId $appId, string $json): AppPauseOverride
    {
        $data = JsonDecoder::decodeJson($json, self::DEPTH);

        if (!\is_array($data)) {
            throw JsonShapeException::wrongType('override', 'an object', get_debug_type($data));
        }

        $stored = $this->appPauseOverrideWireMapper->decodeObject(StoredAppPauseOverride::class, $data);

        return new AppPauseOverride($appId, $stored->paused, $stored->since, $stored->source);
    }
}
