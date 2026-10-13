from collections.abc import Mapping
from dataclasses import dataclass
from typing import Self

from homeassistant.components.select import SelectEntity
from homeassistant.const import Platform

from .change import EntityChange, JsonValue
from .command import CommandAction
from .config import ConfigReader, EntityConfig
from .entity import StewartEntity
from .errors import InvalidStateError
from .platforms import attach


@dataclass(frozen=True, slots=True, kw_only=True)
class SelectConfig:
    entity: EntityConfig
    options: tuple[str, ...]

    @classmethod
    def read_from(cls, raw: Mapping[str, JsonValue]) -> Self:
        reader = ConfigReader(Platform.SELECT, raw)
        entity = EntityConfig.read_from(reader)
        options = reader.read_str_list("options")
        reader.finish()
        if options is None:
            raise reader.fail("needs options")
        if len(set(options)) != len(options):
            raise reader.fail("options must be distinct")
        return cls(entity=entity, options=options)


class StewartSelect(StewartEntity[SelectConfig, str | None], SelectEntity):
    domain = Platform.SELECT
    config_type = SelectConfig

    async def async_select_option(self, option: str) -> None:
        await self.run_command(CommandAction.SELECT_OPTION, {"option": option})
        self.apply_change(EntityChange(state=option))
        self.publish()

    def _show_config(self, config: SelectConfig) -> None:
        super()._show_config(config)
        self._attr_options = list(config.options)

    def _convert_state(self, config: SelectConfig, state: JsonValue) -> str | None:
        if state is None or (isinstance(state, str) and state in config.options):
            return state
        raise InvalidStateError("A select state must be one of its options or null.")

    def _show_state(self, native: str | None) -> None:
        self._attr_current_option = native


async_setup_entry = attach(StewartSelect)
