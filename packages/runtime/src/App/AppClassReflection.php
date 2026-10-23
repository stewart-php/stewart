<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use ReflectionClass;
use Stewart\Contracts\App;
use Stewart\Contracts\AppTemplate;
use Stewart\Contracts\Automation;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Exception\AppException;

final readonly class AppClassReflection
{
    /** @param ReflectionClass<object> $reflection */
    public function __construct(private ReflectionClass $reflection) {}

    public static function findForClassName(string $class): ?self
    {
        return class_exists($class) ? new self(new ReflectionClass($class)) : null;
    }

    /** @return class-string */
    public function getName(): string
    {
        return $this->reflection->getName();
    }

    /** @throws AppException */
    public function findAutomation(): ?Automation
    {
        $automation = $this->reflection->getAttributes(Automation::class)[0] ?? null;

        if ($automation !== null && $this->isTemplate()) {
            throw AppException::createForReason(AppError::TemplateAndAutomation, className: $this->getName());
        }

        return $automation?->newInstance();
    }

    public function isTemplate(): bool
    {
        return $this->reflection->getAttributes(AppTemplate::class) !== [];
    }

    public function isRunnableApp(): bool
    {
        return $this->reflection->isInstantiable() && $this->reflection->implementsInterface(App::class);
    }

    /** @throws AppException */
    public function requireRunnableApp(string $attribute): void
    {
        if (!$this->reflection->implementsInterface(App::class)) {
            throw AppException::createForReason(AppError::NotAnApp, className: $this->getName(), attribute: $attribute, appInterface: App::class);
        }

        if (!$this->reflection->isInstantiable()) {
            throw AppException::createForReason(AppError::NotInstantiable, className: $this->getName(), attribute: $attribute);
        }
    }
}
