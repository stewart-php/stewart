<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Health;

use RuntimeException;
use Stewart\Runtime\Health\ProbeKind;
use Stewart\Runtime\Health\ProbeReport;
use Stewart\Runtime\Health\ProbeReporter;

final class StubProbeReporter implements ProbeReporter
{
    public ?ProbeReport $readiness = null;

    public bool $failing = false;

    public function reportProbe(ProbeKind $kind): ProbeReport
    {
        if ($this->failing) {
            throw new RuntimeException('snapshot unavailable');
        }

        return $kind === ProbeKind::Readiness && $this->readiness !== null ? $this->readiness : ProbeReport::alive();
    }
}
