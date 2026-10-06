<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Request;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppPauseResetOutcome;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Control\Protocol\Frame\ClientFrame;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\ResetAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ServerFrame;
use Stewart\Runtime\Lifecycle\AppPauseOverridePersistence;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Lifecycle\AppStateAfterReset;

/** @implements ControlRequestHandler<ResetAppRequest> */
final readonly class ResetAppRequestHandler implements ControlRequestHandler
{
    public function __construct(private AppPauseService $pauses) {}

    public function handledMessageClass(): string
    {
        return ResetAppRequest::class;
    }

    /** @param ResetAppRequest $request */
    public function answerRequest(ClientFrame $request): ServerFrame
    {
        $appId = new AppId($request->appId);
        $outcome = $this->pauses->resetApp($appId, AppPauseSource::Control);

        return new CommandResult($outcome->overrideRemoved, $this->describeOutcome($appId, $outcome), $this->findWarning($outcome));
    }

    private function findWarning(AppPauseResetOutcome $outcome): ?string
    {
        return $outcome->overrideRemoved || $outcome->persistence !== AppPauseOverridePersistence::NotConfigured ? $outcome->persistence->findWarning() : null;
    }

    private function describeOutcome(AppId $appId, AppPauseResetOutcome $outcome): string
    {
        if (!$outcome->overrideRemoved) {
            return \sprintf('App %s had no pause override.', $appId);
        }

        return \sprintf(match ($outcome->stateAfter) {
            AppStateAfterReset::PausedByConfig => 'App %s pause override removed; config keeps it paused.',
            AppStateAfterReset::Running => 'App %s pause override removed; it is running.',
            AppStateAfterReset::NotLoaded => 'App %s pause override removed.',
        }, $appId);
    }
}
