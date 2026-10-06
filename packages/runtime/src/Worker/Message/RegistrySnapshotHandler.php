<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\RegistrySnapshot;
use Stewart\Runtime\Registry\RegistryCache;

/** @implements BrokerMessageHandler<RegistrySnapshot> */
final readonly class RegistrySnapshotHandler implements BrokerMessageHandler
{
    public function __construct(
        private RegistryCache $registryCache,
        private LoggerInterface $logger,
    ) {}

    public function handledMessageClass(): string
    {
        return RegistrySnapshot::class;
    }

    /** @param RegistrySnapshot $message */
    public function handle(BrokerMessage $message): void
    {
        $registry = $message->registry->registry;

        if ($this->registryCache->replaceIfNewer($registry, $message->revision)) {
            $this->logger->debug('Registry updated', [
                'revision' => $message->revision,
                'areas' => $registry->listAreas()->count(),
                'devices' => $registry->listDevices()->count(),
                'entities' => $registry->listEntities()->count(),
            ]);
        }
    }
}
