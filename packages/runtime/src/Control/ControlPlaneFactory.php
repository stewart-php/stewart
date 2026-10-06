<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Broker\ControlPlane;
use Stewart\Runtime\Broker\DisabledControlPlane;
use Stewart\Runtime\Config\ControlConfig;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Control\Protocol\Codec\FrameCodec;
use Stewart\Runtime\Control\Request\ControlRequestDispatcher;
use Stewart\Runtime\Control\Server\ControlServer;
use Stewart\Runtime\Control\Server\UnixSocketFile;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Support\Time\Deadlines;

final readonly class ControlPlaneFactory
{
    public function __construct(
        private ControlConfig $control,
        private ControlRequestDispatcher $requests,
        private Deadlines $deadlines,
        private FrameCodec $codec,
        private LoggerInterface $logger,
        private UnixSocketFile $socketFile,
        private ProjectRoot $projectRoot,
    ) {}

    /** @throws ConfigurationException */
    public function createControlPlane(): ControlPlane
    {
        if ($this->control->listen === null) {
            return new DisabledControlPlane();
        }

        return new ControlServer(
            address: $this->control->listen,
            token: $this->control->token ?? throw ConfigurationException::controlTokenMissing(),
            requests: $this->requests,
            deadlines: $this->deadlines,
            codec: $this->codec,
            logger: $this->logger,
            socketFile: $this->socketFile,
            projectRoot: $this->projectRoot,
        );
    }
}
