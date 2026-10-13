from collections.abc import Mapping
from dataclasses import dataclass
from typing import Self

from homeassistant.components.button import ButtonDeviceClass, ButtonEntity
from homeassistant.const import Platform

from .change import ABSENT, EntityChange, JsonValue
from .command import CommandAction
from .config import ConfigReader, EntityConfig
from .entity import StewartEntity
from .errors import InvalidStateError
from .platforms import attach


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
    domain = Platform.BUTTON
    config_type = ButtonConfig

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


async_setup_entry = attach(StewartButton)
