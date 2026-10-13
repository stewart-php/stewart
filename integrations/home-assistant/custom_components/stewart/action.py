import re
from collections.abc import Callable, Mapping
from dataclasses import dataclass
from enum import StrEnum
from functools import partial
from typing import Any, Final, Self

import voluptuous as vol
from homeassistant.core import HomeAssistant, ServiceCall, callback
from homeassistant.helpers import config_validation as cv
from homeassistant.helpers.service import async_set_service_schema

from .change import JsonValue
from .command import CommandAction, EntityCommand
from .config import ConfigReader
from .const import DOMAIN
from .errors import ActionTakenError
from .identity import EntityAddress, KeptEntities
from .session import SessionRegistry

ACTION_PLATFORM: Final = "action"
FIELD_NAME_PATTERN: Final = re.compile(r"\A[a-z][a-z0-9_]{0,63}\Z")


class ActionFieldType(StrEnum):
    TEXT = "text"
    NUMBER = "number"
    BOOLEAN = "boolean"
    SELECT = "select"
    ENTITY = "entity"


@dataclass(frozen=True, slots=True, kw_only=True)
class ActionField:
    name: str
    type: ActionFieldType
    required: bool
    description: str | None
    options: tuple[str, ...] | None

    @classmethod
    def read_from(cls, raw: Mapping[str, JsonValue]) -> Self:
        reader = ConfigReader("action field", raw)
        name = reader.read_str("name")
        if name is None or not FIELD_NAME_PATTERN.match(name):
            raise reader.fail("name must be lower snake case")
        field_type = reader.read_choice("type", ActionFieldType)
        if field_type is None:
            raise reader.fail(f"{name} needs type")
        field = cls(
            name=name,
            type=field_type,
            required=reader.read_bool("required", default=False),
            description=reader.read_str("description"),
            options=reader.read_str_list("options"),
        )
        if (field_type is ActionFieldType.SELECT) != (field.options is not None):
            raise reader.fail(f"{name} takes options exactly when its type is select")
        reader.finish()
        return field

    def build_validator(self) -> Callable[[Any], Any]:
        validators: dict[ActionFieldType, Callable[[Any], Any]] = {
            ActionFieldType.TEXT: cv.string,
            ActionFieldType.NUMBER: vol.Coerce(float),
            ActionFieldType.BOOLEAN: cv.boolean,
            ActionFieldType.SELECT: vol.In(self.options or ()),
            ActionFieldType.ENTITY: cv.entity_id,
        }
        return validators[self.type]

    def describe(self) -> dict[str, Any]:
        selectors: dict[ActionFieldType, dict[str, Any]] = {
            ActionFieldType.TEXT: {"text": {}},
            ActionFieldType.NUMBER: {"number": {"mode": "box"}},
            ActionFieldType.BOOLEAN: {"boolean": {}},
            ActionFieldType.SELECT: {"select": {"options": list(self.options or ())}},
            ActionFieldType.ENTITY: {"entity": {}},
        }
        return {
            "name": self.name,
            "description": self.description or "",
            "required": self.required,
            "selector": selectors[self.type],
        }


@dataclass(frozen=True, slots=True, kw_only=True)
class ActionConfig:
    name: str | None
    description: str | None
    fields: tuple[ActionField, ...]

    @classmethod
    def read_from(cls, raw: Mapping[str, JsonValue]) -> Self:
        reader = ConfigReader(ACTION_PLATFORM, raw)
        config = cls(
            name=reader.read_str("name"),
            description=reader.read_str("description"),
            fields=tuple(ActionField.read_from(field) for field in reader.read_object_list("fields")),
        )
        names = [field.name for field in config.fields]
        if len(set(names)) != len(names):
            raise reader.fail("field names must be distinct")
        reader.finish()
        return config

    def build_schema(self) -> vol.Schema:
        return vol.Schema(
            {
                (vol.Required if field.required else vol.Optional)(field.name): field.build_validator()
                for field in self.fields
            }
        )

    def describe(self, fallback_name: str) -> dict[str, Any]:
        return {
            "name": self.name or fallback_name,
            "description": self.description or "",
            "fields": {field.name: field.describe() for field in self.fields},
        }


# Services outlive a session, so a call made while Stewart is away fails like an entity command does.
class ExposedActions:
    def __init__(self, hass: HomeAssistant, sessions: SessionRegistry) -> None:
        self._hass = hass
        self._sessions = sessions
        self._addresses: dict[str, EntityAddress] = {}

    @callback
    def register(self, address: EntityAddress, config: ActionConfig) -> str:
        service = self._name_service(address)
        holder = self._addresses.get(service)
        if holder is not None and holder != address:
            raise ActionTakenError.for_service(f"{DOMAIN}.{service}", holder)
        self._hass.services.async_register(DOMAIN, service, partial(self._call, address), schema=config.build_schema())
        async_set_service_schema(self._hass, DOMAIN, service, config.describe(address.key))
        self._addresses[service] = address
        return f"{DOMAIN}.{service}"

    @callback
    def holds(self, address: EntityAddress) -> bool:
        return self._addresses.get(self._name_service(address)) == address

    @callback
    def remove(self, address: EntityAddress) -> bool:
        if not self.holds(address):
            return False
        service = self._name_service(address)
        del self._addresses[service]
        self._hass.services.async_remove(DOMAIN, service)
        return True

    @callback
    def reconcile(self, instance: str, kept: KeptEntities) -> None:
        for address in list(self._addresses.values()):
            if address.instance == instance and not kept.keeps(address):
                self.remove(address)

    @callback
    def remove_all(self) -> None:
        for address in list(self._addresses.values()):
            self.remove(address)

    async def _call(self, address: EntityAddress, call: ServiceCall) -> None:
        await self._sessions.run_command(
            EntityCommand(address=address, action=CommandAction.CALL, data=dict(call.data), context=call.context)
        )

    @staticmethod
    def _name_service(address: EntityAddress) -> str:
        return f"{address.app}_{address.key}".replace("-", "_")
