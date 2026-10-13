from collections.abc import Mapping
from dataclasses import dataclass
from datetime import time
from typing import Self

from homeassistant.components.time import TimeEntity
from homeassistant.const import Platform

from .change import EntityChange, JsonValue
from .command import CommandAction
from .config import ConfigReader, EntityConfig
from .entity import StewartEntity
from .errors import InvalidStateError
from .moments import read_time_of_day
from .platforms import attach


@dataclass(frozen=True, slots=True, kw_only=True)
class TimeConfig:
    entity: EntityConfig

    @classmethod
    def read_from(cls, raw: Mapping[str, JsonValue]) -> Self:
        reader = ConfigReader(Platform.TIME, raw)
        config = cls(entity=EntityConfig.read_from(reader))
        reader.finish()
        return config


class StewartTime(StewartEntity[TimeConfig, time | None], TimeEntity):
    domain = Platform.TIME
    config_type = TimeConfig

    async def async_set_value(self, value: time) -> None:
        wire_value = value.isoformat(timespec="seconds")
        await self.run_command(CommandAction.SET_VALUE, {"value": wire_value})
        self.apply_change(EntityChange(state=wire_value))
        self.publish()

    def _convert_state(self, config: TimeConfig, state: JsonValue) -> time | None:  # noqa: ARG002
        if state is None:
            return None
        if (value := read_time_of_day(state)) is None:
            raise InvalidStateError("A time state must be HH:MM:SS or null.")
        return value

    def _show_state(self, native: time | None) -> None:
        self._attr_native_value = native


async_setup_entry = attach(StewartTime)
