from dataclasses import dataclass

from homeassistant.config_entries import ConfigEntry

from .exposure import ExposedEntities
from .session import SessionRegistry


@dataclass(frozen=True, slots=True, kw_only=True)
class StewartRuntime:
    sessions: SessionRegistry
    entities: ExposedEntities


type StewartConfigEntry = ConfigEntry[StewartRuntime]
