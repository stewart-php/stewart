import logging
from dataclasses import dataclass
from typing import Any

from homeassistant.components import websocket_api
from homeassistant.core import HomeAssistant, callback
from homeassistant.helpers.dispatcher import async_dispatcher_send

from .const import SIGNAL_SESSION_CHANGED
from .errors import NoSessionError

_LOGGER = logging.getLogger(__name__)


@dataclass(frozen=True, slots=True, kw_only=True)
class Session:
    instance: str
    connection: websocket_api.ActiveConnection
    subscription_id: int
    stewart_version: str
    command_timeout: float

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

    @callback
    def close_session(self, session: Session) -> None:
        if self._sessions.get(session.instance) is session:
            del self._sessions[session.instance]
            async_dispatcher_send(self._hass, SIGNAL_SESSION_CHANGED.format(session.instance))

    @callback
    def end_all_sessions(self) -> None:
        for session in list(self._sessions.values()):
            session.end()
