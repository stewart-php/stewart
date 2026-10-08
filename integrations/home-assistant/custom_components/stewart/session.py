import logging
from dataclasses import dataclass
from typing import Any

from homeassistant.components import websocket_api
from homeassistant.config_entries import ConfigEntry
from homeassistant.core import callback

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
    def __init__(self) -> None:
        self._sessions: dict[str, Session] = {}

    @callback
    def open_session(self, session: Session) -> None:
        replaced = self._sessions.get(session.instance)
        self._sessions[session.instance] = session
        _LOGGER.info("Stewart %s opened a session for instance %s", session.stewart_version, session.instance)
        if replaced is None:
            return
        _LOGGER.warning("Another Stewart connection took over the session for instance %s", session.instance)
        replaced.send_event({"type": "session_replaced"})
        replaced.end()

    @callback
    def close_session(self, session: Session) -> None:
        if self._sessions.get(session.instance) is session:
            del self._sessions[session.instance]

    @callback
    def end_all_sessions(self) -> None:
        for session in list(self._sessions.values()):
            session.end()


type StewartConfigEntry = ConfigEntry[SessionRegistry]
