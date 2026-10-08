from typing import Final

DOMAIN: Final = "stewart"
PROTOCOL: Final = 1

INSTANCE_PATTERN: Final = r"\A[a-z][a-z0-9_]{0,63}\Z"
MAX_COMMAND_TIMEOUT_SECONDS: Final = 300

ERR_PROTOCOL_MISMATCH: Final = "protocol_mismatch"
