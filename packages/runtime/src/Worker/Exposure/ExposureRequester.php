<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Amp\CancelledException;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\DeviceInfo;
use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\TransportError;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\ExposeEntityRequest;
use Stewart\Runtime\Ipc\Message\ReconfigureExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\RemoveExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\UpdateExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\PendingRequest;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Runtime\Worker\Subject\ExposeEntitySubject;
use Stewart\Runtime\Worker\Subject\ExposureChangeSubject;
use Stewart\Support\Time\Deadlines;

final readonly class ExposureRequester
{
    public function __construct(
        private Transport $transport,
        private PendingRequests $pending,
        private Deadlines $deadlines,
        private Duration $exposureRequestTimeout,
    ) {}

    /** @throws ExposureException */
    public function requestExposure(
        ResourceScope $scope,
        ExposedEntityKey $key,
        ExposedEntityConfig $config,
        ?DeviceInfo $device,
        ExposedStateChange $change,
    ): ?ExposedEntitySnapshot {
        $pending = $this->pending->open(new ExposeEntitySubject($key));

        return $this->awaitAnswer($pending, $key, new ExposeEntityRequest($pending->correlationId, $scope, $key, $config, $device, $change))->snapshot;
    }

    /** @throws ExposureException */
    public function requestReconfiguration(ResourceScope $scope, ExposedEntityKey $key, ExposedEntityConfig $config): ?ExposedEntitySnapshot
    {
        $pending = $this->pending->open(new ExposeEntitySubject($key));

        return $this->awaitAnswer($pending, $key, new ReconfigureExposedEntityRequest($pending->correlationId, $scope, $key, $config))->snapshot;
    }

    /** @throws ExposureException */
    public function requestUpdate(ResourceScope $scope, ExposedEntityKey $key, ExposedStateChange $change): void
    {
        $pending = $this->pending->open(new ExposureChangeSubject($key));
        $this->awaitAnswer($pending, $key, new UpdateExposedEntityRequest($pending->correlationId, $scope, $key, $change));
    }

    /** @throws ExposureException */
    public function requestRemoval(ResourceScope $scope, ExposedEntityKey $key): void
    {
        $pending = $this->pending->open(new ExposureChangeSubject($key));
        $this->awaitAnswer($pending, $key, new RemoveExposedEntityRequest($pending->correlationId, $scope, $key));
    }

    /**
     * @template TResult of object
     *
     * @param PendingRequest<TResult> $pending
     * @return TResult
     * @throws ExposureException
     */
    private function awaitAnswer(PendingRequest $pending, ExposedEntityKey $key, WorkerMessage $request): object
    {
        try {
            $this->transport->send($request);

            return $pending->getFuture()->await($this->deadlines->timeout($this->exposureRequestTimeout));
        } catch (TransportException $e) {
            throw $e->reason === TransportError::Unencodable
                ? ExposureException::stateInvalid($e->getMessage())
                : ExposureException::unreachable($key, 'the broker is gone: ' . $e->getMessage(), $e);
        } catch (CancelledException) {
            throw ExposureException::timedOut($key, $this->exposureRequestTimeout);
        } finally {
            $this->pending->forget($pending->correlationId);
        }
    }
}
