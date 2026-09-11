---
status: done
status-note: 2026-06-12 voting-rules-v1 — closed the five audit gaps. qualified-majority thresholds (simple/2-3/3-4/unanimous via additive voteThreshold), abstention handling (exclude|count), configurable tie-breaking (rejected|chair-decides with chair-only casting vote|revote-once via revoteOfRound), per-member proxy limits (decidiq/max_proxies_per_holder app config, default 2, fail closed) and castAs attendance-mode stamping (replacing remote-session verification theater with honest recording, per the modified Remote Voting requirement). All 5 requirements built; covered by PHPUnit (tally matrix, proxy cap, castAs), vitest (votingRules.js), Playwright (rule selectors + active-rules display) and Newman (decidiq-voting-rules collection — authored against this branch; runs green once the branch is the deployed instance). In progress 2026-06-14 via decision-methods (VotingRound retargeted motion→DecisionStage; a VotingRound now resolves a method=vote DecisionStage and the stage outcome derives from VotingRound.result; vote sub-variants stay on VotingRound.isSecret/votingMethod).
openspec-changes:
  - decision-methods
---

# Voting System Specification

## Purpose
The voting system is Decidiq's most critical feature. It supports multiple voting methods (open vote, secret ballot, roll call, weighted voting), real-time ballot casting and result calculation, quorum-aware majority thresholds, proxy vote handling, and configurable voting rules per governing body. The system ensures legally compliant voting for associations (ALV), corporate boards (BV/NV), and government councils.

**Standards**: Schema.org (`VoteAction`, `ChooseAction`), Akoma Ntoso (`voting`, `count`), OpenRaadsinformatie (`Stemming`, `Stem`)
**Feature tier**: MVP
**Legal reference**: BW 2:38 (ALV voting), BW 2:230 (BV shareholder voting), Gemeentewet 27-32 (council voting), WBTR (documentation requirements)

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full Vote and VotingRound entity definitions including property tables, Schema.org mappings, and OpenRaadsinformatie alignment.
## Requirements

---

### Requirement: Open Vote (For/Against/Abstain)

The system MUST support open (public) voting where each participant casts a for, against, or abstain vote. Results MUST be displayed in real-time. The vote of each participant MUST be recorded and visible in the minutes.

**Feature tier**: MVP

#### Scenario: Conduct an open vote on an agenda item

@e2e exclude tests/e2e/workflows/voting-quorum-workflow.spec.ts opens, casts and closes rounds over the real API and asserts the exact tally, and VotingServiceTest::testTallyResultsAdopted covers the result; that spec is tagged to meeting-management and decision-management. No test clicks the For, Against and Abstain panel or watches the running tally: genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

- GIVEN a meeting with quorum met and an active agenda item of type "decision"
- WHEN the chair initiates an open vote
- THEN each eligible member MUST see a voting panel with "For", "Against", and "Abstain" buttons
- AND the system MUST display the running tally in real-time
- AND once all members have voted (or the chair closes voting), the result MUST be calculated
- AND the result (adopted/rejected) MUST be announced based on the configured majority rule

#### Scenario: View individual votes after an open vote

@e2e exclude no test opens a closed open-vote round and checks how each member voted, or reads the result back from the decision audit trail: genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

- GIVEN an open vote has been completed
- WHEN a user views the voting results
- THEN the system MUST display how each member voted (for/against/abstain)
- AND the results MUST be recorded in the decision audit trail

#### Scenario: Reject a vote when quorum is lost mid-meeting

@e2e exclude the quorum gate on opening a round is covered by VotingServiceTest::testOpenVotingRoundBlocksOnQuorumFailure and by voting-quorum-workflow.spec.ts, which is tagged to meeting-management. No test lets members leave after quorum was met and then retries: genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

- GIVEN a meeting where quorum was initially met but members have since left
- WHEN the chair attempts to start a new vote
- THEN the system MUST recalculate quorum from current attendance
- AND if quorum is no longer met, voting MUST be blocked with a quorum warning

