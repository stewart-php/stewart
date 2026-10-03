<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter\Service;

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Method;
use Stewart\Codegen\Php\MemberScope;
use Stewart\Codegen\Php\ReservesMemberNames;
use Stewart\Codegen\Service\ServiceModel;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTargetSource;

final readonly class ServiceMethodEmitter implements ReservesMemberNames
{
    private const string TARGET_PARAMETER = 'target';

    private const array RESERVED_PARAMETER_NAMES = ['this'];

    private const array RESERVED_TARGETED_PARAMETER_NAMES = [...self::RESERVED_PARAMETER_NAMES, self::TARGET_PARAMETER];

    public function addHandleMethod(ClassType $class, ServiceModel $service): void
    {
        $method = $this->declareMethod($class, $service);
        $this->addFieldParameters($method, $service);

        $method->setBody(\sprintf(
            '%s$this->entity->%s(%s%s);',
            $service->definition->returnsResponse ? 'return ' : '',
            $service->definition->returnsResponse ? 'callServiceForResponse' : 'callService',
            var_export($service->definition->name, true),
            $service->fields->isEmpty() ? '' : ', ' . $this->buildPayload($service),
        ));
    }

    public function addDomainServiceMethod(ClassType $class, ServiceModel $service): void
    {
        $method = $this->declareMethod($class, $service);

        if ($service->definition->target->isTargetable) {
            $method->addParameter(self::TARGET_PARAMETER)->setType(ServiceTargetSource::class);
        }

        $this->addFieldParameters($method, $service);

        $method->setBody(\sprintf(
            '%s$this->ha->%s(%s, %s, %s%s);',
            $service->definition->returnsResponse ? 'return ' : '',
            $service->definition->returnsResponse ? 'callServiceForResponse' : 'callService',
            var_export($service->definition->domain, true),
            var_export($service->definition->name, true),
            $service->fields->isEmpty() ? '[]' : $this->buildPayload($service),
            $service->definition->target->isTargetable ? ', $' . self::TARGET_PARAMETER : '',
        ));
    }

    private function declareMethod(ClassType $class, ServiceModel $service): Method
    {
        $method = $class->addMethod($service->method)
            ->setReturnType($service->definition->returnsResponse ? ServiceResponse::class : 'void');

        foreach ($this->buildDocblock($service) as $line) {
            $method->addComment($line);
        }

        return $method;
    }

    private function addFieldParameters(Method $method, ServiceModel $service): void
    {
        foreach ($service->fields as $field) {
            $parameter = $method->addParameter($field->parameter)->setType($field->type()->native);

            if (!$field->field->required) {
                $parameter->setDefaultValue(null);
            }
        }
    }

    /** @return list<string> */
    private function buildDocblock(ServiceModel $service): array
    {
        $title = $service->definition->title;
        $description = $service->definition->description;
        $lines = $title === null ? [] : [$title];

        if ($description !== null) {
            $lines = [...$lines, ...($lines === [] ? [] : ['']), $description];
        }

        $params = [];

        foreach ($service->fields as $field) {
            $summary = $field->summary();

            if (!$field->type()->needsDocblock() && $summary === '') {
                continue;
            }

            $params[] = rtrim(\sprintf('@param %s $%s %s', $field->type()->docblock, $field->parameter, $summary));
        }

        if ($lines !== [] && $params !== []) {
            $lines[] = '';
        }

        return [...$lines, ...$params];
    }

    private function buildPayload(ServiceModel $service): string
    {
        $entries = '';

        foreach ($service->fields as $field) {
            $entries .= \sprintf("    %s => $%s,\n", var_export($field->field->name, true), $field->parameter);
        }

        return \sprintf("[\n%s]", $entries);
    }

    public function listReservedMemberNames(MemberScope $scope): array
    {
        return match ($scope) {
            MemberScope::Parameters => self::RESERVED_PARAMETER_NAMES,
            MemberScope::TargetedParameters => self::RESERVED_TARGETED_PARAMETER_NAMES,
            default => [],
        };
    }
}
