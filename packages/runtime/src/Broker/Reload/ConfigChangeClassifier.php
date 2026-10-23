<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use Stewart\Runtime\Config\StewartConfig;

final readonly class ConfigChangeClassifier
{
    public const array LIVE_SETTINGS = ['apps', 'workers'];

    // Read once at the start, by the connections, listeners and workers' settings, so a change waits for a restart.
    public const array SETTINGS_NEEDING_RESTART = [
        'logLevel' => 'log_level',
        'logFormat' => 'log_format',
        'shutdownGrace' => 'shutdown_grace',
        'workerEventBuffer' => 'worker_event_buffer',
        'workerStateBatch' => 'worker_state_batch',
        'subscriptionBuffer' => 'subscription_buffer',
        'homeAssistant' => 'home_assistant',
        'reconnectBackoff' => 'reconnect',
        'supervision' => 'supervision',
        'haCalls' => 'ha_calls',
        'persistence' => 'persistence',
        'mqtt' => 'mqtt',
        'control' => 'control',
        'http' => 'http',
        'codegen' => 'codegen',
        'gitDeploy' => 'deploy.git',
        'expose' => 'expose',
    ];

    /** @return list<string> */
    public function listSettingsNeedingRestart(StewartConfig $running, StewartConfig $reloaded): array
    {
        $runningValues = get_object_vars($running);
        $reloadedValues = get_object_vars($reloaded);
        $changed = [];

        foreach (self::SETTINGS_NEEDING_RESTART as $property => $setting) {
            if (serialize($runningValues[$property]) !== serialize($reloadedValues[$property])) {
                $changed[] = $setting;
            }
        }

        return $changed;
    }
}
