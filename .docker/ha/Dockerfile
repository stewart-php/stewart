# syntax=docker/dockerfile:1
ARG PYTHON_VERSION=3.14

# The Home Assistant integration's toolchain; HA picks requirements/ha-<HA>.txt (min or latest).
FROM python:${PYTHON_VERSION}-slim

ARG HA=latest

COPY integrations/home-assistant/requirements/ha-${HA}.txt /tmp/requirements.txt

# A few Home Assistant dependencies ship no wheel for every Python, so pip may compile them.
RUN apt-get update \
    && apt-get install -y --no-install-recommends gcc libc6-dev \
    && pip install --no-cache-dir --root-user-action=ignore -r /tmp/requirements.txt \
    && apt-get purge -y --auto-remove gcc libc6-dev \
    && rm -rf /var/lib/apt/lists/* /tmp/requirements.txt

ENV HOME=/tmp \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONPYCACHEPREFIX=/tmp/pycache \
    RUFF_CACHE_DIR=/tmp/ruff \
    MYPY_CACHE_DIR=/tmp/mypy

WORKDIR /app/integrations/home-assistant
CMD ["sh"]
