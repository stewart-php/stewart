from custom_components.stewart.change import ABSENT, EntityChange


def test_change_keeps_absent_fields_absent() -> None:
    change = EntityChange.from_message({"available": False})

    assert change == EntityChange(state=ABSENT, attributes=ABSENT, available=False)


def test_change_keeps_null_state_apart_from_absent() -> None:
    change = EntityChange.from_message({"state": None, "attributes": {}})

    assert change.state is None
    assert change.attributes == {}
    assert change.available is ABSENT
