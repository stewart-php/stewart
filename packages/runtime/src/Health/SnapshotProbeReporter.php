<?php

declare(strict_types=1);

namespace Stewart\Runtime\Health;

use Stewart\Runtime\Control\SnapshotAssembler;

final readonly class SnapshotProbeReporter implements ProbeReporter
{
    public function __construct(
        private SnapshotAssembler $snapshots,
        private ReadinessCheck $readiness,
    ) {}

    public function reportProbe(ProbeKind $kind): ProbeReport
    {
        return match ($kind) {
            ProbeKind::Liveness => ProbeReport::alive(),
            ProbeKind::Readiness => ProbeReport::fromVerdict($this->readiness->assessSnapshot($this->snapshots->assembleSnapshot())),
        };
    }
}
