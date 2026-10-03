<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Config\Environment;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\Environment\BooleanLeafParser;
use Stewart\Runtime\Config\Environment\FloatLeafParser;
use Stewart\Runtime\Config\Environment\InlineListLeafParser;
use Stewart\Runtime\Config\Environment\InlineValueInference;
use Stewart\Runtime\Config\Environment\IntegerLeafParser;
use Stewart\Runtime\Config\Environment\LeafParser;
use Stewart\Runtime\Config\Environment\LeafParserChain;
use Stewart\Runtime\Config\Environment\ScalarLeafParser;
use Stewart\Runtime\Config\Environment\VariableLeafParser;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Testing\Exception\AssertsReason;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BooleanNode;
use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Config\Definition\FloatNode;
use Symfony\Component\Config\Definition\IntegerNode;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\Config\Definition\ScalarNode;
use Symfony\Component\Config\Definition\VariableNode;

#[CoversClass(BooleanLeafParser::class)]
#[CoversClass(IntegerLeafParser::class)]
#[CoversClass(FloatLeafParser::class)]
#[CoversClass(ScalarLeafParser::class)]
#[CoversClass(VariableLeafParser::class)]
#[CoversClass(InlineListLeafParser::class)]
#[CoversClass(InlineValueInference::class)]
#[CoversClass(LeafParserChain::class)]
final class LeafParsersTest extends TestCase
{
    use AssertsReason;

    public function testEachNodeHasExactlyOneParser(): void
    {
        $parsers = [new BooleanLeafParser(), new IntegerLeafParser(), new FloatLeafParser(), new ScalarLeafParser(), new VariableLeafParser(new InlineValueInference())];
        $nodes = [new BooleanNode('enabled'), new IntegerNode('workers'), new FloatNode('ratio'), new ScalarNode('url'), new EnumNode('level', null, ['debug', 'info']), new VariableNode('option')];

        foreach ($nodes as $node) {
            self::assertCount(1, array_filter($parsers, static fn(LeafParser $parser): bool => $parser->supports($node)), $node->getName());
        }
    }

    public function testFloatMustBeNumeric(): void
    {
        self::assertSame(0.5, new FloatLeafParser()->parse('STEWART_RATIO', ' 0.5 '));

        $this->assertThrowsReason(ConfigurationError::EnvironmentValueInvalid, fn() => new FloatLeafParser()->parse('STEWART_RATIO', 'half'));
    }

    public function testBooleanTakesTheUsualSpellings(): void
    {
        $parser = new BooleanLeafParser();

        self::assertTrue($parser->parse('STEWART_X', ' Yes '));
        self::assertFalse($parser->parse('STEWART_X', 'off'));
    }

    public function testIntegerMustBeWhole(): void
    {
        self::assertSame(-3, new IntegerLeafParser()->parse('STEWART_WORKERS', ' -3 '));

        $this->assertThrowsReason(ConfigurationError::EnvironmentValueInvalid, fn() => new IntegerLeafParser()->parse('STEWART_WORKERS', '2.5'));
    }

    public function testVariableNodeInfersTheType(): void
    {
        $parser = new VariableLeafParser(new InlineValueInference());

        self::assertTrue($parser->supports(new VariableNode('options')));
        self::assertSame(12, $parser->parse('STEWART_X', '12'));
        self::assertSame(['a' => 1], $parser->parse('STEWART_X', '{a: 1}'));
        self::assertNull($parser->parse('STEWART_X', '~'));
        self::assertSame('hall', $parser->parse('STEWART_X', ' hall '));
        self::assertSame('yes', $parser->parse('STEWART_X', 'yes'), 'The app constructor decides whether yes is a bool.');
    }

    public function testVariableNodeKeepsUnparsableText(): void
    {
        self::assertSame('[a-z]+', new VariableLeafParser(new InlineValueInference())->parse('STEWART_X', ' [a-z]+ '));
    }

    public function testListParserRefusesUnparsableList(): void
    {
        $this->assertThrowsReason(
            ConfigurationError::EnvironmentValueUnparsable,
            fn() => new InlineListLeafParser(new InlineValueInference())->parse('STEWART_X', '[a, b'),
        );
    }

    public function testInlineListNeedsBrackets(): void
    {
        $parser = new InlineListLeafParser(new InlineValueInference());
        $list = new PrototypedArrayNode('include');
        $list->setPrototype(new ScalarNode('entry'));

        self::assertTrue($parser->supports($list));
        self::assertSame(['a', 'b'], $parser->parse('STEWART_X', '[a, b]'));

        $this->expectException(ConfigurationException::class);

        $parser->parse('STEWART_X', 'a, b');
    }

    public function testChainParsesWithTheMatchingParser(): void
    {
        $chain = ConfigLoaderFixture::createLeafParserChain();

        self::assertSame(4, $chain->parseForNode(new IntegerNode('workers'), 'STEWART_WORKERS', '4'));
        self::assertSame(0.5, $chain->parseForNode(new FloatNode('ratio'), 'STEWART_RATIO', '0.5'));
    }

    public function testChainLeavesUnsupportedNodeAsText(): void
    {
        $chain = ConfigLoaderFixture::createLeafParserChain();

        self::assertFalse($chain->supportsNode(new ArrayNode('home_assistant')));
        self::assertSame('ws://ha', $chain->parseForNode(new ArrayNode('home_assistant'), 'STEWART_HOME_ASSISTANT', 'ws://ha'));
    }
}
