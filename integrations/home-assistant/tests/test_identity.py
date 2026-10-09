from custom_components.stewart.identity import EntityAddress, KeptEntities
from tests.golden import Golden


def test_address_reads_message() -> None:
    address = EntityAddress.from_message(Golden.load("entity-remove").request)

    assert address == EntityAddress(instance="default", app="lights", key="night_mode")


def test_unique_id_joins_instance_app_and_key() -> None:
    address = EntityAddress(instance="default", app="sun-tracker", key="elevation")

    assert address.unique_id == "stewart-default-sun-tracker-elevation"


def test_address_names_app_and_key() -> None:
    assert str(EntityAddress(instance="default", app="lights", key="night_mode")) == "lights/night_mode"


def test_address_reads_unique_id_with_dashed_app() -> None:
    address = EntityAddress.from_unique_id("default", "stewart-default-sun-tracker-elevation")

    assert address == EntityAddress(instance="default", app="sun-tracker", key="elevation")


def test_unique_id_of_other_instance_has_no_address() -> None:
    assert EntityAddress.from_unique_id("default", "stewart-attic-climate-humidity") is None


def test_kept_entities_keep_listed_keys_and_apps() -> None:
    kept = KeptEntities.from_message(Golden.load("entity-reconcile").request)

    assert kept.keeps(EntityAddress(instance="default", app="lights", key="night_mode"))
    assert kept.keeps(EntityAddress(instance="default", app="heating", key="boiler_load"))
    assert not kept.keeps(EntityAddress(instance="default", app="presence", key="anyone_home"))
