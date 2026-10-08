import pytest
from pytest_homeassistant_custom_component.common import MockConfigEntry

from custom_components.stewart.const import DOMAIN


@pytest.fixture(autouse=True)
def auto_enable_custom_integrations(enable_custom_integrations: None) -> None:
    return


@pytest.fixture
def config_entry() -> MockConfigEntry:
    return MockConfigEntry(domain=DOMAIN, title="Stewart")
