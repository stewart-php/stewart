<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin;

use JsonException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\RequestFailed;
use Stewart\Runtime\Http\Admin\Response\AdminAppList;
use Stewart\Runtime\Http\Admin\Response\AdminAppView;
use Stewart\Runtime\Json\ClassShapeReader;
use Stewart\Runtime\Json\Collection\ValueConverterCollection;
use Stewart\Runtime\Json\IsoInstantConverter;
use Stewart\Runtime\Json\WireMapper;

final readonly class AdminApiCodec
{
    public function __construct(private WireMapper $adminApiWireMapper) {}

    public static function createAdminApiWireMapper(): WireMapper
    {
        return new WireMapper(new ClassShapeReader(ValueConverterCollection::keyedByHandledClass([new IsoInstantConverter()])));
    }

    /** @throws JsonException|StewartException */
    public function encodeResponse(AdminAppList|AdminAppView|CommandResult|RequestFailed $response): string
    {
        return $this->adminApiWireMapper->encodeObject($response);
    }
}
