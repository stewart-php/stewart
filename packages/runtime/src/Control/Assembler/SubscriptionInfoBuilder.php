<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Control\Protocol\Status\RegistrationInfo;
use Stewart\Runtime\Ipc\Message\SubscriptionActivityReport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;

final readonly class SubscriptionInfoBuilder
{
    public function __construct(
        private WorkerSlotRegistry $slots,
        private SubscriptionRegistry $registry,
    ) {}

    /** @return list<RegistrationInfo> */
    public function buildSubscriptionInfos(): array
    {
        $infos = [];

        foreach ($this->slots->listLiveHandles() as $handle) {
            foreach ($handle->lastPong->apps ?? [] as $app) {
                foreach ($app->subscriptionReports as $report) {
                    $infos[$report->subscriptionId->value] = $this->buildReportedInfo($handle->id, $app->scope, $report);
                }
            }
        }

        // A worker reports its subscriptions with its first probe; until then only the ones the broker routes are known.
        foreach ($this->registry->listSubscriptions() as $subscription) {
            $infos[$subscription->subscriptionId->value] ??= $this->buildRoutedInfo($subscription);
        }

        usort($infos, static fn(RegistrationInfo $a, RegistrationInfo $b): int => $a->workerId <=> $b->workerId ?: strnatcmp($a->subscriptionId, $b->subscriptionId));

        return $infos;
    }

    private function buildReportedInfo(WorkerId $workerId, ResourceScope $scope, SubscriptionActivityReport $report): RegistrationInfo
    {
        return new RegistrationInfo(
            subscriptionId: $report->subscriptionId->value,
            workerId: $workerId->value,
            appId: $scope->wireValue(),
            kind: $report->kind,
            selector: $report->match,
            exact: $report->exact,
            received: $report->received,
            handled: $report->handled,
            dropped: $report->dropped,
            lastReceivedAt: $report->lastReceivedAt,
            lastHandledAt: $report->lastHandledAt,
        );
    }

    private function buildRoutedInfo(BrokerSubscription $subscription): RegistrationInfo
    {
        return new RegistrationInfo(
            subscriptionId: $subscription->subscriptionId->value,
            workerId: $subscription->workerId->value,
            appId: $subscription->scope->wireValue(),
            kind: $subscription->kind,
            selector: $subscription->selector->toCanonicalKey(),
            exact: $subscription->selector->findExactPattern() !== null,
        );
    }
}
