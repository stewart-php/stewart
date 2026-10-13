<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config\Environment;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\Environment\EnvironmentValueParser;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Testing\Exception\AssertsReason;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BooleanNode;
use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Config\Definition\FloatNode;
use Symfony\Component\Config\Definition\IntegerNode;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\Config\Definition\ScalarNode;
use Symfony\Component\Config\Definition\VariableNode;

#[CoversClass(EnvironmentValueParser::class)]
final class EnvironmentValueParserTest extends TestCase
{
    use AssertsReason;

    public function testEveryLeafNodeIsSupported(): void
    {
        $parser = new EnvironmentValueParser();
        $nodes = [new BooleanNode('enabled'), new IntegerNode('workers'), new FloatNode('ratio'), new ScalarNode('url'), new EnumNode('level', null, ['debug', 'info']), new VariableNode('option'), self::createScalarList()];

        foreach ($nodes as $node) {
            self::assertTrue($parser->supportsNode($node), $node->getName());
        }
    }

    public function testFloatMustBeNumeric(): void
    {
        $parser = new EnvironmentValueParser();

        self::assertSame(0.5, $parser->parseForNode(new FloatNode('ratio'), 'STEWART_RATIO', ' 0.5 '));

        $this->assertThrowsReason(ConfigurationError::EnvironmentValueInvalid, fn() => $parser->parseForNode(new FloatNode('ratio'), 'STEWART_RATIO', 'half'));
    }

    public function testBooleanTakesTheUsualSpellings(): void
    {
        $parser = new EnvironmentValueParser();

        self::assertTrue($parser->parseForNode(new BooleanNode('enabled'), 'STEWART_X', ' Yes '));
        self::assertFalse($parser->parseForNode(new BooleanNode('enabled'), 'STEWART_X', 'off'));

        $this->assertThrowsReason(ConfigurationError::EnvironmentValueInvalid, fn() => $parser->parseForNode(new BooleanNode('enabled'), 'STEWART_X', 'maybe'));
    }

    public function testIntegerMustBeWhole(): void
    {
        $parser = new EnvironmentValueParser();

        self::assertSame(-3, $parser->parseForNode(new IntegerNode('workers'), 'STEWART_WORKERS', ' -3 '));

        $this->assertThrowsReason(ConfigurationError::EnvironmentValueInvalid, fn() => $parser->parseForNode(new IntegerNode('workers'), 'STEWART_WORKERS', '2.5'));
    }

    public function testScalarAndEnumKeepTheText(): void
    {
        $parser = new EnvironmentValueParser();

        self::assertSame(' ws://ha ', $parser->parseForNode(new ScalarNode('url'), 'STEWART_URL', ' ws://ha '));
        self::assertSame('loud', $parser->parseForNode(new EnumNode('level', null, ['debug', 'info']), 'STEWART_LEVEL', 'loud'));
    }

    public function testVariableNodeInfersTheType(): void
    {
        $parser = new EnvironmentValueParser();
        $node = new VariableNode('options');

        self::assertSame(12, $parser->parseForNode($node, 'STEWART_X', '12'));
        self::assertSame(['a' => 1], $parser->parseForNode($node, 'STEWART_X', '{a: 1}'));
        self::assertNull($parser->parseForNode($node, 'STEWART_X', '~'));
        self::assertSame('hall', $parser->parseForNode($node, 'STEWART_X', ' hall '));
        self::assertSame('yes', $parser->parseForNode($node, 'STEWART_X', 'yes'), 'The app constructor decides whether yes is a bool.');
    }

    public function testVariableNodeKeepsUnparsableText(): void
    {
        self::assertSame('[a-z]+', new EnvironmentValueParser()->parseForNode(new VariableNode('options'), 'STEWART_X', ' [a-z]+ '));
    }

    public function testInlineListIsParsed(): void
    {
        self::assertSame(['a', 'b'], new EnvironmentValueParser()->parseForNode(self::createScalarList(), 'STEWART_X', '[a, b]'));
    }

    public function testInlineListNeedsBrackets(): void
    {
        $this->assertThrowsReason(
            ConfigurationError::EnvironmentValueInvalid,
            fn() => new EnvironmentValueParser()->parseForNode(self::createScalarList(), 'STEWART_X', 'a, b'),
        );
    }

    public function testInlineListRefusesUnparsableList(): void
    {
        $this->assertThrowsReason(
            ConfigurationError::EnvironmentValueUnparsable,
            fn() => new EnvironmentValueParser()->parseForNode(self::createScalarList(), 'STEWART_X', '[a, b'),
        );
    }

    public function testSectionIsNotALeaf(): void
    {
        $parser = new EnvironmentValueParser();

        self::assertFalse($parser->supportsNode(new ArrayNode('home_assistant')));
        self::assertSame('ws://ha', $parser->parseForNode(new ArrayNode('home_assistant'), 'STEWART_HOME_ASSISTANT', 'ws://ha'));
    }

    private static function createScalarList(): PrototypedArrayNode
    {
        $list = new PrototypedArrayNode('include');
        $list->setPrototype(new ScalarNode('entry'));

        return $list;
    }
}
