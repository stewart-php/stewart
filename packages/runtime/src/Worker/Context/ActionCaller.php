<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Context;

use Amp\CancelledException;
use Stewart\Contracts\Action\ActionName;
use Stewart\Contracts\Action\ActionResponse;
use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\Exception\ActionCallException;
use Stewart\Contracts\State\EventContext;
use Stewart\Runtime\Exception\TransportError;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\ActionCallRequest;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Ipc\WorkerSettings;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Support\Time\Deadlines;
use Stewart\Support\Wire\ActionFields;
use Throwable;

final readonly class ActionCaller
{
    public function __construct(
        private Transport $transport,
        private PendingRequests $pending,
        private ConnectionStatus $connection,
        private Deadlines $deadlines,
        private WorkerSettings $workerSettings,
    ) {}

    /** @throws ActionCallException */
    public function callAction(ResourceScope $scope, ActionName $action, ActionFields $data, ?ActionTarget $target): EventContext
    {
        return $this->sendRequestAndAwait($scope, $action, $data, $target, returnResponse: false)->context ?? EventContext::unknown();
    }

    /** @throws ActionCallException */
    public function callActionForResponse(ResourceScope $scope, ActionName $action, ActionFields $data, ?ActionTarget $target): ActionResponse
    {
        return $this->sendRequestAndAwait($scope, $action, $data, $target, returnResponse: true);
    }

    /** @throws ActionCallException */
    private function sendRequestAndAwait(
        ResourceScope $scope,
        ActionName $action,
        ActionFields $data,
        ?ActionTarget $target,
        bool $returnResponse,
    ): ActionResponse {
        if (!$this->connection->connected) {
            throw ActionCallException::unreachable($action->domain, $action->action, 'Home Assistant is disconnected');
        }

        $call = $this->pending->open(
            ActionResponse::class,
            static fn(string $detail): ActionCallException => ActionCallException::unreachable($action->domain, $action->action, $detail),
        );

        try {
            $this->sendRequest(new ActionCallRequest(
                correlationId: $call->correlationId,
                scope: $scope,
                domain: $action->domain,
                action: $action->action,
                data: $data->fields,
                target: $target,
                returnResponse: $returnResponse,
            ));

            return $call->getFuture()->await($this->deadlines->timeout($this->workerSettings->callTimeout));
        } catch (CancelledException $e) {
            throw ActionCallException::timedOut(
                $action->domain,
                $action->action,
                \sprintf('no answer from the broker within %s', $this->workerSettings->callTimeout),
                $e,
            );
        } finally {
            $this->pending->forget($call->correlationId);
        }
    }

    /** @throws ActionCallException */
    private function sendRequest(ActionCallRequest $request): void
    {
        try {
            $this->transport->send($request);
        } catch (Throwable $e) {
            throw $e instanceof TransportException && $e->reason === TransportError::Unencodable
                ? ActionCallException::rejected($request->domain, $request->action, $e->getMessage(), null, $e)
                : ActionCallException::unreachable($request->domain, $request->action, 'the broker is gone: ' . $e->getMessage(), $e);
        }
    }
}
