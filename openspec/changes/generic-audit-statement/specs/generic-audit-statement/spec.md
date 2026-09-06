# generic-audit-statement Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [generic-audit-statement](../../changes/generic-audit-statement/)

## Purpose

One record for any audit committee's statement on a body's accounts, with the Dutch vocabulary supplied per organisation mode rather than by the schema name.

## ADDED Requirements

### Requirement: An audit statement fits any audit committee

The system SHALL declare `AuditStatement` (slug `audit-statement`) carrying a financial year, a verdict, the body whose accounts were examined, and optional notes and agenda item.

A body SHALL file at most one statement per financial year.

#### Scenario: A provincial audit committee files a statement

- **WHEN** a body that is not an association records a verdict for a financial year
- **THEN** the statement is valid, with no VvE-specific field involved

### Requirement: The Dutch term is a mode label, not a schema name

The `assoc` organisatie_modus SHALL render the surface as *Kascommissie verklaringen*.

The agenda rule SHALL keep `kascommissie` and `kascontrole` as synonyms so Dutch agendas still match, while its label reads generically.

#### Scenario: An association still reads its own word

- **WHEN** the tenant's organisatie_modus is `assoc`
- **THEN** the surface is labelled Kascommissie verklaringen

#### Scenario: A Dutch agenda still matches

- **WHEN** an agenda item is titled "kascontrole"
- **THEN** the statutory audit-committee item is recognised as present

### Requirement: Existing statements are carried across

A repair step SHALL copy every `kascommissie-verklaring` row onto the generic schema, keyed on (governance body, financial year), resolving a body slug to its UUID before comparing.

It SHALL be idempotent and SHALL NOT edit or delete any source row.

#### Scenario: Rows survive the rename

- **WHEN** the repair step runs
- **THEN** each statement exists on the generic schema with the same year, verdict and body

#### Scenario: Running twice changes nothing

- **WHEN** it runs a second time
- **THEN** no additional statement is created
