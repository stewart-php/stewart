<?php

declare(strict_types=1);

namespace Stewart\Testing\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum HarnessError: string implements ExceptionReason
{
    case AlreadyStarted = 'harness_already_started';
    case AutomationMissing = 'harness_automation_missing';
    case TemplateNeedsAppId = 'harness_template_needs_app_id';
    case NotStarted = 'harness_not_started';
    case OptionMissing = 'harness_option_missing';
    case OptionUnknown = 'harness_option_unknown';
    case ParameterUnresolvable = 'harness_parameter_unresolvable';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::AlreadyStarted => 'The harness already runs {class}; use one harness per app.',
            self::AutomationMissing => '{class} has neither #[Automation] nor #[AppTemplate], so it is not an app.',
            self::TemplateNeedsAppId => '{class} is an #[AppTemplate]; pass the ID of the instance to test as startApp(appId: \'…\').',
            self::NotStarted => 'No app is running; call startApp() first.',
            self::OptionMissing => '{class}::__construct() needs option "{option}"; pass it to startApp().',
            self::OptionUnknown => 'Option "{option}" matches no parameter of {class}::__construct().',
            self::ParameterUnresolvable => '{class}::__construct() takes ${parameter} as {type}, which the harness has no fake for; pass one with provideService().',
        };
    }
}
