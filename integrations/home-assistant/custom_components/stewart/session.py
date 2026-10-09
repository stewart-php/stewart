import asyncio
import logging
import secrets
from dataclasses import dataclass, field
from typing import Any

from homeassistant.components import websocket_api
from homeassistant.core import HomeAssistant, callback
from homeassistant.exceptions import HomeAssistantError
from homeassistant.helpers.dispatcher import async_dispatcher_send

from .command import CommandAnswer, EntityCommand, PendingCommands
from .const import SIGNAL_SESSION_CHANGED
from .errors import CommandNotFoundError, NoSessionError

_LOGGER = logging.getLogger(__name__)


@dataclass(frozen=True, slots=True, kw_only=True)
class Session:
    instance: str
    connection: websocket_api.ActiveConnection
    subscription_id: int
    stewart_version: str
    command_timeout: float
    pending_commands: PendingCommands = field(default_factory=PendingCommands, compare=False)

    async def run_command(self, command: EntityCommand) -> None:
        command_id = secrets.token_hex(8)
        pending = self.pending_commands.open_command(command_id)
        try:
            self.send_event(command.as_event(command_id))
            async with asyncio.timeout(self.command_timeout):
                answer = await pending
        except TimeoutError as error:
            raise HomeAssistantError(
                f"Stewart did not answer the command for {command.address} within {self.command_timeout:g} s."
            ) from error
        finally:
            self.pending_commands.forget_command(command_id)
        if not answer.ok:
            raise HomeAssistantError(answer.message or f"Stewart refused the command for {command.address}.")

    @callback
    def send_event(self, event: dict[str, Any]) -> None:
        self.connection.send_message(websocket_api.event_message(self.subscription_id, event))

    @callback
    def end(self) -> None:
        if (unsubscribe := self.connection.subscriptions.pop(self.subscription_id, None)) is not None:
            unsubscribe()


class SessionRegistry:
    def __init__(self, hass: HomeAssistant) -> None:
        self._hass = hass
        self._sessions: dict[str, Session] = {}

    @callback
    def has_session(self, instance: str) -> bool:
        return instance in self._sessions

    @callback
    def require_session(self, instance: str, connection: websocket_api.ActiveConnection) -> Session:
        session = self._sessions.get(instance)
        if session is None or session.connection is not connection:
            raise NoSessionError.for_instance(instance)
        return session

    @callback
    def open_session(self, session: Session) -> None:
        replaced = self._sessions.get(session.instance)
        self._sessions[session.instance] = session
        _LOGGER.info("Stewart %s opened a session for instance %s", session.stewart_version, session.instance)
        if replaced is None:
            async_dispatcher_send(self._hass, SIGNAL_SESSION_CHANGED.format(session.instance))
            return
        _LOGGER.warning("Another Stewart connection took over the session for instance %s", session.instance)
        replaced.send_event({"type": "session_replaced"})
        replaced.end()

    async def run_command(self, command: EntityCommand) -> None:
        if (session := self._sessions.get(command.address.instance)) is None:
            raise HomeAssistantError(f"Stewart has no session for instance {command.address.instance}.")
        await session.run_command(command)

    @callback
    def answer_command(self, connection: websocket_api.ActiveConnection, answer: CommandAnswer) -> None:
        sessions = [session for session in self._sessions.values() if session.connection is connection]
        if not sessions:
            raise NoSessionError.for_connection()
        if not any(session.pending_commands.settle_command(answer) for session in sessions):
            raise CommandNotFoundError.for_command(answer.command_id)

    @callback
    def close_session(self, session: Session) -> None:
        session.pending_commands.fail_all_commands()
        if self._sessions.get(session.instance) is session:
            del self._sessions[session.instance]
            async_dispatcher_send(self._hass, SIGNAL_SESSION_CHANGED.format(session.instance))

    @callback
    def end_all_sessions(self) -> None:
        for session in list(self._sessions.values()):
            session.end()
