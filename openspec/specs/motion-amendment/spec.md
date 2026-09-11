---
status: done
status-note: 2026-06-13 motion-amendment-v1 closed all four audit gaps — visual word-level amendment diff view (src/utils/textDiff.js + AmendmentDiffView/AmendmentDiffTab), chair-controlled amendment voting order with server-side fail-closed enforcement (VotingService::openVotingRound + MotionService::setAmendmentVotingOrder + MotionAmendmentOrderTab), configurable co-signer minimum threshold (motion_min_cosigners), and submission deadline enforcement (SubmissionDeadlineListener).
---

# Motion and Amendment Specification

## Purpose

Motions and amendments are the formal mechanisms for proposing decisions and modifying proposals before a vote. A motion is a formal proposal submitted by a member for consideration by the governing body. An amendment is a proposed modification to a pending motion. The system supports motion submission, amendment drafting, amendment voting order (amendments voted before the main motion), and the parliamentary procedure for handling competing amendments.

**Standards**: Akoma Ntoso (`bill`, `amendment`, `motion`), Schema.org (`Action`, `ReplaceAction`), OpenRaadsinformatie (`Motie`, `Amendement`)
**Feature tier**: V1
**Legal reference**: Gemeentewet 147a (right to submit motions), Reglement van Orde (rules of procedure)

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full Motion and Amendment entity definitions including property tables, Akoma Ntoso alignment, and OpenRaadsinformatie mapping.
## Requirements

---

### Requirement: Motion Submission

The system MUST support submitting motions with a title, body text, proposer,
co-signers, and rationale. Motions MUST follow the governing body's rules for
submission: a configurable minimum number of co-signers (app config
`decidiq`/`motion_min_cosigners`, default 0 = disabled) MUST be enforced before a
motion may leave `proposed` for `deliberating`, and when the linked meeting carries a
`submissionDeadline`, motions and amendments submitted after that deadline MUST be
rejected server-side. Motions MUST be stored as OpenRegister `decision` objects in the
`decidiq` register, with `decisionType: motion` (ADR-005).

**Feature tier**: V1

#### Scenario: Submit a motion with co-signers

- GIVEN a member of a governing body with an active meeting
- WHEN they submit a motion with title "Sustainability Policy", body text with the proposal, 3 co-signers, and a rationale
- THEN the system MUST create a `decision` object with `decisionType: motion`
- AND the motion `lifecycle` MUST be set to `proposed`
- AND the chair MUST be notified of the new motion
- AND the motion MUST appear on the agenda for consideration

#### Scenario: Reject motion below minimum co-signer threshold

- GIVEN a governing body requiring 2 co-signers for motions (`motion_min_cosigners` = 2)
- WHEN the chair attempts to move a motion with only 1 co-signer from `proposed` to `deliberating`
- THEN the system MUST reject the transition with a message naming the minimum co-signer requirement, the current count, and the shortfall
- AND the member MUST be able to add more co-signers and resubmit

#### Scenario: Submit a motion during a live meeting

- GIVEN a meeting in progress
- WHEN a member submits a motion via the meeting interface
- THEN the chair MUST receive a real-time notification
- AND the chair MUST be able to add the motion to the current agenda or defer to next meeting

#### Scenario: Reject submission after the meeting deadline

@e2e exclude backend-only pre-save hook — late creation is rejected at the OpenRegister object API (HTTP 422), covered by the Newman collection and the SubmissionDeadlineListener unit tests; there is no UI surface beyond the generic save error.

- GIVEN a meeting whose `submissionDeadline` lies in the past
- WHEN a member submits a motion (or an amendment to a motion of that meeting) after the deadline
- THEN the system MUST reject the creation server-side with the message "The submission deadline for this meeting has passed; new motions and amendments can no longer be submitted."
- AND no motion or amendment object may be created

---

### Requirement: Amendment Drafting and Submission

The system MUST support creating amendments to pending motions. Amendments MUST carry
the proposed replacement text (`proposedText`) and MUST clearly show what text is
being added, removed, or modified in a visual diff view (additions in green, removals
in red, never colour-only). Multiple amendments to the same motion MUST be supported.

**Feature tier**: V1

#### Scenario: Submit an amendment to a pending motion

- GIVEN a pending motion "Sustainability Policy" with body text
- WHEN a member submits an amendment that modifies paragraph 2
- THEN the system MUST store the amendment with a reference to the original motion
- AND a diff view MUST show the original text and proposed changes (additions in green, removals in red)
- AND the amendment MUST have its own lifecycle (`draft`, `proposed`, `deliberating`, `voting`, then `decided` with an `adopted` or `rejected` outcome)

