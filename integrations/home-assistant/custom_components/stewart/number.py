from collections.abc import Mapping
from dataclasses import dataclass
from typing import Final, Self

from homeassistant.components.number import DEVICE_CLASS_UNITS, NumberDeviceClass, NumberEntity, NumberMode
from homeassistant.const import Platform
from homeassistant.core import HomeAssistant
from homeassistant.helpers.device_registry import DeviceInfo
from homeassistant.helpers.entity_platform import AddConfigEntryEntitiesCallback, async_get_current_platform

from .change import EntityChange, JsonValue
from .command import CommandAction
from .config import ConfigReader, EntityConfig
from .entity import StewartEntity
from .errors import InvalidStateError
from .identity import EntityAddress
from .runtime import StewartConfigEntry
from .session import SessionRegistry


@dataclass(frozen=True, slots=True, kw_only=True)
class NumberConfig:
    entity: EntityConfig
    min: float
    max: float
    step: float
    mode: NumberMode
    device_class: NumberDeviceClass | None
    unit_of_measurement: str | None

    @classmethod
    def read_from(cls, raw: Mapping[str, JsonValue]) -> Self:
        reader = ConfigReader(Platform.NUMBER, raw)
        config = cls(
            entity=EntityConfig.read_from(reader),
            min=reader.read_required_number("min"),
            max=reader.read_required_number("max"),
            step=reader.read_required_number("step"),
            mode=reader.read_choice("mode", NumberMode) or NumberMode.AUTO,
            device_class=reader.read_choice("device_class", NumberDeviceClass),
            unit_of_measurement=reader.read_str("unit_of_measurement"),
        )
        reader.finish()
        config._check_consistency(reader)
        return config

    def _check_consistency(self, reader: ConfigReader) -> None:
        if self.min > self.max:
            raise reader.fail("min must not be greater than max")
        if self.step <= 0:
            raise reader.fail("step must be greater than 0")
        if self.device_class is None:
            return
        units = DEVICE_CLASS_UNITS.get(self.device_class)
        if units is not None and self.unit_of_measurement not in units:
            raise reader.fail(
                f"device_class {self.device_class} needs a unit_of_measurement of "
                f"{', '.join(sorted(str(unit) for unit in units if unit is not None))}"
            )


class StewartNumber(StewartEntity[NumberConfig, float | None], NumberEntity):
    async def async_set_native_value(self, value: float) -> None:
        await self.run_command(CommandAction.SET_VALUE, {"value": value})
        self.apply_change(EntityChange(state=value))
        self.publish()

    def _show_config(self, config: NumberConfig) -> None:
        super()._show_config(config)
        self._attr_native_min_value = config.min
        self._attr_native_max_value = config.max
        self._attr_native_step = config.step
        self._attr_mode = config.mode
        self._attr_device_class = config.device_class
        self._attr_native_unit_of_measurement = config.unit_of_measurement

    def _convert_state(self, config: NumberConfig, state: JsonValue) -> float | None:
        if state is None:
            return None
        if not isinstance(state, (int, float)) or isinstance(state, bool):
            raise InvalidStateError("A number state must be a number or null.")
        if not config.min <= state <= config.max:
            raise InvalidStateError(f"A number state must be between {config.min:g} and {config.max:g}.")
        return float(state)

    def _show_state(self, native: float | None) -> None:
        self._attr_native_value = native


class NumberExposure:
    domain: Final = Platform.NUMBER

    def read_config(self, raw: Mapping[str, JsonValue]) -> NumberConfig:
        return NumberConfig.read_from(raw)

    def create_entity(
        self, *, address: EntityAddress, config: NumberConfig, sessions: SessionRegistry, device_info: DeviceInfo
    ) -> StewartNumber:
        return StewartNumber(address=address, config=config, sessions=sessions, device_info=device_info)


async def async_setup_entry(
    hass: HomeAssistant,  # noqa: ARG001
    entry: StewartConfigEntry,
    async_add_entities: AddConfigEntryEntitiesCallback,  # noqa: ARG001
) -> None:
    entry.runtime_data.entities.attach_platform(NumberExposure(), async_get_current_platform())
