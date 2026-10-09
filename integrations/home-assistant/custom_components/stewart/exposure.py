from dataclasses import dataclass
from typing import Any

from homeassistant.core import callback
from homeassistant.helpers.entity_platform import EntityPlatform

from .change import EntityUpsert
from .devices import DeviceDirectory, DeviceTarget
from .entity import PlatformConfig, StewartEntity
from .errors import InvalidConfigError
from .platforms import ExposurePlatform
from .session import SessionRegistry
from .snapshot import EntitySnapshot


@dataclass(frozen=True, slots=True, kw_only=True)
class AttachedPlatform:
    exposure: ExposurePlatform[Any]
    entity_platform: EntityPlatform


class ExposedEntities:
    def __init__(self, sessions: SessionRegistry, devices: DeviceDirectory) -> None:
        self._sessions = sessions
        self._devices = devices
        self._platforms: dict[str, AttachedPlatform] = {}
        self._entities: dict[str, StewartEntity[Any, Any]] = {}

    @callback
    def attach_platform(self, exposure: ExposurePlatform[Any], entity_platform: EntityPlatform) -> None:
        self._platforms[exposure.domain] = AttachedPlatform(exposure=exposure, entity_platform=entity_platform)

    async def upsert(self, upsert: EntityUpsert) -> EntitySnapshot:
        platform = self._find_platform(upsert.platform)
        config = platform.exposure.read_config(upsert.config)
        if (entity := self._entities.get(upsert.address.unique_id)) is None:
            return await self._add_entity(platform, upsert, config)
        entity.apply_upsert(config, upsert.change)
        entity.publish()
        return entity.take_snapshot()

    async def _add_entity(
        self, platform: AttachedPlatform, upsert: EntityUpsert, config: PlatformConfig
    ) -> EntitySnapshot:
        address = upsert.address
        device = DeviceTarget.for_app(address)
        entity = platform.exposure.create_entity(
            address=address, config=config, sessions=self._sessions, device_info=device.device_info
        )
        entity.apply_change(upsert.change)
        self._devices.ensure_device(device)
        self._entities[address.unique_id] = entity
        await platform.entity_platform.async_add_entities([entity])
        return entity.take_snapshot()

    def _find_platform(self, domain: str) -> AttachedPlatform:
        if (platform := self._platforms.get(domain)) is None:
            raise InvalidConfigError(f"Platform {domain} is not supported.")
        return platform
