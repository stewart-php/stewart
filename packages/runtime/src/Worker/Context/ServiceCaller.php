<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Context;

use Amp\CancelledException;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Service\ServiceFields;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\TransportError;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\PendingCalls;
use Stewart\Support\Time\Deadlines;
use Throwable;

final readonly class ServiceCaller
{
    public function __construct(
        private Transport $transport,
        private PendingCalls $pending,
        private ConnectionStatus $connection,
        private Deadlines $deadlines,
        private Duration $workerCallTimeout,
    ) {}

    /** @throws ServiceCallException */
    public function callService(ResourceScope $scope, string $domain, string $service, ServiceFields $data, ?ServiceTarget $target): EventContext
    {
        return $this->sendRequestAndAwait($scope, $domain, $service, $data, $target, returnResponse: false)->context ?? EventContext::unknown();
    }

    /** @throws ServiceCallException */
    public function callServiceForResponse(ResourceScope $scope, string $domain, string $service, ServiceFields $data, ?ServiceTarget $target): ServiceResponse
    {
        return $this->sendRequestAndAwait($scope, $domain, $service, $data, $target, returnResponse: true);
    }

    /** @throws ServiceCallException */
    private function sendRequestAndAwait(
        ResourceScope $scope,
        string $domain,
        string $service,
        ServiceFields $data,
        ?ServiceTarget $target,
        bool $returnResponse,
    ): ServiceResponse {
        if (!$this->connection->connected) {
            throw ServiceCallException::unreachable($domain, $service, 'Home Assistant is disconnected');
        }

        $call = $this->pending->open($domain, $service);

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

            return $call->getFuture()->await($this->deadlines->timeout($this->workerCallTimeout));
        } catch (CancelledException $e) {
            throw ServiceCallException::timedOut(
                $domain,
                $service,
                \sprintf('no answer from the broker within %s', $this->workerCallTimeout),
                $e,
            );
        } finally {
            $this->pending->forget($call->correlationId);
        }
    }

    /** @throws ServiceCallException */
    private function sendRequest(ServiceCallRequest $request): void
    {
        try {
            $this->transport->send($request);
        } catch (Throwable $e) {
            throw $e instanceof TransportException && $e->reason === TransportError::Unencodable
                ? ServiceCallException::rejected($request->domain, $request->service, $e->getMessage(), null, $e)
                : ServiceCallException::unreachable($request->domain, $request->service, 'the broker is gone: ' . $e->getMessage(), $e);
        }
    }
}
