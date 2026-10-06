{{- define "stewart.name" -}}
{{- .Chart.Name | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "stewart.fullname" -}}
{{- if contains .Chart.Name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name .Chart.Name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}

{{- define "stewart.labels" -}}
helm.sh/chart: {{ printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{ include "stewart.selectorLabels" . }}
{{- end }}

{{- define "stewart.selectorLabels" -}}
app.kubernetes.io/name: {{ include "stewart.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{- define "stewart.valkeyName" -}}
{{- printf "%s-valkey" (include "stewart.fullname" .) | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "stewart.runtimeImage" -}}
{{- $version := semver .Chart.AppVersion -}}
{{- $tag := .Values.runtimeImage.tag | default (printf "%d.%d" $version.Major $version.Minor) -}}
{{- printf "%s:%s" .Values.runtimeImage.repository $tag }}
{{- end }}

{{- define "stewart.codeImage" -}}
{{- if eq .Values.code.mode "image" -}}
{{- printf "%s:%s" (required "code.image.repository is required when code.mode is image" .Values.code.image.repository) .Values.code.image.tag }}
{{- else -}}
{{- include "stewart.runtimeImage" . }}
{{- end }}
{{- end }}

{{- define "stewart.codePullPolicy" -}}
{{- if eq .Values.code.mode "image" }}{{ .Values.code.image.pullPolicy }}{{ else }}{{ .Values.runtimeImage.pullPolicy }}{{ end }}
{{- end }}

{{/* The Secret and key holding each credential, or nothing when the credential is absent. */}}
{{- define "stewart.homeAssistantTokenSource" -}}
{{- if .Values.homeAssistant.existingSecret -}}
{{ .Values.homeAssistant.existingSecret }}/{{ .Values.homeAssistant.existingSecretKey }}
{{- else if .Values.homeAssistant.token -}}
{{ include "stewart.fullname" . }}/home-assistant-token
{{- end }}
{{- end }}

{{- define "stewart.persistenceUrlSource" -}}
{{- if .Values.persistence.existingSecret -}}
{{ .Values.persistence.existingSecret }}/{{ .Values.persistence.existingSecretKey }}
{{- else if .Values.persistence.url -}}
{{ include "stewart.fullname" . }}/persistence-url
{{- end }}
{{- end }}

{{- define "stewart.controlTokenSource" -}}
{{- if .Values.control.existingSecret -}}
{{ .Values.control.existingSecret }}/{{ .Values.control.existingSecretKey }}
{{- else -}}
{{ include "stewart.fullname" . }}/control-token
{{- end }}
{{- end }}

{{- define "stewart.probe" -}}
{{- if eq .root.Values.probes.mode "http" -}}
httpGet:
  path: {{ ternary "/readyz" "/healthz" (eq .kind "readiness") }}
  port: probe
{{- else -}}
exec:
  command:
    - stewart
    - status
    - --probe={{ .kind }}
    - --timeout=5s
    {{- if .root.Values.config }}
    - --config=/etc/stewart/stewart.yaml
    {{- end }}
{{- end }}
periodSeconds: {{ .settings.periodSeconds }}
timeoutSeconds: {{ .settings.timeoutSeconds }}
failureThreshold: {{ .settings.failureThreshold }}
{{- end }}

{{/* Projected volume sources for a dict of file name to "secret/key"; an empty source is left out. */}}
{{- define "stewart.secretSources" -}}
{{- $sources := list -}}
{{- range $path, $source := . -}}
{{- if $source -}}
{{- $parts := splitn "/" 2 $source -}}
{{- $sources = append $sources (dict "secret" (dict "name" $parts._0 "items" (list (dict "key" $parts._1 "path" $path)))) -}}
{{- end -}}
{{- end -}}
{{- toYaml $sources -}}
{{- end }}

{{- define "stewart.env" -}}
{{- $root := .root -}}
- name: STEWART_HOME_ASSISTANT__URL
  value: {{ required "homeAssistant.url is required" $root.Values.homeAssistant.url | quote }}
{{- if .homeAssistantToken }}
- name: STEWART_HOME_ASSISTANT__TOKEN_FILE
  value: /run/secrets/stewart/home-assistant-token
{{- end }}
{{- if .persistenceUrl }}
- name: STEWART_PERSISTENCE__URL_FILE
  value: /run/secrets/stewart/persistence-url
{{- else if $root.Values.valkey.enabled }}
- name: STEWART_PERSISTENCE__URL
  value: {{ printf "redis://%s:6379/0" (include "stewart.valkeyName" $root) | quote }}
{{- end }}
- name: STEWART_CONTROL__TOKEN_FILE
  value: /run/secrets/stewart/control-token
- name: STEWART_LOG_LEVEL
  value: {{ $root.Values.logLevel | quote }}
- name: STEWART_LOG_FORMAT
  value: {{ $root.Values.logFormat | quote }}
{{- if $root.Values.workers }}
- name: STEWART_WORKERS
  value: {{ $root.Values.workers | quote }}
{{- end }}
{{- end }}
