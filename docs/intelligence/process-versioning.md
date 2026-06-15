# Process Versioning and KPI Eligibility

April speichert Rohereignisse dauerhaft und unverändert. Ob eine Prozessinstanz KPI-relevant ist, wird erst bei der Auswertung anhand von Prozessversion, Baseline, Template-Startschritt und Versionsgrenzen berechnet.

## Warum Prozessversionen existieren

In Amagno werden Workflows haeufig im spaeteren Livesystem entwickelt. Dadurch entstehen vor der fachlichen Freigabe bereits Events, Testlaeufe und Dokumente, die mitten im Prozess sichtbar werden. Eine `ProcessVersion` setzt fuer einen `process_key` eine fachliche Baseline ab `valid_from`. Standard-KPIs und Standard-Heatmaps zaehlen nur Timelines, die sauber innerhalb einer definierten Version starten und nicht ueber die naechste Versionsgrenze laufen.

## Rohdaten bleiben unveraendert

`intelligence_process_event` bleibt ein Rohdaten-Log. Events werden nicht mit KPI-Status, Prozessversionsstatus oder Ausschlussgruenden angereichert. Die Eventverarbeitung bleibt dadurch append-only und diagnostisch vollstaendig. Vorgelagerte Entwicklungs- und Discovery-Events bleiben auswertbar, sind ohne passende Prozessversion aber nicht KPI-relevant.

## Berechnung der KPI-Relevanz

Die Eligibility wird zentral timeline-basiert berechnet. Der Resolver betrachtet:

- den `process_key`
- die sortierte Dokument-Timeline
- die verfuegbaren `ProcessVersion`-Eintraege
- den Template-Startschritt

Wenn ein Template keinen `initial_step` definiert, wird der erste Template-Step als Startschritt verwendet.

Eine Timeline ist KPI-relevant, wenn:

- fuer den `process_key` mindestens eine Prozessversion existiert
- das erste beobachtete Event in den Gueltigkeitszeitraum einer Version faellt
- das erste beobachtete Event dem Template-Startschritt entspricht
- das letzte Event vor der naechsten Versionsgrenze liegt

`valid_until` wird nicht gespeichert. Das Ende einer Version ergibt sich aus `valid_from` der naechsten Version desselben `process_key`.

## Ausschlussgruende

- `no_process_version_defined`: Fuer den Prozess gibt es keine Prozessversion.
- `before_first_baseline`: Die Timeline beginnt vor der ersten definierten Baseline.
- `started_mid_process`: Die Timeline beginnt nach Baseline, aber nicht mit dem Template-Startschritt.
- `crossed_version_boundary`: Die Timeline startet in einer Version und endet nach Beginn der naechsten Version.

## Dokumente mitten im Prozess

Wenn das erste beobachtete Event nicht dem Template-Startschritt entspricht, wird die Timeline als `started_mid_process` ausgeschlossen. Die Events bleiben im Rohdaten-Log und koennen im Diagnosemodus sichtbar gemacht werden.

## Versionsgrenzen

Wenn eine Timeline vor einer neuen Version startet und danach weitere Events bekommt, wird sie nicht fuer die neue Version gezaehlt. Startet sie in einer Version und laeuft ueber die naechste Baseline hinaus, wird sie mit `crossed_version_boundary` ausgeschlossen.

## Standardauswertung und Diagnosemodus

Standard-Heatmaps und Standard-KPIs verwenden nur KPI-relevante Timelines. Mit `--include-excluded` werden ausgeschlossene Timelines in die Diagnoseausgabe aufgenommen und die Summary zeigt die Ausschlussgruende, zum Beispiel:

```yaml
kpi_eligibility:
  included_instances: 42
  excluded_instances: 7
  exclusion_reasons:
    started_mid_process: 4
    crossed_version_boundary: 2
    no_process_version_defined: 1
```

Der Diagramm-Export kann auf eine konkrete Prozessversion begrenzt werden. `latest` meint die zuletzt definierte Baseline fuer den `process_key`:

```bash
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml --view=combined --process-version=latest
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml --view=combined --process-version=latest --include-ok
```

Damit werden aeltere, aber fuer fruehere Baselines gueltige Timelines nicht in die aktuelle Version gemischt.

## CLI-Beispiele

```bash
bin/console intelligence:process-version:create ai-rechnungen 1.0 "2026-06-01 08:00" --description="Produktivstart nach Entwicklungsphase"
bin/console intelligence:process-version:list ai-rechnungen
bin/console intelligence:template:heatmap ai-rechnungen --include-excluded
bin/console intelligence:template:export-diagram templates/ai-rechnungen.yaml --view=flow --debug-metrics --include-excluded
```
