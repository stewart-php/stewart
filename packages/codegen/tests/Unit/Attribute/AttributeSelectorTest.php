<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Attribute\AttributeCatalog;
use Stewart\Codegen\Attribute\AttributeFilter;
use Stewart\Codegen\Attribute\AttributeKind;
use Stewart\Codegen\Attribute\AttributeSelection;
use Stewart\Codegen\Attribute\AttributeSelector;
use Stewart\Codegen\Attribute\Collection\AttributeFilterCollection;
use Stewart\Codegen\Attribute\KeyPatterns;
use Stewart\Codegen\Attribute\UnseenAttribute;
use Stewart\Codegen\GenerationOptions;

#[CoversClass(AttributeSelector::class)]
#[CoversClass(AttributeCatalog::class)]
#[CoversClass(AttributeFilter::class)]
#[CoversClass(GenerationOptions::class)]
#[CoversClass(KeyPatterns::class)]
#[CoversClass(UnseenAttribute::class)]
final class AttributeSelectorTest extends TestCase
{
    private const string UNCATALOGUED_DOMAIN = 'lawn_mower';

    public function testKnownAttributeIsTyped(): void
    {
        $selection = self::createAttributeSelection('light', ['color_temp_kelvin' => AttributeKind::Float, 'unit_of_measurement' => AttributeKind::String]);

        self::assertSame(AttributeKind::Float, $selection->typed['color_temp_kelvin'] ?? null);
        self::assertSame(AttributeKind::String, $selection->typed['unit_of_measurement'] ?? null);
        self::assertTrue($selection->unseen->isEmpty());
    }

    public function testDomainKeyIsTypedWithoutAnyReport(): void
    {
        $selection = self::createAttributeSelection('timer', []);

        self::assertSame(['duration', 'editable', 'finishes_at', 'remaining', 'restore'], array_keys($selection->typed));
        self::assertSame(AttributeKind::String, $selection->typed['remaining']);
    }

    public function testCatalogKindWinsOverObservedKind(): void
    {
        $selection = self::createAttributeSelection('light', ['brightness' => AttributeKind::String]);

        self::assertSame(AttributeKind::Float, $selection->typed['brightness'] ?? null);
    }

    public function testNullOnlyCatalogKeyKeepsCatalogKind(): void
    {
        $selection = self::createAttributeSelection('light', ['effect' => AttributeKind::Mixed]);

        self::assertSame(AttributeKind::String, $selection->typed['effect'] ?? null);
    }

    public function testCommonKeyIsTypedOnlyWhenReported(): void
    {
        self::assertSame([], self::createAttributeSelection(self::UNCATALOGUED_DOMAIN, [])->typed);
        self::assertSame(['icon' => AttributeKind::String], self::createAttributeSelection(self::UNCATALOGUED_DOMAIN, ['icon' => AttributeKind::Mixed])->typed);
    }

    public function testUnknownKeyIsLeftToAttributes(): void
    {
        $selection = self::createAttributeSelection('light', ['entity_id' => AttributeKind::Array, 'group_entities' => AttributeKind::Array, 'firmware' => AttributeKind::String]);

        self::assertSame([], array_intersect(['entity_id', 'group_entities', 'firmware'], array_keys($selection->typed)));
    }

    public function testIncludedKeyIsTyped(): void
    {
        $selection = self::createAttributeSelection(self::UNCATALOGUED_DOMAIN, ['mop_pad' => AttributeKind::String, 'mop_mode' => AttributeKind::Bool, 'firmware' => AttributeKind::String], include: ['mop_*']);

        self::assertSame(['mop_mode' => AttributeKind::Bool, 'mop_pad' => AttributeKind::String], $selection->typed);
    }

    public function testExcludeWinsOverIncludeAndTheKnownList(): void
    {
        $selection = self::createAttributeSelection('light', ['brightness' => AttributeKind::Float, 'effect' => AttributeKind::String], include: ['effect'], exclude: ['brightness', 'eff*']);

        self::assertSame([], array_intersect(['brightness', 'effect', 'effect_list'], array_keys($selection->typed)));
    }

    public function testAttributeKeysUseTheEntitySelectorDialect(): void
    {
        $selection = self::createAttributeSelection(self::UNCATALOGUED_DOMAIN, ['mop_pad' => AttributeKind::Mixed, 'mop_mode' => AttributeKind::Mixed, 'mop[pad]' => AttributeKind::Mixed], include: ['mop_?ad', 'mop[pad]']);

        self::assertSame(['mop[pad]', 'mop_pad'], array_keys($selection->typed));
    }

    public function testFriendlyNameIsNeverTyped(): void
    {
        $selection = self::createAttributeSelection('sensor', ['friendly_name' => AttributeKind::String], include: ['*']);

        self::assertArrayNotHasKey('friendly_name', $selection->typed);
    }

    public function testNonLetterKeyIsNeverTyped(): void
    {
        $selection = self::createAttributeSelection(self::UNCATALOGUED_DOMAIN, ['06-19 13:01' => AttributeKind::Float, 'battery' => AttributeKind::Float], include: ['*']);

        self::assertSame(['battery'], array_keys($selection->typed));
    }

    public function testUnreportedExactIncludeIsUnseen(): void
    {
        $selection = self::createAttributeSelection('vacuum', [], include: ['battery', 'mop_*']);

        self::assertEquals([new UnseenAttribute('vacuum', 'battery')], $selection->unseen->listValues());
        self::assertSame('No vacuum entity reports the included attribute battery.', $selection->unseen->getFirst()?->describeWarning());
    }

    public function testUnseenExactIncludeIsTypedAsMixed(): void
    {
        $selection = self::createAttributeSelection(self::UNCATALOGUED_DOMAIN, [], include: ['blade_height', 'icon']);

        self::assertSame(['blade_height' => AttributeKind::Mixed, 'icon' => AttributeKind::String], $selection->typed);
    }

    public function testGlobIncludeNeedsAReportedKey(): void
    {
        $selection = self::createAttributeSelection(self::UNCATALOGUED_DOMAIN, [], include: ['blade_*']);

        self::assertSame([], $selection->typed);
        self::assertTrue($selection->unseen->isEmpty());
    }

    public function testUnfilteredDomainFallsBackToKnownList(): void
    {
        $options = new GenerationOptions(attributes: AttributeFilterCollection::keyedByDomain([new AttributeFilter('vacuum', new KeyPatterns(['mop_pad']))]));

        $observed = ['mop_pad' => AttributeKind::Mixed, 'device_class' => AttributeKind::Mixed];
        $selector = new AttributeSelector(new AttributeCatalog());

        self::assertArrayHasKey('mop_pad', $selector->selectAttributes('vacuum', $observed, $options->attributesFor('vacuum'))->typed);
        self::assertSame(['device_class', 'last_reset', 'options'], array_keys($selector->selectAttributes('sensor', $observed, $options->attributesFor('sensor'))->typed));
    }

    /**
     * @param array<string, AttributeKind> $observed
     * @param list<string> $include
     * @param list<string> $exclude
     */
    private static function createAttributeSelection(string $domain, array $observed, array $include = [], array $exclude = []): AttributeSelection
    {
        return new AttributeSelector(new AttributeCatalog())->selectAttributes(
            $domain,
            $observed,
            new AttributeFilter($domain, new KeyPatterns($include), new KeyPatterns($exclude)),
        );
    }
}
