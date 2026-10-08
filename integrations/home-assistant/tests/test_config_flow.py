from homeassistant.config_entries import SOURCE_USER
from homeassistant.core import HomeAssistant
from homeassistant.data_entry_flow import FlowResultType
from pytest_homeassistant_custom_component.common import MockConfigEntry

from custom_components.stewart.const import DOMAIN


async def test_user_step_creates_entry(hass: HomeAssistant) -> None:
    form = await hass.config_entries.flow.async_init(DOMAIN, context={"source": SOURCE_USER})
    assert form["type"] is FlowResultType.FORM

    result = await hass.config_entries.flow.async_configure(form["flow_id"], {})

    assert result["type"] is FlowResultType.CREATE_ENTRY
    assert result["title"] == "Stewart"
    assert result["data"] == {}


async def test_second_entry_is_refused(hass: HomeAssistant, config_entry: MockConfigEntry) -> None:
    config_entry.add_to_hass(hass)

    result = await hass.config_entries.flow.async_init(DOMAIN, context={"source": SOURCE_USER})

    assert result["type"] is FlowResultType.ABORT
    assert result["reason"] == "single_instance_allowed"
