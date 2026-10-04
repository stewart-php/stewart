<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Stream;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Stream\PreviousStateRule;

#[CoversClass(PreviousStateRule::class)]
final class PreviousStateRuleTest extends TestCase
{
    private const string ENTITY = 'light.hall';

    public function testDefaultPermitsKnownPrevious(): void
    {
        self::assertTrue(PreviousStateRule::excludingUnavailable()->permits(self::createChange('off')));
    }

    public function testDefaultRejectsUnavailablePrevious(): void
    {
        self::assertFalse(PreviousStateRule::excludingUnavailable()->permits(self::createChange(EntityState::UNAVAILABLE)));
    }

    public function testDefaultRejectsUnknownPrevious(): void
    {
        self::assertFalse(PreviousStateRule::excludingUnavailable()->permits(self::createChange(EntityState::UNKNOWN)));
    }

    public function testDefaultRejectsMissingPrevious(): void
    {
        self::assertFalse(PreviousStateRule::excludingUnavailable()->permits(self::createChange(null)));
    }

    public function testAllowingAnyPermitsMissingPrevious(): void
    {
        self::assertTrue(PreviousStateRule::allowingAny()->permits(self::createChange(null)));
        self::assertTrue(PreviousStateRule::allowingAny()->permits(self::createChange(EntityState::UNAVAILABLE)));
    }

    public function testAllowingOnlyPermitsListedStates(): void
    {
        $rule = PreviousStateRule::allowingOnly('off', EntityState::UNAVAILABLE);

        self::assertTrue($rule->permits(self::createChange('off')));
        self::assertTrue($rule->permits(self::createChange(EntityState::UNAVAILABLE)));
        self::assertFalse($rule->permits(self::createChange('idle')));
        self::assertFalse($rule->permits(self::createChange(null)));
    }

    private static function createChange(?string $from): StateChange
    {
        $entityId = new EntityId(self::ENTITY);

        return new StateChange(
            $entityId,
            $from === null ? null : new EntityState($entityId, $from),
            new EntityState($entityId, 'on'),
        );
    }
}
