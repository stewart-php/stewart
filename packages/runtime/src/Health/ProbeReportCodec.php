<?php

declare(strict_types=1);

namespace Stewart\Runtime\Health;

use JsonException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Json\ClassShapeReader;
use Stewart\Runtime\Json\Collection\ValueConverterCollection;
use Stewart\Runtime\Json\WireMapper;

final readonly class ProbeReportCodec
{
    public function __construct(private WireMapper $probeReportWireMapper) {}

    public static function createProbeReportWireMapper(): WireMapper
    {
        return new WireMapper(new ClassShapeReader(ValueConverterCollection::keyedByHandledClass([])));
    }

    /** @throws JsonException|StewartException */
    public function encodeReport(ProbeReport $report): string
    {
        return $this->probeReportWireMapper->encodeObject($report);
    }
}
