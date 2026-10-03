<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use Stewart\Contracts\Collection\TypedCollection;

/** @implements Rule<Node\Stmt> */
final readonly class TypedCollectionRule implements Rule
{
    private const string FRAMEWORK_SOURCE = '#/packages/[^/]+/src/#';

    private const string STEWART_NAMESPACE = 'Stewart\\';

    private const string WIRE_LIST_ATTRIBUTE = 'ListOf';

    public function __construct(private ReflectionProvider $reflection, private FileTypeMapper $phpDocs) {}

    public function getNodeType(): string
    {
        return Node\Stmt::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof ClassMethod && !$node instanceof Property) {
            return [];
        }

        $docComment = $node->getDocComment();

        // Private state stays inside one object; only what travels between objects needs a collection.
        if ($docComment === null || $node->isPrivate() || preg_match(self::FRAMEWORK_SOURCE, $scope->getFile()) !== 1 || ($node instanceof Property && $this->hasWireListAttribute($node->attrGroups))) {
            return [];
        }

        $phpDoc = $this->phpDocs->getResolvedPhpDoc(
            $scope->getFile(),
            $scope->getClassReflection()?->getName(),
            $scope->getTraitReflection()?->getName(),
            $node instanceof ClassMethod ? $node->name->toString() : null,
            $docComment->getText(),
        );
        $types = [];

        foreach ($phpDoc->getParamTags() as $parameter => $tag) {
            if (!$node instanceof ClassMethod || !$this->isWireListParameter($node, $parameter)) {
                $types[] = $tag->getType();
            }
        }

        foreach ([$phpDoc->getReturnTag(), ...array_values($phpDoc->getVarTags())] as $tag) {
            if ($tag !== null) {
                $types[] = $tag->getType();
            }
        }

        $errors = [];

        foreach ($types as $type) {
            if ($this->holdsStewartObjects($type)) {
                $errors[] = RuleErrorBuilder::message(\sprintf('%s travels as an array; give its element a Collection.', $type->describe(VerbosityLevel::typeOnly())))
                    ->identifier('stewart.untypedCollection')
                    ->build();
            }
        }

        return $errors;
    }

    private function holdsStewartObjects(Type $type): bool
    {
        foreach ($type->getArrays() as $array) {
            $item = $array->getItemType();

            if ($this->holdsStewartObjects($item)) {
                return true;
            }

            if ($item instanceof TemplateType) {
                continue;
            }

            foreach ($item->getObjectClassNames() as $class) {
                if ($this->isStewartObject($class)) {
                    return true;
                }
            }
        }

        return false;
    }

    // Enums are values, and a map of collections is already typed (a grouping).
    private function isStewartObject(string $class): bool
    {
        if (!str_starts_with($class, self::STEWART_NAMESPACE) || !$this->reflection->hasClass($class)) {
            return false;
        }

        $reflection = $this->reflection->getClass($class);

        return !$reflection->isEnum() && !$reflection->isSubclassOfClass($this->reflection->getClass(TypedCollection::class));
    }

    private function isWireListParameter(ClassMethod $method, string $parameter): bool
    {
        foreach ($method->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $parameter) {
                return $this->hasWireListAttribute($param->attrGroups);
            }
        }

        return false;
    }

    /** @param array<Node\AttributeGroup> $attributeGroups */
    private function hasWireListAttribute(array $attributeGroups): bool
    {
        foreach ($attributeGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->getLast() === self::WIRE_LIST_ATTRIBUTE) {
                    return true;
                }
            }
        }

        return false;
    }
}