#### Scenario: View the amendment diff against the parent motion

- GIVEN an amendment with `proposedText` linked to a parent motion
- WHEN a user opens the amendment detail page's diff tab
- THEN the system MUST render a word-level diff between the parent motion text and the proposed text
- AND additions MUST be marked in green and removals in red, with non-colour markers (underline/strike-through) for accessibility
- AND when the amendment has no `proposedText`, the amendment text itself MUST be diffed as a fallback

#### Scenario: Submit multiple amendments to the same motion

- GIVEN a pending motion with one existing amendment
- WHEN another member submits a second amendment to a different paragraph
- THEN both amendments MUST be tracked independently
- AND the system MUST detect if amendments conflict (modify the same text)

---

### Requirement: Amendment Voting Order

The system MUST enforce the parliamentary rule that amendments are voted on before
the main motion: opening a voting round on a motion MUST be rejected while any of its
amendments is still in lifecycle `draft`, `proposed`, `deliberating` or `voting`. When multiple
amendments exist, the most far-reaching amendment MUST be voted on first: the chair
MUST be able to set the voting order (`votingOrder`), the system MUST suggest an
order based on scope (most far-reaching first), and opening a round on an amendment
out of the configured order MUST be rejected server-side.

**Feature tier**: V1

#### Scenario: Vote on amendments before the main motion

- GIVEN a motion with 2 amendments
- WHEN the chair initiates voting on the motion while an amendment is still undecided
- THEN the system MUST reject the round with a message that the amendments must be decided first
- AND after all amendments are resolved, the (possibly amended) main motion MUST be put to vote
- AND the final motion text MUST incorporate all adopted amendments

#### Scenario: Chair sets amendment voting order

- GIVEN a motion with 3 amendments
- WHEN the chair reviews the amendments in the voting-order tab
- THEN the chair MUST be able to reorder the amendments for voting and save the order
- AND the system MUST suggest an order based on scope (most far-reaching first)
- AND saving the order MUST be restricted to the chair (fail closed)

#### Scenario: Reject opening an amendment round out of order

@e2e exclude backend-only enforcement — the ordering guard is a VotingService rule exercised through the API (Newman collection + PHPUnit ordering matrix); the UI surface is the order tab covered by the chair-sets-amendment-voting-order scenario.

- GIVEN a motion with amendments A (votingOrder 1, undecided) and B (votingOrder 2, undecided)
- WHEN a voting round is opened on amendment B
- THEN the system MUST reject the request because amendment A must be voted first
- AND opening a round on amendment A MUST succeed

### Requirement: Motion Withdrawal and Status

The system MUST support motion withdrawal by the proposer before voting. Motions MUST follow the decision lifecycle: `draft`, `proposed`, `deliberating`, `voting`, `decided` (with an `adopted` or `rejected` outcome), `enacted` and `archived`, plus the terminal `withdrawn`.

**Feature tier**: V1

#### Scenario: Withdraw a motion before voting

- GIVEN a motion in lifecycle `proposed` or `deliberating`
- WHEN the proposer requests to withdraw the motion
- THEN the lifecycle MUST change to `withdrawn`
- AND the withdrawal MUST be recorded in the audit trail
- AND the motion MUST remain visible in the meeting record but marked as withdrawn

## User Stories

1. **Member submitting a motion**: As a member, I want to submit a motion or proposal for the ALV agenda with supporting arguments so that my topic is formally discussed and voted on. (Source: intelligence DB #54)

2. **Secretary recording board decisions with votes**: As secretary, I want to record each board decision with the vote distribution per board member so that we comply with WBTR documentation requirements. (Source: intelligence DB #66)

3. **Secretary preparing structured approval request**: As a board secretary, I want to prepare structured approval requests with all required information, so that the supervisory board can make informed decisions efficiently. (Source: intelligence DB #26)

## Acceptance Criteria

- Motions are stored as OpenRegister objects with proposer, co-signers, and rationale
- Amendments show a diff view of proposed text changes
- Amendment voting precedes main motion voting (parliamentary rule)
- Chair can set amendment voting order
- Motion lifecycle follows defined status transitions
- Motion withdrawal is supported and recorded
- OpenRaadsinformatie `Motie`/`Amendement` mapping is available