---

### Requirement: Secret Ballot

The system MUST support secret (anonymous) voting where individual votes are not linked to voters in the results. Secret ballots MUST be used for board elections and other votes where the chair or statutes require anonymity.

**Feature tier**: MVP
**Legal reference**: BW 2:38 (election by secret ballot), Gemeentewet 31 (secret ballot requirements)

#### Scenario: Conduct a secret ballot for board election

@e2e exclude no test casts a secret ballot. The masking rules live in the `secret-ballot` spec, and RegisterJsonTest::testVotingRoundSchema pins only the `isSecret` field. Candidate options and the eligible-voter count check are untested: genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

- GIVEN a meeting with an agenda item "Board Election — Treasurer"
- WHEN the chair initiates a secret ballot
- THEN each eligible member MUST see a voting panel with candidate options
- AND individual votes MUST NOT be linked to voters in the stored results
- AND only aggregate totals (votes per candidate) MUST be recorded
- AND the system MUST verify that the total vote count matches the number of eligible voters

#### Scenario: Verify vote count integrity for secret ballot

@e2e exclude no test tallies a secret ballot against its eligible-voter count or checks that a discrepancy is flagged for the chair: genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.

- GIVEN a secret ballot has been completed with 12 eligible voters
- WHEN the results are tallied
- THEN the total number of votes MUST equal exactly 12
- AND if a discrepancy is detected, the system MUST flag it for the chair

---

### Requirement: Qualified Majority and Voting Rules

The system MUST support configurable majority rules per voting round: simple
majority (50%+1), qualified majority two-thirds, qualified majority
three-quarters, and unanimous (mirroring the Resolution schema's
`voteThreshold` enum). Abstentions MUST be configurable as counting toward the
calculation base (`abstentionHandling: count`) or excluded from it
(`abstentionHandling: exclude`, default). Tie-breaking MUST be configurable per
round (`tieBreakRule`): `rejected` (default — a tied motion fails),
`chair-decides` (round result becomes `tied` and the chair resolves it with an
explicit casting vote), or `revote` (round result becomes `tied` and the round
may be reopened exactly once via a new round linked through `revoteOfRound`).
The system MUST record the applied threshold, abstention handling, tie-break
rule and computed base alongside the result.

**Tally formula** (F = weighted for, A = weighted against, B = weighted
abstentions, T = F+A+B):

- base = F + A (`exclude`) or F + A + B (`count`)
- T == 0 → `invalid`; tie (simple-majority only, F == A and F > 0) → tie-break
  rule applies; base == 0 → `rejected`
- simple-majority: adopted iff 2F > base
- qualified-majority-two-thirds: adopted iff 3F ≥ 2·base
- qualified-majority-three-quarters: adopted iff 4F ≥ 3·base
- unanimous: adopted iff F == base

**Feature tier**: MVP
**Legal reference**: BW 2:42 (statute amendment requires 2/3), BW 2:18 (dissolution requires 2/3)

#### Scenario: Verify qualified majority for statute amendment

@e2e exclude tally math is server-side over multi-user ballot state; exhaustively covered by the PHPUnit tally matrix (VotingServiceTallyMatrixTest) and the Newman two-thirds contract test

- GIVEN a vote on statute amendment requiring 2/3 majority of votes cast
- WHEN 20 members vote: 14 for, 5 against, 1 abstain
- THEN the system MUST calculate: 14/(14+5) = 73.7% (abstentions excluded from calculation)
- AND the result MUST be "adopted" (73.7% >= 66.7%)
- AND the system MUST record the required threshold alongside the result

#### Scenario: Verify quorum requirement for statute amendment vote

@e2e exclude quorum gate is a server-side precondition requiring seeded multi-member attendance state; covered at the PHP layer (checkQuorum + openVotingRound tests)

