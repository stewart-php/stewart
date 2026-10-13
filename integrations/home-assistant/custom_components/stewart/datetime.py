from collections.abc import Mapping
from dataclasses import dataclass
from datetime import datetime
from typing import Self

from homeassistant.components.datetime import DateTimeEntity
from homeassistant.const import Platform

from .change import EntityChange, JsonValue
from .command import CommandAction
from .config import ConfigReader, EntityConfig
from .entity import StewartEntity
from .errors import InvalidStateError
from .moments import read_moment
from .platforms import attach


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
    domain = Platform.DATETIME
    config_type = DateTimeConfig

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


async_setup_entry = attach(StewartDateTime)
