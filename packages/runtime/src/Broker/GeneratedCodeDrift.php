<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Generated\GeneratedFormat;

final readonly class GeneratedCodeDrift
{
    public function __construct(
        private HaSession $session,
        private LoggerInterface $logger,
        private ?ManifestCheck $manifest = null,
    ) {}

    public function warnIfGeneratedCodeDrifted(): void
    {
        if ($this->manifest === null) {
            return;
        }

        if (!$this->manifest->hasCurrentFormat()) {
            $this->logger->warning('Generated classes were written by another stewart generate format; run stewart generate', [
                'format' => $this->manifest->readFormatVersion(),
                'expected_format' => GeneratedFormat::VERSION,
            ]);
        }

        $drift = $this->manifest->findDrift($this->session->listEntityIds());

        if ($drift === null) {
            return;
        }

        $this->logger->warning('Generated entity classes no longer match Home Assistant; run stewart generate', [
            'added' => $drift->added,
            'removed' => $drift->removed,
        ]);
    }
}