- GIVEN a statute amendment vote requiring 2/3 of members present
- WHEN only 8 of 15 members are present (53%)
- THEN the system MUST block the vote with a message: "Quorum not met. Statute amendment requires 2/3 of members present (10 required, 8 present)."

#### Scenario: Configure voting rules when opening a round

- GIVEN a chair opening a voting round on a motion under debate
- WHEN the open-round dialog is shown
- THEN the chair MUST be able to select the vote threshold (simple majority, two-thirds, three-quarters, unanimous), the abstention handling (exclude or count), and the tie-break rule (motion fails, chair decides, revote)
- AND the defaults MUST be simple majority, abstentions excluded, and motion fails on a tie

#### Scenario: Abstentions counted toward the base

@e2e exclude tally math is server-side; covered by the PHPUnit tally matrix (count-mode rows)

- GIVEN a voting round with `abstentionHandling: count` and a two-thirds threshold
- WHEN 20 members vote: 13 for, 4 against, 3 abstain
- THEN the base MUST be 20 (abstentions counted) and the result "rejected" (3·13=39 < 2·20=40)
- AND with `abstentionHandling: exclude` the same votes MUST yield base 17 and "adopted" (3·13=39 ≥ 2·17=34)

#### Scenario: Handle a tie vote

@e2e exclude tie resolution is server-side over multi-user ballot state; covered by the PHPUnit tally matrix (all three tie-break rules) and the Newman chair-casting guard test

- GIVEN a simple majority vote where 10 for and 10 against
- WHEN the votes are tallied
- THEN the system MUST apply the configured tie-break rule: with `rejected` (default) the result MUST be "rejected" (the motion fails), with `chair-decides` or `revote` the result MUST be "tied"

#### Scenario: Chair casting vote resolves a tie

@e2e exclude requires a live tied round with multi-user ballots; chair-only authorization and resolution are covered by PHPUnit (tally matrix chair-casting rows) and the Newman casting-vote guard test

- GIVEN a round closed as "tied" with `tieBreakRule: chair-decides`
- WHEN the chair re-runs close with an explicit casting vote (`chairCasting: for` or `against`)
- THEN the casting vote MUST be recorded on the round as `chairCastingVote` and the result resolved to "adopted" or "rejected"
- AND a casting vote from anyone who does not hold the chair role for the meeting MUST be refused (fail closed)
- AND a casting vote on a round whose tie-break rule is not `chair-decides` MUST be refused

#### Scenario: Revote permitted once after a tie

@e2e exclude requires a live tied round with multi-user ballots; the revote-once guard is covered by PHPUnit (openVotingRound revote tests) and the Newman revote contract test

- GIVEN a round closed as "tied" with `tieBreakRule: revote`
- WHEN a new round is opened with `revoteOfRound` linking to the tied round
- THEN the new round MUST be created and linked, without re-transitioning the motion lifecycle
- AND a second revote of the same tied round MUST be refused
- AND a revote of a round that is not tied, or whose tie-break rule is not `revote`, MUST be refused

#### Scenario: Display active rules and computed base

- GIVEN a voting round with configured rules
- WHEN a user views the live tally or the closed-round results
- THEN the panel MUST show the active threshold, abstention handling and tie-break rule, and the computed base the threshold is evaluated against

---

### Requirement: Proxy Voting

The system MUST support digital proxy voting (volmacht) where a member
authorizes another member to vote on their behalf. Proxy votes MUST be
verifiable and count toward both quorum and voting. The system MUST enforce a
maximum number of ACTIVE proxies a single member may hold per meeting,
configurable via app config `decidiq`/`max_proxies_per_holder` (NL governance
default: 2). The cap MUST fail closed: when existing proxies cannot be counted,
registration MUST be rejected.

**Feature tier**: MVP
**Legal reference**: BW 2:227 (shareholder proxy), BW 2:38 (ALV proxy per statutes)

#### Scenario: Grant and exercise a digital proxy

