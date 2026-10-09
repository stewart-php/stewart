from dataclasses import dataclass

from homeassistant.config_entries import ConfigEntry

from .session import SessionRegistry


@dataclass(frozen=True, slots=True, kw_only=True)
class StewartRuntime:
    sessions: SessionRegistry


type StewartConfigEntry = ConfigEntry[StewartRuntime]
