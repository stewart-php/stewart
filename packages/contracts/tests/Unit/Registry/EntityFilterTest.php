<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\Registry\IndexedRegistry;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Tests\Fixtures\Registry\RegistryFixture;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(EntityFilter::class)]
final class EntityFilterTest extends TestCase
{
    use AssertsReason;

    public function testAreaMatchesThroughDevice(): void
    {
        $filter = EntityFilter::inArea('kitchen');

        self::assertTrue($this->isMatchedBy($filter, 'light.kitchen_ceiling'));
        self::assertFalse($this->isMatchedBy($filter, 'light.hall_spot'));
    }

    public function testValuesWithinDimensionAreAlternatives(): void
    {
        $filter = EntityFilter::inArea(new AreaId('kitchen'))->withArea('hall');

        self::assertTrue($this->isMatchedBy($filter, 'light.kitchen_ceiling'));
        self::assertTrue($this->isMatchedBy($filter, 'light.hall_spot'));
    }

    public function testDimensionsMustAllMatch(): void
    {
        self::assertTrue($this->isMatchedBy(EntityFilter::onFloor('ground')->withDomain('light')->withLabel('night'), 'light.kitchen_ceiling'));
        self::assertFalse($this->isMatchedBy(EntityFilter::onFloor('ground')->withDomain('light')->withLabel('night'), 'light.hall_spot'));
        self::assertFalse($this->isMatchedBy(EntityFilter::onFloor('upstairs')->withDomain('light'), 'light.kitchen_ceiling'));
    }

    public function testLabelMatchesAreaLabel(): void
    {
        self::assertTrue($this->isMatchedBy(EntityFilter::labelled('cooking'), 'light.kitchen_ceiling'));
        self::assertFalse($this->isMatchedBy(EntityFilter::labelled('cooking'), 'light.hall_spot'));
    }

    public function testDeviceMatchesItsEntitiesInAnyArea(): void
    {
        self::assertTrue($this->isMatchedBy(EntityFilter::ofDevice('ceiling_bulb'), 'light.hall_spot'));
        self::assertFalse($this->isMatchedBy(EntityFilter::ofDevice('relay'), 'light.hall_spot'));
    }

    public function testRegistryMatchSkipsHiddenAndCategorized(): void
    {
        self::assertFalse($this->isMatchedBy(EntityFilter::ofDevice('ceiling_bulb'), 'sensor.ceiling_bulb_signal'));
        self::assertFalse($this->isMatchedBy(EntityFilter::inArea('hall'), 'switch.hidden_relay'));
        self::assertTrue($this->isMatchedBy(EntityFilter::inDomain('switch'), 'switch.hidden_relay'));
    }

    public function testDomainAndSelectorNeedNoRegistry(): void
    {
        $registry = IndexedRegistry::empty();

        self::assertTrue(EntityFilter::inDomain('light', 'switch')->matchesEntity(new EntityId('switch.pump'), $registry));
        self::assertTrue(EntityFilter::matching('light.porch_*')->withSelector(Selector::exact('light.hall'))->matchesEntity(new EntityId('light.hall'), $registry));
        self::assertFalse(EntityFilter::matching('light.porch_*')->matchesEntity(new EntityId('light.hall'), $registry));
        self::assertFalse(EntityFilter::inArea('kitchen')->matchesEntity(new EntityId('light.kitchen'), $registry));
    }

    public function testCanonicalKeyIgnoresOrder(): void
    {
        self::assertSame(
            EntityFilter::inArea('hall', 'kitchen')->withDomain('switch', 'light')->toCanonicalKey(),
            EntityFilter::inDomain('light')->withDomain('switch')->withArea('kitchen', 'hall', 'kitchen')->toCanonicalKey(),
        );
    }

    public function testRejectsEmptyId(): void
    {
        $this->assertThrowsReason(IdentifierError::RegistryIdEmpty, static fn() => EntityFilter::labelled(''));
    }

    private function isMatchedBy(EntityFilter $filter, string $entityId): bool
    {
        return $filter->matchesEntity(new EntityId($entityId), RegistryFixture::createRegistry());
    }
}
