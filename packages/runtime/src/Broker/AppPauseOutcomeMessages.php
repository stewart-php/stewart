<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppPauseOutcome;
use Stewart\Runtime\App\AppPauseResetOutcome;
use Stewart\Runtime\Lifecycle\AppPauseOverridePersistence;
use Stewart\Runtime\Lifecycle\AppStateAfterReset;

final readonly class AppPauseOutcomeMessages
{
    public function describePauseOutcome(AppId $appId, AppPauseOutcome $outcome): string
    {
        return \sprintf($outcome->changed ? 'App %s paused.' : 'App %s was already paused.', $appId);
    }

    public function describeResumeOutcome(AppId $appId, AppPauseOutcome $outcome): string
    {
        return \sprintf($outcome->changed ? 'App %s resumed.' : 'App %s was not paused.', $appId);
    }

    public function findChangeWarning(AppPauseOutcome $outcome): ?string
    {
        return $outcome->persistence->findWarning();
    }

    public function describeResetOutcome(AppId $appId, AppPauseResetOutcome $outcome): string
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

    public function findResetWarning(AppPauseResetOutcome $outcome): ?string
    {
        return $outcome->overrideRemoved || $outcome->persistence !== AppPauseOverridePersistence::NotConfigured ? $outcome->persistence->findWarning() : null;
    }
}
