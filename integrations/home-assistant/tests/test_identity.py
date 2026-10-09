from custom_components.stewart.identity import EntityAddress
from tests.golden import Golden


def test_address_reads_message() -> None:
    address = EntityAddress.from_message(Golden.load("entity-remove").request)

    assert address == EntityAddress(instance="default", app="lights", key="night_mode")


def test_unique_id_joins_instance_app_and_key() -> None:
    address = EntityAddress(instance="default", app="sun-tracker", key="elevation")

    assert address.unique_id == "stewart-default-sun-tracker-elevation"


def test_address_names_app_and_key() -> None:
    assert str(EntityAddress(instance="default", app="lights", key="night_mode")) == "lights/night_mode"
