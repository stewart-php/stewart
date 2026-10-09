from typing import Any

from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket

from tests.golden import Golden


async def send_request(client: MockHAClientWebSocket, request: dict[str, Any]) -> dict[str, Any]:
    await client.send_json_auto_id(request)
    response: dict[str, Any] = await client.receive_json()
    return response


async def subscribe(client: MockHAClientWebSocket) -> dict[str, Any]:
    return await send_request(client, Golden.load("session-subscribe").request)
