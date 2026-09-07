# confidentiality-in-plain-words

**Status**: planned
**Scope**: decidiq

## Why

Every organisation can restrict what it circulates, and has to say on what ground. Only the words were Dutch.

`GeheimhoudingGrond` already called itself configuration: "Grounds are DATA, not code: administrators add the ones their own law gives them." Its `category` enum contradicted that, fixing four values from one country's statutes.

## What changes

`Geheimhouding` becomes `ConfidentialityRestriction` and `GeheimhoudingGrond` becomes `ConfidentialityGround`. Every property keeps its name.

`category` stops being an enum. A ground is configuration by its own description, so the way grounds are grouped is configuration too. Existing values stay valid, because the field no longer constrains them.

## Decision: grounds are copied before restrictions

A restriction names the ground it was imposed on. Copied in the other order, or copied with the reference untouched, every restriction would live on the new schema while pointing at the retired ground: readable, plausible, and joined to the wrong side of the migration.

So grounds go first, and the reference follows the ground to its copy.

## Impact

Two Dutch schema names go. The routes follow: `/geheimhoudingen` becomes `/confidentiality-restrictions`, `/geheimhoudingsgronden` becomes `/confidentiality-grounds`.

An organisation whose law groups its grounds differently can now say so, rather than picking the nearest of four Dutch categories.
