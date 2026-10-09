from collections.abc import Mapping
from dataclasses import dataclass
from typing import Any, Final, Self

from homeassistant.components.switch import SwitchDeviceClass, SwitchEntity
from homeassistant.const import Platform
from homeassistant.core import HomeAssistant
from homeassistant.exceptions import HomeAssistantError
from homeassistant.helpers.device_registry import DeviceInfo
from homeassistant.helpers.entity_platform import AddConfigEntryEntitiesCallback, async_get_current_platform

from .change import JsonValue
from .config import ConfigReader, EntityConfig
from .entity import StewartEntity
from .errors import InvalidStateError
from .identity import EntityAddress
from .runtime import StewartConfigEntry
from .session import SessionRegistry


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
    async def async_turn_on(self, **kwargs: Any) -> None:  # noqa: ANN401, ARG002
        raise HomeAssistantError("Stewart switches do not take commands yet.")

    async def async_turn_off(self, **kwargs: Any) -> None:  # noqa: ANN401, ARG002
        raise HomeAssistantError("Stewart switches do not take commands yet.")

    def _show_config(self, config: SwitchConfig) -> None:
        super()._show_config(config)
        self._attr_device_class = config.device_class

    def _convert_state(self, config: SwitchConfig, state: JsonValue) -> bool | None:  # noqa: ARG002
        if state is None or isinstance(state, bool):
            return state
        raise InvalidStateError("A switch state must be true, false or null.")

    def _show_state(self, native: bool | None) -> None:  # noqa: FBT001
        self._attr_is_on = native


class SwitchExposure:
    domain: Final = Platform.SWITCH

    def read_config(self, raw: Mapping[str, JsonValue]) -> SwitchConfig:
        return SwitchConfig.read_from(raw)

    def create_entity(
        self, *, address: EntityAddress, config: SwitchConfig, sessions: SessionRegistry, device_info: DeviceInfo
    ) -> StewartSwitch:
        return StewartSwitch(address=address, config=config, sessions=sessions, device_info=device_info)


async def async_setup_entry(
    hass: HomeAssistant,  # noqa: ARG001
    entry: StewartConfigEntry,
    async_add_entities: AddConfigEntryEntitiesCallback,  # noqa: ARG001
) -> None:
    entry.runtime_data.entities.attach_platform(SwitchExposure(), async_get_current_platform())