@e2e exclude requires two authenticated members with live meeting/round state; covered at the PHP layer (castVote proxy tests) and the lifecycle Newman suite

- GIVEN member A cannot attend the ALV and grants a proxy to member B
- WHEN member B votes on a decision item
- THEN the system MUST prompt member B to cast their own vote AND the proxy vote separately
- AND both votes MUST be recorded (member B's own vote and member A's proxy vote)
- AND the results MUST show the total including proxy votes

#### Scenario: Limit proxy votes per member

@e2e exclude proxy-cap enforcement is a server-side rule over seeded proxy rows; covered by PHPUnit (ProxyVoteServiceTest cap cases) and the Newman proxy-cap contract test

- GIVEN the configured maximum is 2 proxies per holder per meeting
- WHEN member B already holds 2 ACTIVE proxies in the meeting and member C attempts to register a proxy to member B
- THEN the system MUST reject the registration with a message indicating the maximum has been reached
- AND when the existing proxies cannot be counted, the registration MUST also be rejected (fail closed)

---

### Requirement: Remote Voting in Digital/Hybrid Meetings

The system MUST support real-time voting for remote participants in digital and
hybrid meetings. Remote votes MUST have equal weight to in-person votes. The
system MUST honestly record the caster's attendance mode alongside the vote: at
cast time the participant's `participantType` (`in-person` | `remote`) is
stamped on the vote as `castAs` (`unknown` when the participant cannot be
resolved). No session-verification theater is performed.

**Feature tier**: MVP

#### Scenario: Cast vote remotely during hybrid meeting

@e2e exclude requires a remote-attending second user with live round state; castAs stamping is covered by PHPUnit (VotingServiceCastAsTest)

- GIVEN a hybrid meeting where member is attending remotely
- WHEN the chair initiates a vote
- THEN the remote member MUST see the same voting panel as in-person attendees
- AND their vote MUST be counted with equal weight
- AND their attendance mode (remote) MUST be recorded alongside their vote as `castAs: remote`

### Requirement: VotingRound resolves a DecisionStage of method vote

A `VotingRound` SHALL be relatable to the `DecisionStage` it resolves, so that a `method=vote` stage derives its outcome from the round. The `VotingRound` relation that previously targeted the retired `motion` schema (ADR-005) SHALL be retargeted onto `DecisionStage`. Vote sub-variants — anonymous/secret ballot, personal, general, roll-call, show-of-hands — SHALL continue to be expressed via the existing `VotingRound.isSecret` and `VotingRound.votingMethod` fields and SHALL NOT be promoted to `DecisionStage.method` enum values.

#### Scenario: A voting round is linked to the stage it resolves

@e2e exclude a data-model rule with no page of its own. VotingRoundCloserStageOutcomeTest::testResultMapsToTheStageOutcome covers the stage outcome deriving from the round result, and ::testARoundWithNoStageWritesNothing covers the unlinked case.

- **GIVEN** a `method=vote` DecisionStage and a `VotingRound` with `result=adopted`
- **WHEN** the stage references the round via `votingRound`
- **THEN** the round resolves that stage and the stage outcome derives from `VotingRound.result`

#### Scenario: Secret ballot sub-variant is carried by the round

@e2e exclude a data-model rule with no page of its own. RegisterJsonTest::testVotingRoundSchema pins `isSecret` and `votingMethod` on VotingRound. No test pins that `DecisionStage.method` has no secret-ballot value.

- **GIVEN** a `VotingRound` with `isSecret=true` linked to a `method=vote` stage
- **WHEN** the ballot is configured
- **THEN** the anonymous/secret nature is carried by `VotingRound.isSecret`, not by a distinct stage method value

### Requirement: Advisory voting mode for citizen participation

