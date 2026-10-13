<?php

declare(strict_types=1);

// What a user can do about a failure, keyed by reason enum and case; the errors reference shows it next to the message.
return [
    'ConfigurationError::EnvironmentVariableUnknown' => 'Fix the variable name; keys are joined by two underscores.',
    'ContainerError::AppOptionUnknown' => 'Rename the option in `stewart.yaml` to match a constructor parameter.',
    'ContainerError::AppOptionMissing' => 'Set the option, or give the parameter a default.',
    'ExposureError::ComponentMissing' => 'Install the Stewart integration in Home Assistant and restart it.',
    'ExposureError::ProtocolMismatch' => 'Update Stewart or the integration so both have the same version.',
    'HaClientError::AdministratorRequired' => 'Create the token from a Home Assistant administrator.',
    'IdentifierError::EntityNotGenerated' => 'Fix the ID, or run `make generate` for a new entity.',
    'MqttError::NotConfigured' => 'Set `STEWART_MQTT__URL`.',
    'ScheduleError::SunLocationUnknown' => 'Set a location in Home Assistant under Settings → System → General, then restart Stewart.',
    'ServiceCallError::Rejected' => 'Try the same call under Developer tools → Actions in Home Assistant.',
    'ServiceCallError::Overloaded' => 'Call fewer services at once, for example one call with several targets.',
    'StoreError::NotConfigured' => 'Set `STEWART_PERSISTENCE__URL`.',
    'StoreError::TypeMismatch' => 'Read the key with the type it was written with, or delete it.',
    'StoreError::ValueRejected' => 'Make `fromStorage()` accept the stored shape, or delete the key.',
];
