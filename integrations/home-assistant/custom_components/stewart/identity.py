from collections.abc import Mapping
from dataclasses import dataclass
from typing import Any, Self

from .const import DOMAIN


@dataclass(frozen=True, slots=True, kw_only=True)
class EntityAddress:
    instance: str
    app: str
    key: str

    @classmethod
    def from_message(cls, msg: Mapping[str, Any]) -> Self:
        return cls(instance=msg["instance"], app=msg["app"], key=msg["key"])

    @property
    def unique_id(self) -> str:
        return f"{DOMAIN}-{self.instance}-{self.app}-{self.key}"

    def __str__(self) -> str:
        return f"{self.app}/{self.key}"
