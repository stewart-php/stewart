from dataclasses import asdict, dataclass
from typing import Any

from .change import JsonValue


@dataclass(frozen=True, slots=True, kw_only=True)
class EntitySnapshot:
    entity_id: str
    state: JsonValue
    attributes: dict[str, JsonValue]
    available: bool

    def as_result(self) -> dict[str, Any]:
        return asdict(self)
