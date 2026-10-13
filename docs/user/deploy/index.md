---
title: Choosing a deployment
description: The four ways to run Stewart on a server, and which one fits your setup.
sidebar:
  order: 1
---

During development, `make run` runs Stewart on your machine. For everyday use, it runs on a server next to Home
Assistant: a Raspberry Pi, a NAS, a small PC or a Kubernetes cluster. Every way uses the same Docker image,
`ghcr.io/stewart-php/runtime`, which holds PHP and a start-up script but not your code. Your code, and the Stewart
version in your `composer.lock`, come from your project.

| Way | You update it by | Good for |
|---|---|---|
| [A checkout on the server](checkout.md) | `git pull` and `make deploy` on the server | One machine you can log in to |
| [Following a Git branch](git.md) | Pushing to the branch; the server picks it up within a minute | A server you rarely log in to |
| [An image built by CI](image.md) | Pushing; GitHub builds an image, then you pull it | Several servers, or an exact record of what runs |
| [Kubernetes](kubernetes.md) | The Helm chart, with any of the three above | A cluster you already run |

All of them run **one** Stewart. Two copies against the same Home Assistant would both run every automation.

## What every server needs

- **Docker with Compose**, or Kubernetes. Images exist for amd64 and arm64, so a Raspberry Pi 4 or 5 works.
- **Your `.env`**, with the Home Assistant URL and token and the control token. It never goes into Git.
- **Valkey** for apps that store data. The compose files and the Helm chart run it for you.

[Running in production](operations.md) covers health checks, logs, backups and updates.
