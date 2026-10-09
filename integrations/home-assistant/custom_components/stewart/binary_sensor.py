from collections.abc import Mapping
from dataclasses import dataclass
from typing import Final, Self

from homeassistant.components.binary_sensor import BinarySensorDeviceClass, BinarySensorEntity
from homeassistant.const import Platform
from homeassistant.core import HomeAssistant
from homeassistant.helpers.device_registry import DeviceInfo
from homeassistant.helpers.entity_platform import AddConfigEntryEntitiesCallback, async_get_current_platform

from .change import JsonValue
from .config import ConfigReader, EntityConfig
from .entity import StewartEntity
from .errors import InvalidStateError
from .identity import EntityAddress
from .runtime import StewartConfigEntry
from .session import SessionRegistry


@dataclass(frozen=True, slots=True, kw_only=True)
class BinarySensorConfig:
    entity: EntityConfig
    device_class: BinarySensorDeviceClass | None

    @classmethod
    def read_from(cls, raw: Mapping[str, JsonValue]) -> Self:
        reader = ConfigReader(Platform.BINARY_SENSOR, raw)
        config = cls(
            entity=EntityConfig.read_from(reader),
            device_class=reader.read_choice("device_class", BinarySensorDeviceClass),
        )
        reader.finish()
        return config


class StewartBinarySensor(StewartEntity[BinarySensorConfig, bool | None], BinarySensorEntity):
    def _show_config(self, config: BinarySensorConfig) -> None:
        super()._show_config(config)
        self._attr_device_class = config.device_class

    def _convert_state(self, config: BinarySensorConfig, state: JsonValue) -> bool | None:  # noqa: ARG002
        if state is None or isinstance(state, bool):
            return state
        raise InvalidStateError("A binary_sensor state must be true, false or null.")

    def _show_state(self, native: bool | None) -> None:  # noqa: FBT001
        self._attr_is_on = native


class BinarySensorExposure:
    domain: Final = Platform.BINARY_SENSOR

    def read_config(self, raw: Mapping[str, JsonValue]) -> BinarySensorConfig:
        return BinarySensorConfig.read_from(raw)

    def create_entity(
        self, *, address: EntityAddress, config: BinarySensorConfig, sessions: SessionRegistry, device_info: DeviceInfo
    ) -> StewartBinarySensor:
        return StewartBinarySensor(address=address, config=config, sessions=sessions, device_info=device_info)


async def async_setup_entry(
    hass: HomeAssistant,  # noqa: ARG001
    entry: StewartConfigEntry,
    async_add_entities: AddConfigEntryEntitiesCallback,  # noqa: ARG001
) -> None:
    entry.runtime_data.entities.attach_platform(BinarySensorExposure(), async_get_current_platform())
