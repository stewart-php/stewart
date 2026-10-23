<?php

declare(strict_types=1);

namespace Stewart\Codegen\Emitter\Action;

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Method;
use Stewart\Codegen\Action\ActionModel;
use Stewart\Codegen\Php\MemberScope;
use Stewart\Codegen\Php\ReservesMemberNames;
use Stewart\Contracts\Action\ActionResponse;
use Stewart\Contracts\Action\ActionTargetSource;
use Stewart\Contracts\State\EventContext;

final readonly class ActionMethodEmitter implements ReservesMemberNames
{
    private const string TARGET_PARAMETER = 'target';

    private const array RESERVED_PARAMETER_NAMES = ['this'];

    private const array RESERVED_TARGETED_PARAMETER_NAMES = [...self::RESERVED_PARAMETER_NAMES, self::TARGET_PARAMETER];

    public function addHandleMethod(ClassType $class, ActionModel $action): void
    {
        $method = $this->declareMethod($class, $action);
        $this->addFieldParameters($method, $action);

        $method->setBody(\sprintf(
            'return $this->entity->%s(%s%s);',
            $action->definition->returnsResponse ? 'callActionForResponse' : 'callAction',
            var_export($action->definition->name, true),
            $action->fields === [] ? '' : ', ' . $this->buildPayload($action),
        ));
    }

    public function addDomainActionMethod(ClassType $class, ActionModel $action): void
    {
        $method = $this->declareMethod($class, $action);

        if ($action->definition->target->isTargetable) {
            $method->addParameter(self::TARGET_PARAMETER)->setType(ActionTargetSource::class);
        }

        $this->addFieldParameters($method, $action);

        $method->setBody(\sprintf(
            'return $this->ha->%s(%s, %s%s);',
            $action->definition->returnsResponse ? 'callActionForResponse' : 'callAction',
            var_export($action->definition->domain . '.' . $action->definition->name, true),
            $action->fields === [] ? '[]' : $this->buildPayload($action),
            $action->definition->target->isTargetable ? ', $' . self::TARGET_PARAMETER : '',
        ));
    }

    private function declareMethod(ClassType $class, ActionModel $action): Method
    {
        $method = $class->addMethod($action->method)
            ->setReturnType($action->definition->returnsResponse ? ActionResponse::class : EventContext::class);

        foreach ($this->buildDocblock($action) as $line) {
            $method->addComment($line);
        }

        return $method;
    }

    private function addFieldParameters(Method $method, ActionModel $action): void
    {
        foreach ($action->fields as $field) {
            $parameter = $method->addParameter($field->parameter)->setType($field->type()->native);

            if (!$field->field->required) {
                $parameter->setDefaultValue(null);
            }
        }
    }

    /** @return list<string> */
    private function buildDocblock(ActionModel $action): array
    {
        $title = $action->definition->title;
        $description = $action->definition->description;
        $lines = $title === null ? [] : [$title];

        if ($description !== null) {
            $lines = [...$lines, ...($lines === [] ? [] : ['']), $description];
        }

        $params = [];

        foreach ($action->fields as $field) {
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

    private function buildPayload(ActionModel $action): string
    {
        $entries = '';

        foreach ($action->fields as $field) {
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
