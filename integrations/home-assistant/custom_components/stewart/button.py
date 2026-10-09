from collections.abc import Mapping
from dataclasses import dataclass
from typing import Final, Self

from homeassistant.components.button import ButtonDeviceClass, ButtonEntity
from homeassistant.const import Platform
from homeassistant.core import HomeAssistant
from homeassistant.helpers.device_registry import DeviceInfo
from homeassistant.helpers.entity_platform import AddConfigEntryEntitiesCallback, async_get_current_platform

from .change import ABSENT, EntityChange, JsonValue
from .command import CommandAction
from .config import ConfigReader, EntityConfig
from .entity import StewartEntity
from .errors import InvalidStateError
from .identity import EntityAddress
from .runtime import StewartConfigEntry
from .session import SessionRegistry


@dataclass(frozen=True, slots=True, kw_only=True)
class ButtonConfig:
    entity: EntityConfig
    device_class: ButtonDeviceClass | None

    @classmethod
    def read_from(cls, raw: Mapping[str, JsonValue]) -> Self:
        reader = ConfigReader(Platform.BUTTON, raw)
        config = cls(
            entity=EntityConfig.read_from(reader),
            device_class=reader.read_choice("device_class", ButtonDeviceClass),
        )
        reader.finish()
        return config


# Home Assistant owns a button's state, the time of its last press.
class StewartButton(StewartEntity[ButtonConfig, None], ButtonEntity):
    async def async_press(self) -> None:
        await self.run_command(CommandAction.PRESS)

    def _show_config(self, config: ButtonConfig) -> None:
        super()._show_config(config)
        self._attr_device_class = config.device_class

    def _check_change(self, change: EntityChange) -> None:
        if change.state is not ABSENT:
            raise InvalidStateError("A button takes no state.")

    def _convert_state(self, config: ButtonConfig, state: JsonValue) -> None:  # noqa: ARG002
        if state is not None:
            raise InvalidStateError("A button takes no state.")

    def _show_state(self, native: None) -> None:  # noqa: ARG002
        return


class ButtonExposure:
    domain: Final = Platform.BUTTON

    def read_config(self, raw: Mapping[str, JsonValue]) -> ButtonConfig:
        return ButtonConfig.read_from(raw)

    def create_entity(
        self, *, address: EntityAddress, config: ButtonConfig, sessions: SessionRegistry, device_info: DeviceInfo
    ) -> StewartButton:
        return StewartButton(address=address, config=config, sessions=sessions, device_info=device_info)


async def async_setup_entry(
    hass: HomeAssistant,  # noqa: ARG001
    entry: StewartConfigEntry,
    async_add_entities: AddConfigEntryEntitiesCallback,  # noqa: ARG001
) -> None:
    entry.runtime_data.entities.attach_platform(ButtonExposure(), async_get_current_platform())
