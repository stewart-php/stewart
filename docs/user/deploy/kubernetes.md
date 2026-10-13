---
title: Kubernetes
description: Run Stewart on Kubernetes with the Helm chart, from an image, a Git branch or a volume.
sidebar:
  order: 5
---

The Helm chart runs Stewart in one pod, with an optional Valkey next to it. It is locked down by default: it runs as a
non-root user with a read-only root filesystem, and reads every secret from a mounted file.

## Install it

Put the Home Assistant token in a secret, then install the chart:

```bash
kubectl create secret generic stewart-ha --from-literal=token=<long-lived access token>

helm install home oci://ghcr.io/stewart-php/charts/stewart \
  --set code.image.repository=ghcr.io/<owner>/<repository> \
  --set homeAssistant.url=ws://homeassistant.local:8123/api/websocket \
  --set homeAssistant.existingSecret=stewart-ha
```

## Where the code comes from

| `code.mode` | The pod runs | You set |
|---|---|---|
| `image` (default) | [Your project's image](image.md), built by CI | `code.image.repository`, `code.image.tag` |
| `git` | The runtime image, which follows a branch [like the Git mode](git.md) | `code.git.url`, and `code.git.tokenSecret` or `code.git.sshKeySecret` for a private repository |
| `volume` | The runtime image over a checkout in an existing volume claim | `code.volume.claimName` |

## How it runs

- **One replica, always.** An upgrade stops the old pod before it starts the new one.
- **Probes** call Stewart's health port: liveness checks that Stewart answers, readiness also needs a live Home
  Assistant connection. `kubectl get pods` shows when automations are not running.
- **Valkey** runs next to Stewart and keeps its data on a 1 Gi volume. To use your own server, set `persistence.url`
  and `valkey.enabled: false`.
- **`stewart.yaml`** comes from your project. Set `config` in the values to replace it.

The chart's [README](https://github.com/stewart-php/stewart/blob/main/charts/stewart/README.md) lists every value.

## Operating it

The release `home` creates the deployment `home-stewart`:

```bash
kubectl logs -f deployment/home-stewart
kubectl exec deployment/home-stewart -- stewart status
kubectl exec deployment/home-stewart -- stewart app:pause porch-light
kubectl exec deployment/home-stewart -- stewart worker:restart 0
```

With `adminApi.enabled: true`, the [admin API](../reference/admin-api.md) and Prometheus metrics are available too.
`adminApi.podMonitor.enabled: true` creates a `PodMonitor` for the Prometheus Operator.

## Common problems

**The pod is not ready.** `kubectl describe pod` shows the probe's answer, such as `Home Assistant is connecting`.
Check `homeAssistant.url` and the token.

**Upgrading the chart takes Stewart down for a moment.** That is the single replica being replaced. It is on
purpose: two pods would run every automation twice.
