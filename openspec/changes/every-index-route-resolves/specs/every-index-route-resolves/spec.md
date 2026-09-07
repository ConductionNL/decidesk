# every-index-route-resolves Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [every-index-route-resolves](../../changes/every-index-route-resolves/)

## Purpose

Every list page the app declares can be opened, and the suite finds out when one cannot.

## ADDED Requirements

### Requirement: Every declared index route opens

The e2e suite SHALL navigate to every index page the merged manifest declares whose route takes no parameter, and SHALL assert that page renders a heading carrying its manifest title.

A route that fails to resolve renders the application shell alone, which on a list page looks the same as an empty list. The heading is what separates them.

#### Scenario: A renamed route keeps its test

- **WHEN** a change renames an index page's route
- **THEN** the suite navigates to the new route on the next run, with no test file edited

#### Scenario: A page a fragment stops declaring

- **WHEN** a manifest fragment no longer declares an index page
- **THEN** the suite stops asserting that route, and asserts the remaining ones

### Requirement: The derived route table cannot go empty unnoticed

The suite SHALL assert that the route table it derives is populated, and that no two routes in it share a title.

A suite generated from a list reports green when the list is empty, because zero assertions all pass. Titles are what the navigation assertions match on, so two routes sharing one would let a missing page pass on its twin.

#### Scenario: The manifest merge returns nothing

- **WHEN** the merge yields no index pages
- **THEN** the suite fails, rather than running no tests and reporting green

### Requirement: Two surfaces a reader sees side by side are named apart

No two index pages SHALL carry the same title.

The title is what a person reads in the navigation and at the top of the page. Two entries under one group carrying one word leaves the reader to guess, and leaves the suite unable to tell which page it opened.

#### Scenario: Two consultation surfaces under one group

- **WHEN** a public-engagement surface and a governance-consultation surface both sit under Decisions
- **THEN** they are titled apart, and the navigation says which is which
