import asyncio
from dataclasses import dataclass
from typing import Any

import pytest
from homeassistant.core import HomeAssistant
from homeassistant.exceptions import HomeAssistantError
from pytest_homeassistant_custom_component.typing import MockHAClientWebSocket

from custom_components.stewart.change import JsonValue
from tests.commands import call_service, command_result, receive_command, without_ids
from tests.exchange import send_request
from tests.golden import Golden


@dataclass(frozen=True, kw_only=True)
class MomentPlatform:
    domain: str
    entity_id: str
    service_field: str
    shown_state: str
    unfit_states: tuple[JsonValue, ...]

    def load_upsert(self, **overrides: object) -> dict[str, Any]:
        return {**Golden.load(f"entity-upsert.{self.domain}").request, **overrides}

    def call_set_value(self, hass: HomeAssistant, value: str) -> asyncio.Task[Any]:
        return call_service(hass, self.domain, "set_value", self.entity_id, context=None, **{self.service_field: value})

    def load_commanded_value(self) -> str:
        event = Golden.load(f"event-command.{self.domain}").event
        assert event is not None
        value: str = event["data"]["value"]
        return value


TIME = MomentPlatform(
    domain="time",
    entity_id="time.stewart_wakeup_alarm",
    service_field="time",
    shown_state="06:45:00",
    unfit_states=("noon", "25:00:00", 645, True),
)
DATE = MomentPlatform(
    domain="date",
    entity_id="date.stewart_garden_next_mowing",
    service_field="date",
    shown_state="2026-10-12",
    unfit_states=("2026-13-01", "12/10/2026", 20261012),
)
DATETIME = MomentPlatform(
    domain="datetime",
    entity_id="datetime.stewart_garden_last_watered",
    service_field="datetime",
    shown_state="2026-10-09T05:15:00+00:00",
    unfit_states=("2026-10-09T07:15:00", "2026-10-09", 1760000000),
)
PLATFORMS = pytest.mark.parametrize("platform", [TIME, DATE, DATETIME], ids=lambda platform: platform.domain)


@PLATFORMS
async def test_upsert_matches_golden(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, platform: MomentPlatform
) -> None:
    golden = Golden.load(f"entity-upsert.{platform.domain}")

    response = await send_request(session_client, golden.request)

    assert response["result"] == golden.result
    state = hass.states.get(platform.entity_id)
    assert state is not None
    assert state.state == platform.shown_state


@PLATFORMS
async def test_null_state_shows_unknown(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, platform: MomentPlatform
) -> None:
    await send_request(session_client, platform.load_upsert(state=None))

    state = hass.states.get(platform.entity_id)
    assert state is not None
    assert state.state == "unknown"


@PLATFORMS
async def test_refuses_unfit_states(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, platform: MomentPlatform
) -> None:
    for unfit_state in platform.unfit_states:
        response = await send_request(session_client, platform.load_upsert(state=unfit_state))

        assert response["error"]["code"] == "invalid_state", unfit_state


@PLATFORMS
async def test_refuses_platform_config(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, platform: MomentPlatform
) -> None:
    response = await send_request(session_client, platform.load_upsert(config={"device_class": "timestamp"}))

    assert response["error"]["code"] == "invalid_config"


@PLATFORMS
async def test_set_value_sends_golden_command(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, platform: MomentPlatform
) -> None:
    await send_request(session_client, platform.load_upsert())
    value = platform.load_commanded_value()
    call = platform.call_set_value(hass, value)

    event = await receive_command(session_client)
    response = await send_request(session_client, command_result(event["command_id"]))
    await call

    assert response["success"]
    assert without_ids(event) == without_ids(Golden.load(f"event-command.{platform.domain}").event or {})
    state = hass.states.get(platform.entity_id)
    assert state is not None
    assert state.state == value


@PLATFORMS
async def test_rejected_set_value_keeps_state(
    hass: HomeAssistant, session_client: MockHAClientWebSocket, platform: MomentPlatform
) -> None:
    await send_request(session_client, platform.load_upsert())
    call = platform.call_set_value(hass, platform.load_commanded_value())

    event = await receive_command(session_client)
    await send_request(session_client, command_result(event["command_id"], "command-result.rejected"))

    with pytest.raises(HomeAssistantError):
        await call
    state = hass.states.get(platform.entity_id)
    assert state is not None
    assert state.state == platform.shown_state