The voting machinery (tally calculation, atomic tally updates, deadline/phase enforcement, duplicate-vote detection, count-integrity verification) MUST support an **advisory mode** used by citizen participation on `BudgetProposal` objects. Advisory mode MUST reuse the existing tally engine and integrity guarantees and MUST differ from statutory voting only as follows: no quorum requirement, no secret-ballot mode, no proxy votes, simple voor/tegen only (weighted and ranked methods are out of scope), votes recorded as `CitizenVote` (`schema:VoteAction`) instead of staff `Vote` objects, and results are advisory (they never produce an adopted/rejected decision outcome). Advisory tallies MUST remain strictly separate from statutory `VotingRound` tallies and MUST never be combined with them in any decision calculation.

**Feature tier**: V1
**Reuses**: open-vote tally engine, deadline enforcement, duplicate detection from the MVP voting requirements

#### Scenario: Advisory vote uses the shared tally engine

@e2e exclude service-level reuse assertion — PHPUnit verifies the advisory path delegates to the shared tally service
- **GIVEN** a `validated` budget proposal in a round in `voting` phase
- **WHEN** an authenticated citizen casts a `voor` advisory vote
- **THEN** the vote is recorded as a `CitizenVote` and the tally update runs through the same atomic tally path as statutory open votes

#### Scenario: Advisory vote requires no quorum

@e2e exclude mode-configuration branch — PHPUnit on the voting rule resolver
- **GIVEN** a budget round with any number of participants
- **WHEN** advisory voting opens
- **THEN** no quorum check is performed and voting proceeds regardless of participation level

#### Scenario: Advisory and statutory tallies never mix

@e2e exclude separation invariant — PHPUnit on the tally/result services
- **GIVEN** a motion with a statutory `VotingRound` and a related budget proposal with advisory `CitizenVote` records
- **WHEN** the statutory result is calculated
- **THEN** only `Vote` objects from the `VotingRound` are counted; `CitizenVote` records contribute nothing to the statutory outcome

#### Scenario: Duplicate detection shared with statutory voting

@e2e exclude VotingServiceAdvisoryTest::testDuplicateAdvisoryVoteRejected covers it: a second advisory vote throws and nothing is saved, so the tally is unchanged. The statutory path detects the same repeat but updates the earlier ballot instead (VotingServiceTest::testCastVoteOverwritesDuplicate). No browser test casts a second advisory vote: tracked as e2e debt in ConductionNL/decidiq#1277.

- **GIVEN** a citizen who has already cast an advisory vote on a proposal
- **WHEN** they attempt to vote again on the same proposal
- **THEN** the duplicate is rejected by the same duplicate-detection mechanism used for statutory votes and the tally is unchanged

## User Stories

1. **Chair conducting open vote**: As chair, I want to conduct an open vote (for/against/abstain) on an agenda item and see results in real-time so that I can announce the outcome immediately. (Source: intelligence DB #57)

2. **Chair conducting secret ballot**: As chair, I want to conduct a secret ballot for board elections so that members can vote freely without social pressure. (Source: intelligence DB #60)

3. **Secretary verifying qualified majority**: As secretary, I want to verify that a statute amendment vote meets the required quorum and qualified majority so that the notary can confirm proper adoption. (Source: intelligence DB #59)

4. **Member casting remote vote**: As a member attending remotely, I want to cast my vote securely during the ALV so that my participation is equal to physical attendees. (Source: intelligence DB #58)

5. **Member granting digital proxy**: As a member who cannot attend the ALV, I want to grant a proxy (volmacht) to another member digitally so that my vote is represented without paper forms. (Source: intelligence DB #63)

## Acceptance Criteria

- Open vote records individual votes per participant (for/against/abstain)
- Secret ballot stores only aggregate totals with vote count integrity verification
- Configurable majority rules: simple, qualified (2/3, 3/4), unanimous, weighted
- Proxy votes are verifiable, count toward quorum, and respect per-member limits
- Remote votes have equal weight with session verification
- Quorum is rechecked before each vote
- Tie-breaking rules are configurable per body
- All voting results mapped to OpenRaadsinformatie `Stemming`/`Stem`
- Real-time result display during voting
