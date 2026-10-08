import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Self

GOLDEN_DIRECTORY = Path(__file__).parent / "protocol"


@dataclass(frozen=True, kw_only=True)
class Golden:
    request: dict[str, Any]
    result: Any = None
    error_code: str | None = None
    event: dict[str, Any] | None = None

    @classmethod
    def load(cls, name: str) -> Self:
        exchange = json.loads((GOLDEN_DIRECTORY / f"{name}.json").read_text())
        return cls(
            request=exchange.get("request", {}),
            result=exchange.get("result"),
            error_code=exchange.get("error", {}).get("code"),
            event=exchange.get("event"),
        )
