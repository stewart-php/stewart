import pytest
from homeassistant.components.button import DOMAIN as BUTTON_DOMAIN
from homeassistant.components.button import SERVICE_PRESS
from homeassistant.components.switch import DOMAIN as SWITCH_DOMAIN
from homeassistant.const import SERVICE_TURN_OFF, SERVICE_TURN_ON
from homeassistant.core import Context, HomeAssistant
from homeassistant.exceptions import HomeAssistantError
from pytest_homeassistant_custom_component.common import MockConfigEntry, MockUser
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket, WebSocketGenerator

from tests.commands import call_service, command_result, receive_command, without_ids
from tests.exchange import send_request, subscribe
from tests.golden import Golden

SWITCH_ENTITY_ID = "switch.stewart_lights_night_mode"
BUTTON_ENTITY_ID = "button.stewart_lights_all_off"


@pytest.fixture
async def switch_client(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> MockHAClientWebSocket:
    request = {**Golden.load("entity-upsert.switch").request, "state": False}
    assert (await send_request(session_client, request))["success"]
    return session_client


async def test_turn_on_sends_golden_command(
    hass: HomeAssistant, hass_admin_user: MockUser, switch_client: MockHAClientWebSocket
) -> None:
    context = Context(user_id=hass_admin_user.id)
    call = call_service(hass, SWITCH_DOMAIN, SERVICE_TURN_ON, SWITCH_ENTITY_ID, context=context)

    event = await receive_command(switch_client)
    await send_request(switch_client, command_result(event["command_id"]))
    await call

    golden = Golden.load("event-command.switch").event
    assert golden is not None
    assert without_ids(event) == without_ids(golden)
    assert event["context"] == {"id": context.id, "parent_id": None, "user_id": hass_admin_user.id}


async def test_accepted_command_applies_state(hass: HomeAssistant, switch_client: MockHAClientWebSocket) -> None:
    call = call_service(hass, SWITCH_DOMAIN, SERVICE_TURN_ON, SWITCH_ENTITY_ID)

    event = await receive_command(switch_client)
    response = await send_request(switch_client, command_result(event["command_id"]))
    await call

    assert response["result"] == Golden.load("command-result.ok").result
    state = hass.states.get(SWITCH_ENTITY_ID)
    assert state is not None
    assert state.state == "on"


async def test_turn_off_sends_turn_off(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, Golden.load("entity-upsert.switch").request)
    call = call_service(hass, SWITCH_DOMAIN, SERVICE_TURN_OFF, SWITCH_ENTITY_ID)

    event = await receive_command(session_client)
    await send_request(session_client, command_result(event["command_id"]))
    await call

    assert event["action"] == "turn_off"
    state = hass.states.get(SWITCH_ENTITY_ID)
    assert state is not None
    assert state.state == "off"


async def test_rejected_command_fails_service_call(hass: HomeAssistant, switch_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("command-result.rejected")
    call = call_service(hass, SWITCH_DOMAIN, SERVICE_TURN_ON, SWITCH_ENTITY_ID)

    event = await receive_command(switch_client)
    response = await send_request(switch_client, command_result(event["command_id"], "command-result.rejected"))

    assert response["result"] == golden.result
    with pytest.raises(HomeAssistantError, match=golden.request["message"]):
        await call
    state = hass.states.get(SWITCH_ENTITY_ID)
    assert state is not None
    assert state.state == "off"


async def test_unanswered_command_times_out(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    client = await hass_ws_client(hass)
    await send_request(client, {**Golden.load("session-subscribe").request, "command_timeout": 0.05})
    await send_request(client, Golden.load("entity-upsert.switch").request)
    call = call_service(hass, SWITCH_DOMAIN, SERVICE_TURN_OFF, SWITCH_ENTITY_ID)

    event = await receive_command(client)

    with pytest.raises(HomeAssistantError, match="did not answer"):
        await call
    response = await send_request(client, command_result(event["command_id"]))
    assert response["error"]["code"] == Golden.load("command-result.not-found").error_code


async def test_session_end_fails_pending_command(hass: HomeAssistant, switch_client: MockHAClientWebSocket) -> None:
    call = call_service(hass, SWITCH_DOMAIN, SERVICE_TURN_ON, SWITCH_ENTITY_ID)
    await receive_command(switch_client)

    await switch_client.close()

    with pytest.raises(HomeAssistantError, match="session ended"):
        await call


async def test_takeover_fails_pending_command(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, switch_client: MockHAClientWebSocket
) -> None:
    call = call_service(hass, SWITCH_DOMAIN, SERVICE_TURN_ON, SWITCH_ENTITY_ID)
    event = await receive_command(switch_client)

    await subscribe(await hass_ws_client(hass))

    with pytest.raises(HomeAssistantError, match="session ended"):
        await call
    assert (await switch_client.receive_json())["event"] == Golden.load("event-session-replaced").event
    response = await send_request(switch_client, command_result(event["command_id"]))
    assert response["error"]["code"] == "no_session"


async def test_unknown_command_is_not_found(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    golden = Golden.load("command-result.not-found")

    response = await send_request(session_client, golden.request)

    assert response["error"]["code"] == golden.error_code


async def test_answer_without_session_is_refused(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, loaded_entry: MockConfigEntry
) -> None:
    client = await hass_ws_client(hass)

    response = await send_request(client, Golden.load("command-result.ok").request)

    assert response["error"]["code"] == "no_session"


async def test_answer_from_other_connection_is_not_found(
    hass: HomeAssistant, hass_ws_client: WebSocketGenerator, switch_client: MockHAClientWebSocket
) -> None:
    other_client = await hass_ws_client(hass)
    await send_request(other_client, {**Golden.load("session-subscribe").request, "instance": "other"})
    call = call_service(hass, SWITCH_DOMAIN, SERVICE_TURN_ON, SWITCH_ENTITY_ID)
    event = await receive_command(switch_client)

    response = await send_request(other_client, command_result(event["command_id"]))
    await send_request(switch_client, command_result(event["command_id"]))
    await call

    assert response["error"]["code"] == "not_found"


async def test_press_sends_golden_command(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, Golden.load("entity-upsert.button").request)
    call = call_service(hass, BUTTON_DOMAIN, SERVICE_PRESS, BUTTON_ENTITY_ID)

    event = await receive_command(session_client)
    await send_request(session_client, command_result(event["command_id"]))
    await call

    golden = Golden.load("event-command.button").event
    assert golden is not None
    assert without_ids(event) == without_ids(golden)


async def test_rejected_press_fails_service_call(hass: HomeAssistant, session_client: MockHAClientWebSocket) -> None:
    await send_request(session_client, Golden.load("entity-upsert.button").request)
    call = call_service(hass, BUTTON_DOMAIN, SERVICE_PRESS, BUTTON_ENTITY_ID)

    event = await receive_command(session_client)
    await send_request(session_client, {**command_result(event["command_id"]), "ok": False})

    with pytest.raises(HomeAssistantError, match="refused"):
        await call
