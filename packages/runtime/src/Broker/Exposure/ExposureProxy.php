<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Exposure;

use Closure;
use Psr\Log\LoggerInterface;
use Stewart\Client\Component\ExposedEntityDefinition;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\ExposeEntityRequest;
use Stewart\Runtime\Ipc\Message\ExposeEntityResult;
use Stewart\Runtime\Ipc\Message\ExposureAcknowledged;
use Stewart\Runtime\Ipc\Message\ExposureFailed;
use Stewart\Runtime\Ipc\Message\RemoveExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\UpdateExposedEntityRequest;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Throwable;

use function Amp\async;

final readonly class ExposureProxy
{
    public function __construct(
        private HaSession $session,
        private LoggerInterface $logger,
    ) {}

    public function forwardExpose(WorkerHandle $handle, ExposeEntityRequest $request): void
    {
        $this->answerInBackground($handle, $request->correlationId, function () use ($handle, $request): BrokerMessage {
            $appId = $this->requireAppId($request->scope, $request->key);
            $definition = ExposedEntityDefinition::fromConfig($request->config, $request->device);
            $snapshot = $this->session->exposeEntity($handle->id, $appId, $request->key, $definition, $request->change);

            return new ExposeEntityResult($request->correlationId, $snapshot);
        });
    }

    public function forwardUpdate(WorkerHandle $handle, UpdateExposedEntityRequest $request): void
    {
        $this->answerInBackground($handle, $request->correlationId, function () use ($request): BrokerMessage {
            $this->session->updateExposedEntity($this->requireAppId($request->scope, $request->key), $request->key, $request->change);

            return new ExposureAcknowledged($request->correlationId);
        });
    }

    public function forwardRemove(WorkerHandle $handle, RemoveExposedEntityRequest $request): void
    {
        $this->answerInBackground($handle, $request->correlationId, function () use ($request): BrokerMessage {
            $this->session->removeExposedEntity($this->requireAppId($request->scope, $request->key), $request->key);

            return new ExposureAcknowledged($request->correlationId);
        });
    }

    /** @param Closure(): BrokerMessage $answer */
    private function answerInBackground(WorkerHandle $handle, CorrelationId $correlationId, Closure $answer): void
    {
        async(function () use ($handle, $correlationId, $answer): void {
            try {
                $handle->send($this->runAnswer($correlationId, $answer));
            } catch (Throwable $e) {
                $this->logger->error('Exposure reply failed', ['exception' => $e, 'worker' => $handle->id->value, 'correlation_id' => $correlationId->value]);
            }
        })->ignore();
    }

    /** @param Closure(): BrokerMessage $answer */
    private function runAnswer(CorrelationId $correlationId, Closure $answer): BrokerMessage
    {
        try {
            return $answer();
        } catch (ExposureException $e) {
            return ExposureFailed::fromException($correlationId, $e);
        }
    }

    /** @throws ExposureException */
    private function requireAppId(ResourceScope $scope, ExposedEntityKey $key): AppId
    {
        return $scope->appId ?? throw ExposureException::outsideApp($key);
    }
}
