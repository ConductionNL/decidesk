# configuration-surface Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [configuration-surface](../../changes/configuration-surface/)

## Purpose

The configurable types this app runs on are editable by the people who run it.

## ADDED Requirements

### Requirement: Every configurable type has a surface

The system SHALL offer an index and a detail page for `meeting-type`, `agenda-item-type`, `position-type` and `governance-body-composition`.

Each SHALL allow creating a new row, so a kind can arrive without an example set.

#### Scenario: An operator adds a kind of agenda item

- **WHEN** an operator opens the agenda item types page and adds one
- **THEN** it is available to agenda items without reinstalling or reseeding

#### Scenario: An instance with no example set still shows the page

- **WHEN** no example set has been loaded
- **THEN** each page renders its empty state rather than failing

### Requirement: A type's detail page shows what uses it

The detail pages SHALL list the records carrying the type, because that is what an operator needs to know before changing one.

#### Scenario: Editing a type in use

- **WHEN** an operator opens an agenda-item type that items already carry
- **THEN** those items are listed on the page

### Requirement: The configuration surfaces sit in the settings foldout

These entries SHALL be lifted into the settings section, leaving the primary menu at or under the ADR-004 ceiling.

#### Scenario: The primary menu does not grow

- **WHEN** the four entries are added
- **THEN** the primary menu still holds six entries

### Requirement: Every menu-layout rule names an entry that exists

`src/menu-layout.json` SHALL NOT relocate, remove, or lift into settings any menu id the manifests do not declare, and SHALL NOT relocate under a parent that does not exist.

The nav-ceiling check asks only whether every declared entry is placed, so drift in the other direction is invisible to it.

#### Scenario: A rule for a removed entry is refused

- **WHEN** a menu entry is removed and its layout rule is left behind
- **THEN** the test suite fails, naming the rule

#### Scenario: The guard is not vacuous

- **WHEN** the guard runs
- **THEN** it asserts the app declares menu entries at all, so an empty read cannot pass
