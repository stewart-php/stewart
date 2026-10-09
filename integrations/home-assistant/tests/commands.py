import asyncio
from typing import Any

from homeassistant.const import ATTR_ENTITY_ID
from homeassistant.core import Context, HomeAssistant
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket

from tests.golden import Golden

ANSWER_WAIT_SECONDS = 5


def call_service(
    hass: HomeAssistant,
    domain: str,
    service: str,
    entity_id: str,
    *,
    context: Context | None = None,
    **data: Any,  # noqa: ANN401
) -> asyncio.Task[Any]:
    return hass.async_create_task(
        hass.services.async_call(domain, service, {ATTR_ENTITY_ID: entity_id, **data}, blocking=True, context=context)
    )


def command_result(command_id: str, golden: str = "command-result.ok") -> dict[str, Any]:
    return {**Golden.load(golden).request, "command_id": command_id}


async def receive_command(client: MockHAClientWebSocket) -> dict[str, Any]:
    async with asyncio.timeout(ANSWER_WAIT_SECONDS):
        frame = await client.receive_json()
    assert frame["type"] == "event"
    event: dict[str, Any] = frame["event"]
    return event


def without_ids(event: dict[str, Any]) -> dict[str, Any]:
    return {**event, "command_id": None, "context": None}
