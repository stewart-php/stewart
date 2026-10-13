from collections.abc import Mapping
from dataclasses import dataclass
from datetime import date
from typing import Self

from homeassistant.components.date import DateEntity
from homeassistant.const import Platform

from .change import EntityChange, JsonValue
from .command import CommandAction
from .config import ConfigReader, EntityConfig
from .entity import StewartEntity
from .errors import InvalidStateError
from .moments import read_day
from .platforms import attach


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
    domain = Platform.DATE
    config_type = DateConfig

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


async_setup_entry = attach(StewartDate)
