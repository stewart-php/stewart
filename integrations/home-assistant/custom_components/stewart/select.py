from collections.abc import Mapping
from dataclasses import dataclass
from typing import Final, Self

from homeassistant.components.select import SelectEntity
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


class SelectExposure:
    domain: Final = Platform.SELECT

    def read_config(self, raw: Mapping[str, JsonValue]) -> SelectConfig:
        return SelectConfig.read_from(raw)

    def create_entity(
        self, *, address: EntityAddress, config: SelectConfig, sessions: SessionRegistry, device_info: DeviceInfo
    ) -> StewartSelect:
        return StewartSelect(address=address, config=config, sessions=sessions, device_info=device_info)


async def async_setup_entry(
    hass: HomeAssistant,  # noqa: ARG001
    entry: StewartConfigEntry,
    async_add_entities: AddConfigEntryEntitiesCallback,  # noqa: ARG001
) -> None:
    entry.runtime_data.entities.attach_platform(SelectExposure(), async_get_current_platform())
