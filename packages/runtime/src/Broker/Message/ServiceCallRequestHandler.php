<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Psr\Log\LoggerInterface;
use Stewart\Client\HaClient;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<ServiceCallRequest> */
final readonly class ServiceCallRequestHandler implements WorkerMessageHandler
{
    public function __construct(
        private HaSession $session,
        private HaClient $client,
        private HaCallSlots $slots,
        private ServiceCallPolicy $policy,
        private LoggerInterface $logger,
    ) {}

    public function handledMessageClass(): string
    {
        return ServiceCallRequest::class;
    }

    /** @param ServiceCallRequest $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->slots->runBounded($handle, $message, fn(): ServiceCallResult => $this->callHomeAssistant($message));
    }

    /** @throws ServiceCallException */
    private function callHomeAssistant(ServiceCallRequest $request): ServiceCallResult
    {
        if ($this->policy->dryRun) {
            $this->logger->info('Service call not sent: service_calls.dry_run is on', [
                'domain' => $request->domain,
                'service' => $request->service,
                'target' => $request->target,
                'data' => $request->data,
            ]);

            return new ServiceCallResult(
                $request->correlationId,
                new ServiceResponse($request->domain, $request->service, context: $this->policy->createDryRunContext($request->correlationId)),
            );
        }

        if (!$this->session->isConnected()) {
            throw $request->createUnreachableFailure('Home Assistant is disconnected');
        }

        return new ServiceCallResult($request->correlationId, $this->client->callService(
            $request->domain,
            $request->service,
            $request->data,
            $request->target,
            $request->returnResponse,
        ));
    }
}
