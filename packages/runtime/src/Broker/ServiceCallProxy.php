<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Psr\Log\LoggerInterface;
use Stewart\Client\HaClient;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;

final readonly class ServiceCallProxy
{
    public function __construct(
        private HaSession $session,
        private HaClient $client,
        private HaCallSlots $slots,
        private ServiceCallPolicy $policy,
        private LoggerInterface $logger,
    ) {}

    public function forward(WorkerHandle $handle, ServiceCallRequest $request): void
    {
        $this->slots->runBounded($handle, $request, fn(): ServiceCallResult => $this->callHomeAssistant($request));
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
