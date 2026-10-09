from collections.abc import Mapping
from dataclasses import dataclass
from datetime import date, datetime
from typing import Final, Self

from homeassistant.components.sensor import (
    DEVICE_CLASS_STATE_CLASSES,
    DEVICE_CLASS_UNITS,
    NON_NUMERIC_DEVICE_CLASSES,
    SensorDeviceClass,
    SensorEntity,
    SensorStateClass,
)
from homeassistant.const import MAX_LENGTH_STATE_STATE, Platform
from homeassistant.core import HomeAssistant
from homeassistant.helpers.device_registry import DeviceInfo
from homeassistant.helpers.entity_platform import AddConfigEntryEntitiesCallback, async_get_current_platform

from .change import JsonValue
from .config import ConfigReader, EntityConfig
from .entity import StewartEntity
from .errors import InvalidStateError
from .identity import EntityAddress
from .moments import read_day, read_moment
from .runtime import StewartConfigEntry
from .session import SessionRegistry

# Strings, so classes newer than the oldest supported Home Assistant (uptime) need no import.
DATETIME_DEVICE_CLASSES: Final = frozenset({"timestamp", "uptime"})

type SensorValue = str | int | float | date | datetime | None


@dataclass(frozen=True, slots=True, kw_only=True)
class SensorConfig:
    entity: EntityConfig
    device_class: SensorDeviceClass | None
    unit_of_measurement: str | None
    state_class: SensorStateClass | None
    suggested_display_precision: int | None
    options: tuple[str, ...] | None

    @classmethod
    def read_from(cls, raw: Mapping[str, JsonValue]) -> Self:
        reader = ConfigReader(Platform.SENSOR, raw)
        config = cls(
            entity=EntityConfig.read_from(reader),
            device_class=reader.read_choice("device_class", SensorDeviceClass),
            unit_of_measurement=reader.read_str("unit_of_measurement"),
            state_class=reader.read_choice("state_class", SensorStateClass),
            suggested_display_precision=reader.read_non_negative_int("suggested_display_precision"),
            options=reader.read_str_list("options"),
        )
        reader.finish()
        config._check_consistency(reader)
        return config

    @property
    def expects_number(self) -> bool:
        return (
            self.unit_of_measurement is not None
            or self.state_class is not None
            or self.suggested_display_precision is not None
            or (self.device_class is not None and self.device_class not in NON_NUMERIC_DEVICE_CLASSES)
        )

    def _check_consistency(self, reader: ConfigReader) -> None:
        device_class = self.device_class
        if (self.options is not None) != (device_class is SensorDeviceClass.ENUM):
            raise reader.fail("options and device_class enum go together")
        if device_class is None:
            return
        if device_class in NON_NUMERIC_DEVICE_CLASSES and (
            self.unit_of_measurement is not None or self.state_class is not None
        ):
            raise reader.fail(f"device_class {device_class} takes no unit_of_measurement or state_class")
        units = DEVICE_CLASS_UNITS.get(device_class)
        if units is not None and self.unit_of_measurement not in units:
            raise reader.fail(
                f"device_class {device_class} needs a unit_of_measurement of "
                f"{', '.join(sorted(str(unit) for unit in units if unit is not None))}"
            )
        state_classes = DEVICE_CLASS_STATE_CLASSES.get(device_class)
        if self.state_class is not None and state_classes is not None and self.state_class not in state_classes:
            raise reader.fail(f"state_class {self.state_class} does not fit device_class {device_class}")


def convert_sensor_state(config: SensorConfig, state: JsonValue) -> SensorValue:
    if state is None:
        return None
    if config.device_class is SensorDeviceClass.ENUM:
        return _convert_option(config, state)
    if config.device_class in DATETIME_DEVICE_CLASSES:
        return _convert_moment(state)
    if config.device_class is SensorDeviceClass.DATE:
        return _convert_day(state)
    return _convert_plain(config, state)


def _convert_option(config: SensorConfig, state: JsonValue) -> str:
    if isinstance(state, str) and config.options is not None and state in config.options:
        return state
    raise InvalidStateError("A sensor state must be one of its options.")


def _convert_moment(state: JsonValue) -> datetime:
    if (moment := read_moment(state)) is None:
        raise InvalidStateError("A sensor state must be an ISO 8601 date and time with an offset.")
    return moment


def _convert_day(state: JsonValue) -> date:
    if (day := read_day(state)) is None:
        raise InvalidStateError("A sensor state must be an ISO 8601 date.")
    return day


def _convert_plain(config: SensorConfig, state: JsonValue) -> str | int | float:
    if isinstance(state, (int, float)) and not isinstance(state, bool):
        return state
    if config.expects_number:
        raise InvalidStateError("A sensor state must be a number.")
    if isinstance(state, str) and len(state) <= MAX_LENGTH_STATE_STATE:
        return state
    raise InvalidStateError(
        f"A sensor state must be a number or a string of at most {MAX_LENGTH_STATE_STATE} characters."
    )


class StewartSensor(StewartEntity[SensorConfig, SensorValue], SensorEntity):
    def _show_config(self, config: SensorConfig) -> None:
        super()._show_config(config)
        self._attr_device_class = config.device_class
        self._attr_native_unit_of_measurement = config.unit_of_measurement
        self._attr_state_class = config.state_class
        self._attr_suggested_display_precision = config.suggested_display_precision
        self._attr_options = list(config.options) if config.options is not None else None

    def _convert_state(self, config: SensorConfig, state: JsonValue) -> SensorValue:
        return convert_sensor_state(config, state)

    def _show_state(self, native: SensorValue) -> None:
        self._attr_native_value = native


class SensorExposure:
    domain: Final = Platform.SENSOR

    def read_config(self, raw: Mapping[str, JsonValue]) -> SensorConfig:
        return SensorConfig.read_from(raw)

    def create_entity(
        self, *, address: EntityAddress, config: SensorConfig, sessions: SessionRegistry, device_info: DeviceInfo
    ) -> StewartSensor:
        return StewartSensor(address=address, config=config, sessions=sessions, device_info=device_info)


async def async_setup_entry(
    hass: HomeAssistant,  # noqa: ARG001
    entry: StewartConfigEntry,
    async_add_entities: AddConfigEntryEntitiesCallback,  # noqa: ARG001
) -> None:
    entry.runtime_data.entities.attach_platform(SensorExposure(), async_get_current_platform())
