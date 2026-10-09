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

    # Instance and key cannot hold "-", so the key is everything after the last one.
    @classmethod
    def from_unique_id(cls, instance: str, unique_id: str) -> Self | None:
        prefix = f"{DOMAIN}-{instance}-"
        if not unique_id.startswith(prefix):
            return None
        app, separator, key = unique_id.removeprefix(prefix).rpartition("-")
        if not separator or not app or not key:
            return None
        return cls(instance=instance, app=app, key=key)

    @property
    def unique_id(self) -> str:
        return f"{DOMAIN}-{self.instance}-{self.app}-{self.key}"

    def __str__(self) -> str:
        return f"{self.app}/{self.key}"


@dataclass(frozen=True, slots=True, kw_only=True)
class KeptEntities:
    addresses: frozenset[EntityAddress]
    apps: frozenset[str]

    @classmethod
    def from_message(cls, msg: Mapping[str, Any]) -> Self:
        return cls(
            addresses=frozenset(
                EntityAddress(instance=msg["instance"], app=kept["app"], key=kept["key"]) for kept in msg["keep"]
            ),
            apps=frozenset(msg["keep_apps"]),
        )

    def keeps(self, address: EntityAddress) -> bool:
        return address in self.addresses or address.app in self.apps
