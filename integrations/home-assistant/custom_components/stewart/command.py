import asyncio
from collections.abc import Mapping
from dataclasses import dataclass
from enum import StrEnum
from typing import Any, Self

from homeassistant.core import Context, callback
from homeassistant.exceptions import HomeAssistantError

from .change import JsonValue
from .identity import EntityAddress


class CommandAction(StrEnum):
    TURN_ON = "turn_on"
    TURN_OFF = "turn_off"
    PRESS = "press"
    SET_VALUE = "set_value"
    SELECT_OPTION = "select_option"


@dataclass(frozen=True, slots=True, kw_only=True)
class EntityCommand:
    address: EntityAddress
    action: CommandAction
    data: dict[str, JsonValue]
    context: Context

    def as_event(self, command_id: str) -> dict[str, Any]:
        return {
            "type": "command",
            "command_id": command_id,
            "app": self.address.app,
            "key": self.address.key,
            "action": self.action,
            "data": self.data,
            "context": dict(self.context.as_dict()),
        }


@dataclass(frozen=True, slots=True, kw_only=True)
class CommandAnswer:
    command_id: str
    ok: bool
    message: str | None

    @classmethod
    def from_message(cls, msg: Mapping[str, Any]) -> Self:
        return cls(command_id=msg["command_id"], ok=msg["ok"], message=msg.get("message"))


class PendingCommands:
    def __init__(self) -> None:
        self._answers: dict[str, asyncio.Future[CommandAnswer]] = {}

    @callback
    def open_command(self, command_id: str) -> asyncio.Future[CommandAnswer]:
        answer: asyncio.Future[CommandAnswer] = asyncio.get_running_loop().create_future()
        self._answers[command_id] = answer
        return answer

    @callback
    def settle_command(self, answer: CommandAnswer) -> bool:
        pending = self._answers.pop(answer.command_id, None)
        if pending is None or pending.done():
            return False
        pending.set_result(answer)
        return True

    @callback
    def forget_command(self, command_id: str) -> None:
        self._answers.pop(command_id, None)

    @callback
    def fail_all_commands(self) -> None:
        for pending in self._answers.values():
            if not pending.done():
                pending.set_exception(HomeAssistantError("The Stewart session ended before the command was answered."))
        self._answers.clear()
