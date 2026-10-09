<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\RegistryEditError;
use Stewart\Contracts\Exception\RegistryEditException;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Registry\Collection\RecordedRegistryUpdateCollection;
use Stewart\Testing\Registry\InMemoryRegistry;
use Stewart\Testing\Registry\RecordingRegistryEditor;

#[CoversClass(RecordingRegistryEditor::class)]
#[CoversClass(RecordedRegistryUpdateCollection::class)]
final class RecordingRegistryEditorTest extends TestCase
{
    use AssertsReason;

    public function testUpdateAppliesToSeededRegistry(): void
    {
        $registry = new InMemoryRegistry()->seedEntity(new RegisteredEntity(new EntityId('sensor.battery'), labelIds: [new LabelId('night')]));
        $editor = new RecordingRegistryEditor($registry);

        $updated = $editor->updateEntity('sensor.battery', new EntityRegistryUpdate()->withHidden(true)->withAddedLabels('battery'));

        self::assertTrue($registry->findEntity('sensor.battery')?->isHidden());
        self::assertSame(['night', 'battery'], $updated->listLabelIds()->toStrings());
        self::assertCount(1, $editor->updates->filterByEntityId('sensor.battery'));
    }

    public function testEntityMissingFromRegistryIsNotFound(): void
    {
        $editor = new RecordingRegistryEditor(new InMemoryRegistry());

        $this->assertThrowsReason(RegistryEditError::NotFound, static fn() => $editor->updateEntity('sensor.gone', new EntityRegistryUpdate()->withName('Gone')));
        self::assertTrue($editor->updates->isEmpty());
    }

    public function testWithoutRegistryUpdateStartsFromEmptyEntry(): void
    {
        $updated = new RecordingRegistryEditor()->updateEntity('light.hall', new EntityRegistryUpdate()->withName('Hall'));

        self::assertSame('Hall', $updated->name);
    }

    public function testEmptyUpdateChangesNothing(): void
    {
        $this->assertThrowsReason(
            RegistryEditError::NothingToUpdate,
            static fn() => new RecordingRegistryEditor()->updateEntity('light.hall', new EntityRegistryUpdate()),
        );
    }

    public function testRefusalIsThrownWithoutRecording(): void
    {
        $editor = new RecordingRegistryEditor();
        $editor->refuseUpdates(RegistryEditException::overloaded(new EntityId('light.hall'), 'busy'));

        $this->assertThrowsReason(RegistryEditError::Overloaded, static fn() => $editor->updateEntity('light.hall', new EntityRegistryUpdate()->withName('Hall')));
        self::assertTrue($editor->updates->isEmpty());
    }
}
