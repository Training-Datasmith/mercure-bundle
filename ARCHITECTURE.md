# Architecture: mercure-bundle

## Purpose

A Symfony bundle integrating the `symfony/mercure` library. Registers Mercure hubs as Symfony services, provides Twig helpers (stimulus data attributes for Mercure), and adds a data collector for the Symfony profiler.

## Directory Structure

```
src/
  Mercure_Bundle.php                           — Bundle class: registers compiler passes
  DependencyInjection/
    Configuration.php                          — Config tree: hubs, jwt, public_url, etc.
    Mercure_Extension.php                      — Loads service definitions, processes configuration
    CompilerPass/
      Stimulus_Helper_Pass.php                 — Registers Stimulus Mercure helper if UX Stimulus is present
  DataCollector/
    Mercure_Data_Collector.php                 — Collects Mercure publish events for the Symfony web profiler panel
```

## Key Design Decisions

- **Convention-over-configuration**: A single `hubs` config key registers multiple Mercure hubs; the first is the default
- **Symfony service container integration**: Each hub becomes a named service; the JWT factory is also registered as a service for easy testing and injection
- **Optional Stimulus integration**: The `StimulusHelperPass` is a compiler pass — it only adds the Mercure Stimulus helper if `symfony/ux-turbo` or `symfony/stimulus-bundle` is installed

## Extension Points

- Configure multiple hubs under `mercure.hubs` for multi-hub deployments
- Inject `HubInterface` by type hint or by hub name (`$defaultHub`) in your services

## Dependency Flow

```
config/packages/mercure.yaml
  → MercureExtension::load()
  → Symfony DI container
  → HubInterface services (one per configured hub)
  → MercureDataCollector (profiler panel)
```
