<?php

declare(strict_types=1);

namespace Stewart\Testing\Exposure\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Testing\Exposure\RecordedExposure;

/** @extends ListCollection<RecordedExposure> */
final readonly class RecordedExposureCollection extends ListCollection
{
    public function withRecordedExposure(RecordedExposure $exposure): self
    {
        return $this->withAppendedElement($exposure);
    }
}
