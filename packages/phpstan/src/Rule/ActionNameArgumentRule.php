<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Stewart\Contracts\Action\ActionName;
use Stewart\Contracts\Entity\Entity;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Entity\TypedEntity;
use Stewart\Contracts\Exception\ActionCallException;
use Stewart\Contracts\HaContext;
use Stewart\PHPStan\Manifest\GeneratedHandles;
use Stewart\PHPStan\Manifest\ManifestCatalog;
use Stewart\PHPStan\Manifest\ManifestCatalogLoader;
use Stewart\Support\Text\ClosestNameFinder;

/** @implements Rule<CallLike> */
final readonly class ActionNameArgumentRule implements Rule
{
    private const string IDENTIFIER = 'stewart.actionName';

    private const array ACTION_METHODS = ['callAction', 'callActionForResponse'];

    public function __construct(
        private ManifestCatalogLoader $manifests,
        private EntityIdLiterals $literals,
        private TestCodeScope $testCode,
        private GeneratedHandles $handles,
        private ClosestNameFinder $names,
    ) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @param Scope&DependencyTracker $scope
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $called = CalledMethod::findForCall($node, $scope);

        if ($called === null || $this->testCode->isInTestCode($scope)) {
            return [];
        }

        foreach (self::ACTION_METHODS as $method) {
            if ($called->isCallTo(HaContext::class, $method)) {
                $catalog = $this->manifests->findCatalogTrackedBy($scope);

                return $catalog === null ? [] : $this->checkActionNames($called, $scope, $catalog);
            }

            if ($called->isCallTo(Entity::class, $method) && $node instanceof MethodCall) {
                $catalog = $this->manifests->findCatalogTrackedBy($scope);

                return $catalog === null ? [] : $this->checkEntityActions($called, $scope, $catalog, $this->findEntityDomain($node->var, $scope));
            }
        }

        return [];
    }

    /** @return list<IdentifierRuleError> */
    private function checkActionNames(CalledMethod $called, Scope $scope, ManifestCatalog $catalog): array
    {
        $errors = [];

        foreach ($this->listLiterals($called, $scope) as $actionName) {
            try {
                new ActionName($actionName);
            } catch (ActionCallException $e) {
                $errors[] = RuleErrorBuilder::message($e->getMessage())->identifier(self::IDENTIFIER)->build();

                continue;
            }

            if (!$catalog->knowsAction($actionName)) {
                $errors[] = $this->buildUnknownActionError($actionName, $catalog);
            }
        }

        return $errors;
    }

    /** @return list<IdentifierRuleError> */
    private function checkEntityActions(CalledMethod $called, Scope $scope, ManifestCatalog $catalog, ?string $domain): array
    {
        $errors = [];

        foreach ($this->listLiterals($called, $scope) as $action) {
            if (str_contains($action, '.')) {
                $errors[] = RuleErrorBuilder::message(\sprintf('Entity::%s() takes the action alone, such as "turn_on", not "%s".', $called->method, $action))
                    ->identifier(self::IDENTIFIER)
                    ->build();
            } elseif ($domain !== null && !$catalog->knowsAction($domain . '.' . $action)) {
                $errors[] = $this->buildUnknownActionError($domain . '.' . $action, $catalog);
            }
        }

        return $errors;
    }

    // Only a receiver that names its entity right there, $ha->getEntity('light.hall') or a generated handle, has a known domain.
    private function findEntityDomain(Expr $receiver, Scope $scope): ?string
    {
        if (!$receiver instanceof MethodCall) {
            return null;
        }

        $called = CalledMethod::findForCall($receiver, $scope);

        if ($called?->isCallTo(HaContext::class, 'getEntity') === true) {
            $entityIds = [];

            foreach ($called->listArgumentValues(0, 'entityId') as $value) {
                $entityIds = [...$entityIds, ...$this->literals->listEntityIds($scope->getType($value))];
            }

            return \count($entityIds) === 1 ? new EntityId($entityIds[0])->domain : null;
        }

        if ($called?->isCallTo(TypedEntity::class, 'getEntity') === true) {
            $handleClasses = $called->callerType->getObjectClassNames();

            return \count($handleClasses) === 1 ? $this->handles->findDomain($handleClasses[0]) : null;
        }

        return null;
    }

    /** @return list<string> */
    private function listLiterals(CalledMethod $called, Scope $scope): array
    {
        $literals = [];

        foreach ($called->listArgumentValues(0, 'action') as $value) {
            foreach ($scope->getType($value)->getConstantStrings() as $string) {
                $literals[] = $string->getValue();
            }
        }

        return $literals;
    }

    private function buildUnknownActionError(string $actionName, ManifestCatalog $catalog): IdentifierRuleError
    {
        $suggestion = $this->names->findClosestName($actionName, $catalog->listActionNames());

        return RuleErrorBuilder::message(\sprintf(
            'Action "%s" was not in Home Assistant at the last stewart generate.%s',
            $actionName,
            $suggestion === null ? '' : \sprintf(' Did you mean "%s"?', $suggestion),
        ))
            ->identifier(self::IDENTIFIER)
            ->tip('Run make generate if the action is new, such as one from a script or an integration added since.')
            ->build();
    }
}
