<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Control\Protocol\Status\GeneratedCodeStatus;
use Stewart\Support\Wire\GeneratedFormat;

final class GeneratedCodeDrift
{
    public private(set) ?GeneratedCodeStatus $status = null;

    public function __construct(
        private readonly HaSession $session,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private ?GeneratedManifest $manifest = null,
    ) {}

    public function replaceGeneratedManifest(?GeneratedManifest $manifest): void
    {
        $this->manifest = $manifest;
    }

    // Rechecked often, so a warning repeats only when what it says has changed.
    public function recheckGeneratedCode(): void
    {
        if ($this->manifest === null) {
            $this->status = null;

            return;
        }

        $previous = $this->status;
        $drift = $this->manifest->findDrift($this->session->listEntityIds());
        $status = new GeneratedCodeStatus($this->manifest->formatVersion, GeneratedFormat::VERSION, $drift->added, $drift->removed, $this->clock->getNow());
        $this->status = $status;

        if (!$status->hasCurrentFormat() && $previous?->format !== $status->format) {
            $this->logger->warning('Generated classes were written by another stewart generate format; run stewart generate', [
                'format' => $status->format,
                'expected_format' => $status->expectedFormat,
            ]);
        }

        if ($status->hasDrift() && ($previous === null || !$previous->hasSameDriftAs($status))) {
            $this->logger->warning('Generated entity classes no longer match Home Assistant; run stewart generate', [
                'added' => $status->added,
                'removed' => $status->removed,
            ]);
        } elseif (!$status->hasDrift() && $previous?->hasDrift() === true) {
            $this->logger->info('Generated entity classes match Home Assistant again');
        }
    }
}
