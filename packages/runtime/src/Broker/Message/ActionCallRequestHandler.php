<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Client\HaClient;
use Stewart\Contracts\Action\ActionResponse;
use Stewart\Contracts\Exception\ActionCallException;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Broker\ActionCallTracer;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Config\HaCallPolicy;
use Stewart\Runtime\Ipc\Message\ActionCallRequest;
use Stewart\Runtime\Ipc\Message\ActionCallResult;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Stewart\Runtime\Logging\LogOrigin;
use Throwable;

/** @implements WorkerMessageHandler<ActionCallRequest> */
final readonly class ActionCallRequestHandler implements WorkerMessageHandler
{
    public function __construct(
        private HaSession $session,
        private HaClient $client,
        private HaCallSlots $slots,
        private HaCallPolicy $policy,
        private ActionCallTracer $tracer,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function handledMessageClass(): string
    {
        return ActionCallRequest::class;
    }

    /** @param ActionCallRequest $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->slots->runBounded($handle, $message, fn(): ActionCallResult => $this->callTracingHomeAssistant($message));
    }

    /** @throws ActionCallException */
    private function callTracingHomeAssistant(ActionCallRequest $request): ActionCallResult
    {
        $this->tracer->traceCallSent($request);
        $sentAt = $this->clock->getMonotonicTime();

        try {
            $result = $this->callHomeAssistant($request);
        } catch (Throwable $e) {
            $this->tracer->traceCallAnswered($request, $this->clock->getMonotonicTime()->elapsedSince($sentAt), $e);

            throw $e;
        }

        $this->tracer->traceCallAnswered($request, $this->clock->getMonotonicTime()->elapsedSince($sentAt));

        return $result;
    }

    /** @throws ActionCallException */
    private function callHomeAssistant(ActionCallRequest $request): ActionCallResult
    {
        if ($this->policy->dryRun) {
            $this->logger->info('Action call not sent: ha_calls.dry_run is on', [
                'action' => $request->domain . '.' . $request->action,
                'target' => $request->target,
                'data' => $request->data,
                LogOrigin::CONTEXT_KEY => LogOrigin::forScope($request->scope),
            ]);

            return new ActionCallResult(
                $request->correlationId,
                new ActionResponse($request->domain, $request->action, context: $this->policy->createDryRunContext($request->correlationId)),
            );
        }

        if (!$this->session->isConnected()) {
            throw $request->createUnreachableFailure('Home Assistant is disconnected');
        }

        return new ActionCallResult($request->correlationId, $this->client->callService(
            $request->domain,
            $request->action,
            $request->data,
            $request->target,
            $request->returnResponse,
        ));
    }
}
