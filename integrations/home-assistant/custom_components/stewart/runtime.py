from dataclasses import dataclass

from homeassistant.config_entries import ConfigEntry

from .action import ExposedActions
from .exposure import ExposedEntities
from .session import SessionRegistry


@dataclass(frozen=True, slots=True, kw_only=True)
class StewartRuntime:
    sessions: SessionRegistry
    entities: ExposedEntities
    actions: ExposedActions


type StewartConfigEntry = ConfigEntry[StewartRuntime]
