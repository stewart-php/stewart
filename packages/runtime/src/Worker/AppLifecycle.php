<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Amp\Cancellation;
use Amp\CancelledException;
use LogicException;
use Stewart\Contracts\App;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Container\AppContainerBuilder;
use Stewart\Runtime\Container\AppRuntimeServices;
use Stewart\Runtime\Container\TypedContainer;
use Stewart\Runtime\Exception\AppException;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Lifecycle\AppLifecyclePhase;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Worker\Collection\AppSlotCollection;
use Throwable;

use function Amp\async;
use function Amp\Future\await;

final class AppLifecycle
{
    private readonly AppSlotCollection $slots;

    private AppLifecyclePhase $phase = AppLifecyclePhase::Starting;

    public function __construct(
        private readonly Bootstrap $bootstrap,
        private readonly AppRuntimeServices $runtime,
        private readonly AppResources $resources,
        private readonly AppFailureReporter $failures,
        private readonly AppContainerBuilder $containers,
    ) {
        $this->slots = AppSlotCollection::fromSlots(
            $this->bootstrap->apps->collection->mapToList(static fn(WorkerApp $definition): AppSlot => new AppSlot($definition)),
        );
    }

    public function constructApps(): void
    {
        try {
            $build = $this->containers->buildAppContainer(
                apps: $this->bootstrap->apps->collection,
                runtime: $this->runtime,
                servicesFile: $this->bootstrap->settings->servicesFile,
                workerId: $this->bootstrap->workerId,
            );
        } catch (StewartException $e) {
            foreach ($this->slots as $slot) {
                $this->failApp($slot, AppFailurePhase::Construct, $e);
            }

            return;
        }

        foreach ($this->slots as $slot) {
            $failure = $build->failures->find($slot->id());

            if ($failure !== null) {
                $this->failApp($slot, AppFailurePhase::Construct, $failure->error);
            } elseif ($slot->isInState(AppState::Declared)) {
                $this->constructApp($build->container, $slot);
            }
        }
    }

    public function initializeApps(): void
    {
        $attempts = [];

        foreach ($this->slots as $slot) {
            if ($slot->isInState(AppState::Declared) || $slot->isInState(AppState::Constructing)) {
                throw new LogicException(\sprintf('App "%s" is still being constructed.', $slot->id()));
            }

            if (!$slot->isInState(AppState::Constructed)) {
                continue;
            }

            if ($this->isStopRequested()) {
                $slot->moveTo(AppState::Skipped);

                continue;
            }

            $slot->moveTo(AppState::Initializing);
            $attempts[] = async(fn() => $this->initializeApp($slot));
        }

        await($attempts);

        if (!$this->isStopRequested()) {
            $this->resources->activateScope(ResourceScope::shared());
        }
    }

    public function listSlots(): AppSlotCollection
    {
        return $this->slots;
    }

    public function listAppIdsInState(AppState $state): AppIdCollection
    {
        return AppIdCollection::fromIds($this->slots
            ->filter(static fn(AppSlot $slot): bool => $slot->isInState($state))
            ->mapToList(static fn(AppSlot $slot) => $slot->id()));
    }

    public function markStopping(): void
    {
        $this->moveToPhase(AppLifecyclePhase::Stopping);
    }

    /** @throws CancelledException */
    public function disposeAppsBefore(Cancellation $deadline): void
    {
        $disposals = [];

        foreach ($this->slots as $slot) {
            if ($slot->needsDisposal()) {
                $disposals[] = async(fn() => $this->disposeApp($slot));
            }
        }

        await($disposals, $deadline);
    }

    public function abandonUnfinishedApps(): AppIdCollection
    {
        $this->moveToPhase(AppLifecyclePhase::Closed);

        $abandoned = $this->slots->filter(static fn(AppSlot $slot): bool => !$slot->state->isTerminal());

        foreach ($abandoned as $slot) {
            $slot->moveTo(AppState::Abandoned);
        }

        return AppIdCollection::fromIds($abandoned->mapToList(static fn(AppSlot $slot) => $slot->id()));
    }

    private function isStopRequested(): bool
    {
        return $this->phase !== AppLifecyclePhase::Starting;
    }

    private function moveToPhase(AppLifecyclePhase $phase): void
    {
        if (!$this->phase->canEnter($phase)) {
            throw new LogicException(\sprintf('App lifecycle cannot move from %s to %s.', $this->phase->name, $phase->name));
        }

        $this->phase = $phase;
    }

    private function constructApp(TypedContainer $services, AppSlot $slot): void
    {
        if ($this->isStopRequested()) {
            $slot->moveTo(AppState::Skipped);

            return;
        }

        $slot->moveTo(AppState::Constructing);

        try {
            $app = $services->resolveService(App::class, AppContainerBuilder::buildAppServiceId($slot->id()));
        } catch (Throwable $e) {
            $this->failApp($slot, AppFailurePhase::Construct, $e);

            return;
        }

        if (!$slot->isInState(AppState::Abandoned)) {
            $slot->markConstructed($app);
        }
    }

    private function initializeApp(AppSlot $slot): void
    {
        $attempt = async(function () use ($slot): void {
            $slot->app()->initialize();

            if ($slot->isInState(AppState::Failed)) {
                // initialize() finished after the timeout failed it; dispose so it releases what it opened.
                $this->disposeApp($slot);
            }
        });

        $timeout = $this->bootstrap->settings->initializeTimeout;
        $deadline = $timeout === null ? null : $this->runtime->deadlines->timeout($timeout);

        try {
            $attempt->await($deadline);
        } catch (Throwable $e) {
            if ($e instanceof CancelledException && $timeout !== null && $deadline?->isRequested() === true) {
                $attempt->ignore();
                $e = AppException::initializeTimedOut($slot->id(), $timeout, $e);
            }

            $this->failApp($slot, AppFailurePhase::Initialize, $e);

            return;
        }

        if ($slot->isInState(AppState::Abandoned)) {
            return;
        }

        if ($this->isStopRequested()) {
            $slot->moveTo(AppState::Initialized);
            $this->disposeApp($slot);

            return;
        }

        $slot->moveTo(AppState::Running);
        $this->resources->activateScope($slot->scope());
    }

    private function disposeApp(AppSlot $slot): void
    {
        if (!$slot->claimDisposal()) {
            return;
        }

        $this->resources->releaseScope($slot->scope());

        try {
            $slot->app()->dispose();
        } catch (Throwable $e) {
            $this->failures->report($slot->scope(), AppFailurePhase::Dispose, $e);
        }

        $slot->markDisposed();
    }

    private function failApp(AppSlot $slot, AppFailurePhase $phase, Throwable $error): void
    {
        if ($slot->isInState(AppState::Abandoned)) {
            return;
        }

        $slot->moveTo(AppState::Failed);
        $this->resources->releaseScope($slot->scope());
        $this->failures->report($slot->scope(), $phase, $error);
    }
}
