# Stewart Helm chart

Runs a [Stewart](https://github.com/stewart-php/stewart-php) project (Home Assistant automations in PHP) on
Kubernetes, with an optional Valkey for app state.

```bash
kubectl create secret generic stewart-ha --from-literal=token=<long-lived access token>
helm install home oci://ghcr.io/stewart-php/charts/stewart \
  --set code.image.repository=ghcr.io/<owner>/<repository> \
  --set homeAssistant.url=ws://homeassistant.local:8123/api/websocket \
  --set homeAssistant.existingSecret=stewart-ha
```

## What it runs

- **One pod, always.** Two daemons would both run every automation, so the Deployment has one replica and the
  `Recreate` strategy: an upgrade stops the old pod before starting the new one. There is no Service; nothing
  connects to Stewart.
- **Probes** run `stewart status` inside the pod. Liveness (and startup) checks that the daemon answers. Readiness
  also needs a live Home Assistant connection and no quarantined worker, so `kubectl get pods` shows when automations
  are not running.
- **Secrets** are mounted as files and read through `STEWART_*_FILE` variables; none is passed as a plain environment
  value. The control token, used only by the probes inside the pod, is generated once per release.
- **Locked down**: non-root (uid 10001), read-only root filesystem, no capabilities, no service account token,
  `enableServiceLinks: false`.

## Where the code comes from

| `code.mode` | The pod runs | Needs |
|---|---|---|
| `image` (default) | Your project's image, built by the skeleton's `image.yml` | `code.image.repository`, `code.image.tag` |
| `git` | `ghcr.io/stewart-php/runtime`; an init container clones `code.git.url` at `code.git.ref` and installs dependencies on every start | `code.git.url`; `code.git.tokenSecret` or `code.git.sshKeySecret` for a private repository |
| `volume` | `ghcr.io/stewart-php/runtime` over a checkout in an existing PVC; dependencies are installed on start | `code.volume.claimName` |

`code.generateOnStart: true` regenerates the entity and service classes from Home Assistant before the daemon starts.
A failure keeps the committed classes. In `image` mode this turns off the read-only root filesystem, because the
classes are rewritten inside the image.

## Values

| Value | Default | Meaning |
|---|---|---|
| `code.mode` | `image` | `image`, `git` or `volume`; see above |
| `code.image.repository`, `.tag`, `.pullPolicy` | `""`, `main`, `IfNotPresent` | Your project's image |
| `code.git.url`, `.ref` | `""`, `main` | Repository and branch, tag or commit to clone |
| `code.git.tokenSecret.name`, `.key` | `""`, `token` | Secret with an HTTPS token that can read the repository |
| `code.git.sshKeySecret.name`, `.key` | `""`, `ssh-privatekey` | Secret with an SSH deploy key, for `git@` URLs |
| `code.volume.claimName` | `""` | PVC holding a checkout |
| `code.generateOnStart` | `false` | Regenerate the classes from Home Assistant on every start |
| `runtimeImage.repository`, `.tag` | `ghcr.io/stewart-php/runtime`, appVersion's `X.Y` | Image for the `git` and `volume` modes |
| `homeAssistant.url` | required | `ws://…/api/websocket`; `http(s)://` is accepted too |
| `homeAssistant.existingSecret`, `.existingSecretKey` | `""`, `token` | Secret with the long-lived access token |
| `homeAssistant.token` | `""` | The token inline, stored in the chart's Secret, if no Secret is named |
| `persistence.url` | `""` | An external `redis://` URL; empty with `valkey.enabled` uses the chart's Valkey |
| `persistence.existingSecret`, `.existingSecretKey` | `""`, `url` | Secret holding the URL, when it carries a password |
| `control.existingSecret`, `.existingSecretKey` | `""`, `token` | Secret with the control token; name one when rendering with `helm template`, or the generated token changes on every render |
| `config` | `{}` | `stewart.yaml` content; when set it replaces the project's file |
| `logLevel`, `logFormat` | `info`, `json` | |
| `workers` | `0` | Worker processes; `0` is min(4, CPUs), and a CPU limit counts |
| `env` | `[]` | Extra environment variables, such as `STEWART_APPS__HELLO__ENABLED` |
| `resources` | 50m / 128Mi request, 512Mi limit | |
| `podSecurityContext`, `securityContext` | non-root, read-only root, no capabilities | |
| `terminationGracePeriodSeconds` | `30` | Must exceed `shutdown_grace` (5s) plus a second |
| `probes.{startup,liveness,readiness}` | see `values.yaml` | Periods, timeouts and thresholds |
| `valkey.enabled` | `true` | Run a single Valkey next to Stewart, reachable only inside the cluster |
| `valkey.storage.enabled`, `.size`, `.storageClass` | `true`, `1Gi`, `""` | Keep Valkey's append-only file on a PVC |
| `imagePullSecrets`, `nodeSelector`, `tolerations`, `affinity`, `podAnnotations`, `podLabels` | | As usual |

## Operating it

```bash
kubectl logs -f deployment/home-stewart
kubectl exec deployment/home-stewart -- stewart status
kubectl rollout restart deployment/home-stewart   # git mode: deploy the latest commit of code.git.ref
```
