from typing import Final

from homeassistant.const import Platform

DOMAIN: Final = "stewart"
PROTOCOL: Final = 1
MANUFACTURER: Final = "Stewart"
DEFAULT_INSTANCE: Final = "default"
PLATFORMS: Final = (Platform.SENSOR,)

INSTANCE_PATTERN: Final = r"\A[a-z][a-z0-9_]{0,63}\Z"
APP_PATTERN: Final = r"\A[a-z][a-z0-9_-]*\Z"
KEY_PATTERN: Final = r"\A[a-z][a-z0-9_]{0,63}\Z"
MAX_COMMAND_TIMEOUT_SECONDS: Final = 300

SIGNAL_SESSION_CHANGED: Final = "stewart_session_changed_{}"

ERR_PROTOCOL_MISMATCH: Final = "protocol_mismatch"
ERR_NO_SESSION: Final = "no_session"
ERR_NOT_FOUND: Final = "not_found"
ERR_INVALID_CONFIG: Final = "invalid_config"
ERR_INVALID_STATE: Final = "invalid_state"
