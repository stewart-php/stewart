from collections.abc import Mapping
from dataclasses import dataclass
from typing import Any, Self

from homeassistant.helpers.restore_state import ExtraStoredData

from .change import JsonValue


# Kept apart from the state Home Assistant stores, which loses attributes while the entity is unavailable.
@dataclass(frozen=True, slots=True, kw_only=True)
class StoredExposure(ExtraStoredData):
    state: JsonValue
    attributes: dict[str, JsonValue]

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> Self | None:
        attributes = data.get("attributes")
        if "state" not in data or not isinstance(attributes, dict):
            return None
        return cls(state=data["state"], attributes=attributes)

    def as_dict(self) -> dict[str, Any]:
        return {"state": self.state, "attributes": self.attributes}
