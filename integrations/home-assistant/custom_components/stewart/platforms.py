from collections.abc import Mapping
from typing import Any, Protocol

from homeassistant.helpers.device_registry import DeviceInfo

from .change import JsonValue
from .entity import PlatformConfig, StewartEntity
from .identity import EntityAddress
from .session import SessionRegistry


class ExposurePlatform[ConfigT: PlatformConfig](Protocol):
    @property
    def domain(self) -> str: ...

    def read_config(self, raw: Mapping[str, JsonValue]) -> ConfigT: ...

    def create_entity(
        self, *, address: EntityAddress, config: ConfigT, sessions: SessionRegistry, device_info: DeviceInfo
    ) -> StewartEntity[ConfigT, Any]: ...
