from collections.abc import Iterable
from dataclasses import dataclass, replace
from typing import Any

from homeassistant.core import Event, callback
from homeassistant.helpers import entity_registry as er
from homeassistant.helpers.entity_platform import EntityPlatform

from .change import EntityChange, EntityUpsert
from .const import DOMAIN
from .devices import DeviceDirectory, DeviceTarget
from .entity import PlatformConfig, StewartEntity
from .errors import EntityNotFoundError, InvalidConfigError
from .identity import EntityAddress
from .platforms import ExposurePlatform
from .session import SessionRegistry
from .snapshot import EntitySnapshot


@dataclass(frozen=True, slots=True, kw_only=True)
class AttachedPlatform:
    exposure: ExposurePlatform[Any]
    entity_platform: EntityPlatform


@dataclass(frozen=True, slots=True, kw_only=True)
class TrackedEntity:
    platform: AttachedPlatform
    device: DeviceTarget
    entity: StewartEntity[Any, Any]


class ExposedEntities:
    def __init__(self, sessions: SessionRegistry, devices: DeviceDirectory, entity_registry: er.EntityRegistry) -> None:
        self._sessions = sessions
        self._devices = devices
        self._entity_registry = entity_registry
        self._platforms: dict[str, AttachedPlatform] = {}
        self._entities: dict[str, TrackedEntity] = {}

    @callback
    def attach_platform(self, exposure: ExposurePlatform[Any], entity_platform: EntityPlatform) -> None:
        self._platforms[exposure.domain] = AttachedPlatform(exposure=exposure, entity_platform=entity_platform)

    async def upsert(self, upsert: EntityUpsert) -> EntitySnapshot:
        platform = self._find_platform(upsert.platform)
        config = platform.exposure.read_config(upsert.config)
        tracked = self._entities.get(upsert.address.unique_id)
        if tracked is None or tracked.platform is not platform:
            return await self._add_entity(platform, upsert, config)
        tracked.entity.apply_upsert(config, upsert.change)
        self._place_on_device(tracked, upsert.device)
        tracked.entity.publish()
        return tracked.entity.take_snapshot()

    @callback
    def update_state(self, address: EntityAddress, change: EntityChange) -> None:
        if (tracked := self._entities.get(address.unique_id)) is None:
            raise EntityNotFoundError.for_address(address)
        tracked.entity.apply_change(change)
        tracked.entity.publish()

    @callback
    def remove(self, address: EntityAddress) -> bool:
        tracked = self._entities.pop(address.unique_id, None)
        removed_entries = self._remove_registry_entries(address, self._platforms.values())
        self._devices.remove_if_empty(entry.device_id for entry in removed_entries)
        return tracked is not None or bool(removed_entries)

    # A user may delete an entity in Home Assistant; it stays deleted until Stewart upserts it again.
    @callback
    def forget_removed_entry(self, event: Event[er.EventEntityRegistryUpdatedData]) -> None:
        if event.data["action"] != "remove":
            return
        for unique_id, tracked in list(self._entities.items()):
            if tracked.entity.entity_id == event.data["entity_id"]:
                del self._entities[unique_id]

    async def _add_entity(
        self, platform: AttachedPlatform, upsert: EntityUpsert, config: PlatformConfig
    ) -> EntitySnapshot:
        address = upsert.address
        entity = platform.exposure.create_entity(
            address=address, config=config, sessions=self._sessions, device_info=upsert.device.device_info
        )
        entity.apply_change(upsert.change)
        others = [other for other in self._platforms.values() if other is not platform]
        replaced_entries = self._remove_registry_entries(address, others)
        self._devices.ensure_device(upsert.device)
        self._entities[address.unique_id] = TrackedEntity(platform=platform, device=upsert.device, entity=entity)
        await platform.entity_platform.async_add_entities([entity])
        self._devices.remove_if_empty(entry.device_id for entry in replaced_entries)
        return entity.take_snapshot()

    def _place_on_device(self, tracked: TrackedEntity, device: DeviceTarget) -> None:
        device_entry = self._devices.ensure_device(device)
        if device.identifier == tracked.device.identifier:
            return
        registry_entry = self._entity_registry.async_get(tracked.entity.entity_id)
        self._entity_registry.async_update_entity(tracked.entity.entity_id, device_id=device_entry.id)
        self._entities[tracked.entity.address.unique_id] = replace(tracked, device=device)
        if registry_entry is not None:
            self._devices.remove_if_empty([registry_entry.device_id])

    # The registry, not memory, so an entry left from before a Home Assistant restart goes too.
    def _remove_registry_entries(
        self, address: EntityAddress, platforms: Iterable[AttachedPlatform]
    ) -> list[er.RegistryEntry]:
        removed: list[er.RegistryEntry] = []
        for platform in platforms:
            entity_id = self._entity_registry.async_get_entity_id(platform.exposure.domain, DOMAIN, address.unique_id)
            if entity_id is not None and (entry := self._entity_registry.async_get(entity_id)) is not None:
                self._entity_registry.async_remove(entity_id)
                removed.append(entry)
        return removed

    def _find_platform(self, domain: str) -> AttachedPlatform:
        if (platform := self._platforms.get(domain)) is None:
            raise InvalidConfigError(f"Platform {domain} is not supported.")
        return platform
