from collections.abc import Mapping
from dataclasses import dataclass
from datetime import datetime
from typing import Final, Self

from homeassistant.components.datetime import DateTimeEntity
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
from .moments import read_moment
from .runtime import StewartConfigEntry
from .session import SessionRegistry


@dataclass(frozen=True, slots=True, kw_only=True)
class DateTimeConfig:
    entity: EntityConfig

    @classmethod
    def read_from(cls, raw: Mapping[str, JsonValue]) -> Self:
        reader = ConfigReader(Platform.DATETIME, raw)
        config = cls(entity=EntityConfig.read_from(reader))
        reader.finish()
        return config


class StewartDateTime(StewartEntity[DateTimeConfig, datetime | None], DateTimeEntity):
    async def async_set_value(self, value: datetime) -> None:
        wire_value = value.isoformat()
        await self.run_command(CommandAction.SET_VALUE, {"value": wire_value})
        self.apply_change(EntityChange(state=wire_value))
        self.publish()

    def _convert_state(self, config: DateTimeConfig, state: JsonValue) -> datetime | None:  # noqa: ARG002
        if state is None:
            return None
        if (value := read_moment(state)) is None:
            raise InvalidStateError("A datetime state must be ISO 8601 with an offset or null.")
        return value

    def _show_state(self, native: datetime | None) -> None:
        self._attr_native_value = native


class DateTimeExposure:
    domain: Final = Platform.DATETIME

    def read_config(self, raw: Mapping[str, JsonValue]) -> DateTimeConfig:
        return DateTimeConfig.read_from(raw)

    def create_entity(
        self, *, address: EntityAddress, config: DateTimeConfig, sessions: SessionRegistry, device_info: DeviceInfo
    ) -> StewartDateTime:
        return StewartDateTime(address=address, config=config, sessions=sessions, device_info=device_info)


async def async_setup_entry(
    hass: HomeAssistant,  # noqa: ARG001
    entry: StewartConfigEntry,
    async_add_entities: AddConfigEntryEntitiesCallback,  # noqa: ARG001
) -> None:
    entry.runtime_data.entities.attach_platform(DateTimeExposure(), async_get_current_platform())
