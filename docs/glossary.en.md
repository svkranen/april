# APRIL Glossary

This glossary defines the canonical Community-Core terminology for APRIL. Connector-specific terms may differ, but the core UI and documentation should use these terms consistently.

## Item

The process object whose lifecycle APRIL reconstructs and analyzes from events.

Connectors may call an item a document, incident, ticket, request, order, case, or another domain-specific term.

## Event

A recorded occurrence that describes something that happened to an item at a specific time.

## Journey

The reconstructed timeline of an item across events, process instances, context snapshots, and findings.

## Finding

A human-readable observation produced by APRIL, such as a deviation, missing context, warning, or rule violation.

## Context Snapshot

The captured business context of an item at the time of an event. Snapshots are historical evidence and must not be silently changed later.

## Process Instance

One reconstructed execution of a process template for an item and version.

## Process Template

The expected process model used to check events, decisions, context, routing, and allowed outcomes.

## Connector

An adapter for a source or target system. Connectors translate system-specific identifiers and terms into APRIL core concepts.
