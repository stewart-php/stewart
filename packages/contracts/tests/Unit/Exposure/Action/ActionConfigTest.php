<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exposure\Action;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exposure\Action\ActionConfig;
use Stewart\Contracts\Exposure\Action\ActionField;
use Stewart\Contracts\Exposure\Action\ActionFieldType;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ActionConfig::class)]
#[CoversClass(ActionField::class)]
final class ActionConfigTest extends TestCase
{
    use AssertsReason;

    /** @param Closure(): mixed $build */
    #[DataProvider('provideInvalidConfigs')]
    public function testInvalidConfigIsRejected(Closure $build): void
    {
        self::assertThrowsReason(ExposureError::ConfigInvalid, $build);
    }

    /** @return iterable<string, array{Closure(): mixed}> */
    public static function provideInvalidConfigs(): iterable
    {
        yield 'empty name' => [static fn() => new ActionConfig('')];
        yield 'duplicate fields' => [static fn() => new ActionConfig(fields: [new ActionField('room'), new ActionField('room')])];
        yield 'field name not snake case' => [static fn() => new ActionField('Room')];
        yield 'select without options' => [static fn() => new ActionField('room', ActionFieldType::Select)];
        yield 'options on a text field' => [static fn() => new ActionField('room', options: ['office'])];
        yield 'duplicate options' => [static fn() => new ActionField('room', ActionFieldType::Select, options: ['office', 'office'])];
    }

    public function testFieldsKeepTheirOrder(): void
    {
        $config = new ActionConfig(fields: [new ActionField('room', ActionFieldType::Entity, required: true), new ActionField('minutes', ActionFieldType::Number)]);

        self::assertSame(['room', 'minutes'], array_map(static fn(ActionField $field): string => $field->name, $config->fields));
    }
}
