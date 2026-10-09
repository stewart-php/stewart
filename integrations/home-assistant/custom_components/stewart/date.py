from collections.abc import Mapping
from dataclasses import dataclass
from datetime import date
from typing import Final, Self

from homeassistant.components.date import DateEntity
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
from .moments import read_day
from .runtime import StewartConfigEntry
from .session import SessionRegistry


@dataclass(frozen=True, slots=True, kw_only=True)
class DateConfig:
    entity: EntityConfig

    @classmethod
    def read_from(cls, raw: Mapping[str, JsonValue]) -> Self:
        reader = ConfigReader(Platform.DATE, raw)
        config = cls(entity=EntityConfig.read_from(reader))
        reader.finish()
        return config


class StewartDate(StewartEntity[DateConfig, date | None], DateEntity):
    async def async_set_value(self, value: date) -> None:
        wire_value = value.isoformat()
        await self.run_command(CommandAction.SET_VALUE, {"value": wire_value})
        self.apply_change(EntityChange(state=wire_value))
        self.publish()

    def _convert_state(self, config: DateConfig, state: JsonValue) -> date | None:  # noqa: ARG002
        if state is None:
            return None
        if (value := read_day(state)) is None:
            raise InvalidStateError("A date state must be YYYY-MM-DD or null.")
        return value

    def _show_state(self, native: date | None) -> None:
        self._attr_native_value = native


class DateExposure:
    domain: Final = Platform.DATE

    def read_config(self, raw: Mapping[str, JsonValue]) -> DateConfig:
        return DateConfig.read_from(raw)

    def create_entity(
        self, *, address: EntityAddress, config: DateConfig, sessions: SessionRegistry, device_info: DeviceInfo
    ) -> StewartDate:
        return StewartDate(address=address, config=config, sessions=sessions, device_info=device_info)


async def async_setup_entry(
    hass: HomeAssistant,  # noqa: ARG001
    entry: StewartConfigEntry,
    async_add_entities: AddConfigEntryEntitiesCallback,  # noqa: ARG001
) -> None:
    entry.runtime_data.entities.attach_platform(DateExposure(), async_get_current_platform())
