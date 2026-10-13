from collections.abc import Mapping
from dataclasses import dataclass
from typing import Self

from homeassistant.components.binary_sensor import BinarySensorDeviceClass, BinarySensorEntity
from homeassistant.const import Platform

from .change import JsonValue
from .config import ConfigReader, EntityConfig
from .entity import StewartEntity
from .errors import InvalidStateError
from .platforms import attach


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
    domain = Platform.BINARY_SENSOR
    config_type = BinarySensorConfig

    def _show_config(self, config: BinarySensorConfig) -> None:
        super()._show_config(config)
        self._attr_device_class = config.device_class

    def _convert_state(self, config: BinarySensorConfig, state: JsonValue) -> bool | None:  # noqa: ARG002
        if state is None or isinstance(state, bool):
            return state
        raise InvalidStateError("A binary_sensor state must be true, false or null.")

    def _show_state(self, native: bool | None) -> None:  # noqa: FBT001
        self._attr_is_on = native


async_setup_entry = attach(StewartBinarySensor)
