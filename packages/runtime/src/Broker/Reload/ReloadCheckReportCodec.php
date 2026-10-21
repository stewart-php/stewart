<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use JsonException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Json\ClassShapeReader;
use Stewart\Runtime\Json\Collection\ValueConverterCollection;
use Stewart\Runtime\Json\WireMapper;
use Stewart\Support\Exception\JsonShapeException;
use Stewart\Support\Json\JsonDecoder;

final readonly class ReloadCheckReportCodec
{
    private const int DEPTH = 8;

    public function __construct(private WireMapper $reloadCheckReportWireMapper) {}

    public static function createReportWireMapper(): WireMapper
    {
        return new WireMapper(new ClassShapeReader(ValueConverterCollection::keyedByHandledClass([])));
    }

    /** @throws JsonException|StewartException */
    public function encodeReport(ReloadCheckReport $report): string
    {
        return $this->reloadCheckReportWireMapper->encodeObject($report);
    }

    /** @throws JsonException|StewartException */
    public function decodeReport(string $json): ReloadCheckReport
    {
        $data = JsonDecoder::decodeJson($json, self::DEPTH);

        if (!\is_array($data)) {
            throw JsonShapeException::wrongType('report', 'an object', get_debug_type($data));
        }

        return $this->reloadCheckReportWireMapper->decodeObject(ReloadCheckReport::class, $data);
    }
}
