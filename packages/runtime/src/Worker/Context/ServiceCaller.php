<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Context;

use Amp\CancelledException;
use Stewart\Contracts\Action\ActionResponse;
use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\Exception\ActionCallException;
use Stewart\Contracts\State\EventContext;
use Stewart\Runtime\Exception\TransportError;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Ipc\WorkerSettings;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Support\Time\Deadlines;
use Stewart\Support\Wire\ServiceFields;
use Throwable;

final readonly class ServiceCaller
{
    public function __construct(
        private Transport $transport,
        private PendingRequests $pending,
        private ConnectionStatus $connection,
        private Deadlines $deadlines,
        private WorkerSettings $workerSettings,
    ) {}

    /** @throws ActionCallException */
    public function callService(ResourceScope $scope, string $domain, string $service, ServiceFields $data, ?ActionTarget $target): EventContext
    {
        return $this->sendRequestAndAwait($scope, $domain, $service, $data, $target, returnResponse: false)->context ?? EventContext::unknown();
    }

    /** @throws ActionCallException */
    public function callServiceForResponse(ResourceScope $scope, string $domain, string $service, ServiceFields $data, ?ActionTarget $target): ActionResponse
    {
        return $this->sendRequestAndAwait($scope, $domain, $service, $data, $target, returnResponse: true);
    }

    /** @throws ActionCallException */
    private function sendRequestAndAwait(
        ResourceScope $scope,
        string $domain,
        string $service,
        ServiceFields $data,
        ?ActionTarget $target,
        bool $returnResponse,
    ): ActionResponse {
        if (!$this->connection->connected) {
            throw ActionCallException::unreachable($domain, $service, 'Home Assistant is disconnected');
        }

        $call = $this->pending->open(
            ActionResponse::class,
            static fn(string $detail): ActionCallException => ActionCallException::unreachable($domain, $service, $detail),
        );

        try {
            $this->sendRequest(new ServiceCallRequest(
                correlationId: $call->correlationId,
                scope: $scope,
                domain: $domain,
                service: $service,
                data: $data->fields,
                target: $target,
                returnResponse: $returnResponse,
            ));

            return $call->getFuture()->await($this->deadlines->timeout($this->workerSettings->callTimeout));
        } catch (CancelledException $e) {
            throw ActionCallException::timedOut(
                $domain,
                $service,
                \sprintf('no answer from the broker within %s', $this->workerSettings->callTimeout),
                $e,
            );
        } finally {
            $this->pending->forget($call->correlationId);
        }
    }

    /** @throws ActionCallException */
    private function sendRequest(ServiceCallRequest $request): void
    {
        try {
            $this->transport->send($request);
        } catch (Throwable $e) {
            throw $e instanceof TransportException && $e->reason === TransportError::Unencodable
                ? ActionCallException::rejected($request->domain, $request->service, $e->getMessage(), null, $e)
                : ActionCallException::unreachable($request->domain, $request->service, 'the broker is gone: ' . $e->getMessage(), $e);
        }
    }
}
