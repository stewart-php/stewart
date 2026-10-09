from dataclasses import dataclass
from typing import Any

from homeassistant.core import callback
from homeassistant.helpers import entity_registry as er
from homeassistant.helpers.entity_platform import EntityPlatform

from .change import EntityUpsert
from .const import DOMAIN
from .devices import DeviceDirectory, DeviceTarget
from .entity import PlatformConfig, StewartEntity
from .errors import InvalidConfigError
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
        tracked.entity.publish()
        return tracked.entity.take_snapshot()

    async def _add_entity(
        self, platform: AttachedPlatform, upsert: EntityUpsert, config: PlatformConfig
    ) -> EntitySnapshot:
        address = upsert.address
        device = DeviceTarget.for_app(address)
        entity = platform.exposure.create_entity(
            address=address, config=config, sessions=self._sessions, device_info=device.device_info
        )
        entity.apply_change(upsert.change)
        self._remove_from_other_platforms(platform, address)
        self._devices.ensure_device(device)
        self._entities[address.unique_id] = TrackedEntity(platform=platform, entity=entity)
        await platform.entity_platform.async_add_entities([entity])
        return entity.take_snapshot()

    # The registry, not memory, so an entry left from before a Home Assistant restart goes too.
    def _remove_from_other_platforms(self, platform: AttachedPlatform, address: EntityAddress) -> None:
        for other in self._platforms.values():
            if other is platform:
                continue
            entity_id = self._entity_registry.async_get_entity_id(other.exposure.domain, DOMAIN, address.unique_id)
            if entity_id is not None:
                self._entity_registry.async_remove(entity_id)

    def _find_platform(self, domain: str) -> AttachedPlatform:
        if (platform := self._platforms.get(domain)) is None:
            raise InvalidConfigError(f"Platform {domain} is not supported.")
        return platform
