import argparse
import http.client
import json
import sys
import time
from dataclasses import dataclass
from http import HTTPStatus
from pathlib import Path
from urllib.parse import urlencode

type Json = dict[str, Json] | list[Json] | str | int | float | bool | None

HA_HOST = "homeassistant"
HA_PORT = 8123
CLIENT_ID = "http://stewart-e2e.local/"
EXPOSED_SENSOR = "sensor.stewart_hello_changes_seen"
EXPOSED_SENSOR_DEVICE = "Stewart · hello"
WATCHED_ENTITY = "input_boolean.stewart_e2e"
WAIT_SECONDS = 60.0
POLL_SECONDS = 0.5


class SmokeError(Exception):
    pass


@dataclass(frozen=True, slots=True, kw_only=True)
class Response:
    status: int
    body: str

    def read_object(self) -> dict[str, Json]:
        decoded: Json = json.loads(self.body)
        if not isinstance(decoded, dict):
            raise SmokeError(f"expected a JSON object, got {self.body}")
        return decoded


@dataclass(frozen=True, slots=True, kw_only=True)
class HomeAssistant:
    token: str | None = None

    def with_token(self, token: str) -> HomeAssistant:
        return HomeAssistant(token=token)

    def send(self, method: str, path: str, body: str | None = None, content_type: str = "application/json") -> Response:
        headers = {"Content-Type": content_type}
        if self.token is not None:
            headers["Authorization"] = f"Bearer {self.token}"
        connection = http.client.HTTPConnection(HA_HOST, HA_PORT, timeout=10)
        try:
            connection.request(method, path, body, headers)
            response = connection.getresponse()
            return Response(status=response.status, body=response.read().decode())
        finally:
            connection.close()

    def post_json(self, path: str, payload: dict[str, Json]) -> Response:
        return self.expect_success(self.send("POST", path, json.dumps(payload)), path)

    def post_form(self, path: str, fields: dict[str, str]) -> Response:
        return self.expect_success(
            self.send("POST", path, urlencode(fields), "application/x-www-form-urlencoded"), path
        )

    def get(self, path: str) -> Response:
        return self.send("GET", path)

    @staticmethod
    def expect_success(response: Response, path: str) -> Response:
        if response.status >= HTTPStatus.BAD_REQUEST:
            raise SmokeError(f"{path} answered {response.status}: {response.body}")
        return response


def read_text(source: dict[str, Json], key: str) -> str:
    value = source.get(key)
    if not isinstance(value, str):
        raise SmokeError(f"expected a string under {key} in {source}")
    return value


def provision(ha: HomeAssistant, token_file: Path) -> None:
    owner = ha.post_json(
        "/api/onboarding/users",
        {
            "client_id": CLIENT_ID,
            "name": "Stewart e2e",
            "username": "stewart",
            "password": "stewart-e2e",
            "language": "en",
        },
    )
    tokens = ha.post_form(
        "/auth/token",
        {
            "grant_type": "authorization_code",
            "code": read_text(owner.read_object(), "auth_code"),
            "client_id": CLIENT_ID,
        },
    )
    token = read_text(tokens.read_object(), "access_token")
    admin = ha.with_token(token)

    flow = admin.post_json("/api/config/config_entries/flow", {"handler": "stewart"}).read_object()
    entry = admin.post_json(f"/api/config/config_entries/flow/{read_text(flow, 'flow_id')}", {}).read_object()
    if read_text(entry, "type") != "create_entry":
        raise SmokeError(f"the stewart config flow did not create an entry: {entry}")

    token_file.write_text(token)


def read_sensor_state(ha: HomeAssistant) -> str | None:
    response = ha.get(f"/api/states/{EXPOSED_SENSOR}")
    if response.status == HTTPStatus.NOT_FOUND:
        return None
    return read_text(HomeAssistant.expect_success(response, EXPOSED_SENSOR).read_object(), "state")


def await_sensor(ha: HomeAssistant, expected: str) -> None:
    deadline = time.monotonic() + WAIT_SECONDS
    while (state := read_sensor_state(ha)) != expected:
        if time.monotonic() > deadline:
            raise SmokeError(f"{EXPOSED_SENSOR} is {state!r}, expected {expected!r}")
        time.sleep(POLL_SECONDS)

    template = f"{{{{ device_attr(device_id('{EXPOSED_SENSOR}'), 'name') }}}}"
    device = ha.post_json("/api/template", {"template": template}).body
    if device != EXPOSED_SENSOR_DEVICE:
        raise SmokeError(f"{EXPOSED_SENSOR} belongs to device {device!r}, expected {EXPOSED_SENSOR_DEVICE!r}")


def toggle_watched_entity(ha: HomeAssistant) -> None:
    ha.post_json("/api/services/input_boolean/toggle", {"entity_id": WATCHED_ENTITY})


def main() -> int:
    parser = argparse.ArgumentParser(description="Drives the real Home Assistant of the e2e smoke test.")
    parser.add_argument("--token-file", type=Path, required=True)
    steps = parser.add_subparsers(dest="step", required=True)
    steps.add_parser("provision")
    steps.add_parser("await-sensor").add_argument("state")
    steps.add_parser("toggle")
    arguments = parser.parse_args()
    token_file: Path = arguments.token_file

    try:
        if arguments.step == "provision":
            provision(HomeAssistant(), token_file)
        else:
            ha = HomeAssistant(token=token_file.read_text())
            if arguments.step == "await-sensor":
                await_sensor(ha, arguments.state)
            else:
                toggle_watched_entity(ha)
    except SmokeError as failure:
        sys.stderr.write(f"ha-e2e {arguments.step}: {failure}\n")
        return 1
    sys.stdout.write(f"ha-e2e {arguments.step}: ok\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
