from collections.abc import Mapping
from dataclasses import dataclass
from typing import Any, Self

from homeassistant.components.switch import SwitchDeviceClass, SwitchEntity
from homeassistant.const import Platform

from .change import EntityChange, JsonValue
from .command import CommandAction
from .config import ConfigReader, EntityConfig
from .entity import StewartEntity
from .errors import InvalidStateError
from .platforms import attach


@dataclass(frozen=True, slots=True, kw_only=True)
class SwitchConfig:
    entity: EntityConfig
    device_class: SwitchDeviceClass | None

    @classmethod
    def read_from(cls, raw: Mapping[str, JsonValue]) -> Self:
        reader = ConfigReader(Platform.SWITCH, raw)
        config = cls(
            entity=EntityConfig.read_from(reader),
            device_class=reader.read_choice("device_class", SwitchDeviceClass),
        )
        reader.finish()
        return config


class StewartSwitch(StewartEntity[SwitchConfig, bool | None], SwitchEntity):
    domain = Platform.SWITCH
    config_type = SwitchConfig

    async def async_turn_on(self, **kwargs: Any) -> None:  # noqa: ANN401, ARG002
        await self.run_command(CommandAction.TURN_ON)
        self.apply_change(EntityChange(state=True))
        self.publish()

    async def async_turn_off(self, **kwargs: Any) -> None:  # noqa: ANN401, ARG002
        await self.run_command(CommandAction.TURN_OFF)
        self.apply_change(EntityChange(state=False))
        self.publish()

    def _show_config(self, config: SwitchConfig) -> None:
        super()._show_config(config)
        self._attr_device_class = config.device_class

    def _convert_state(self, config: SwitchConfig, state: JsonValue) -> bool | None:  # noqa: ARG002
        if state is None or isinstance(state, bool):
            return state
        raise InvalidStateError("A switch state must be true, false or null.")

    def _show_state(self, native: bool | None) -> None:  # noqa: FBT001
        self._attr_is_on = native


async_setup_entry = attach(StewartSwitch)
