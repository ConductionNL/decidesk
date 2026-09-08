---
status: in-progress
status-note: 2026-06-12 — the three audit gaps (recurring-meeting generation UI, per-recipient convocation delivery tracking, Schema.org eventAttendanceMode/virtualLocation mapping) closed by meeting-agenda-gaps-v1 (MeetingSeriesService + Series tab, BoardMeetingService noticeDeliveries + deadline warnings + delivery table, additive Meeting schema annotations). Earlier requirements built via meeting-core/-crud/-workflow/-series/-attendees/-quorum/-list-view/-detail-view + digital-meetings-and-recurrence capabilities. In progress 2026-06-14 via retire-board-portal (parallel BoardMeeting/BoardMaterial schemas + views + CalDAV bridge retired per ADR-006; corporate board meetings fold onto the universal meeting schema with mode=corp labels).
openspec-changes:
  - retire-board-portal
  - urgent-decision-procedure
---

# Meeting Management Specification

## Purpose

Meeting management covers the full lifecycle of governance meetings: creation, scheduling, attendance tracking, quorum verification, and meeting conduct. Meetings are the primary container for agenda items, decisions, and minutes. The system supports physical, digital, and hybrid meeting formats for governance bodies, associations (ALV/ledenraad), corporate boards, and operational teams.

**Standards**: Schema.org (`Event`, `EventAttendanceModeEnumeration`), Akoma Ntoso (`debate`, `session`), OpenRaadsinformatie (`Vergadering`, `Zitting`)
**Feature tier**: MVP
**Legal reference**: BW 2:38 (association ALV), Gemeentewet 17-20 (council meetings), BW 2:227 (BV shareholder meeting)

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full Meeting entity definition including property tables, Schema.org mappings, and OpenRaadsinformatie alignment.
## Requirements

---

### Requirement: Meeting Creation and Scheduling

The system MUST support creating meetings with a title, date/time, location (physical/digital/hybrid), governing body, and meeting type. Meetings MUST be stored as OpenRegister objects in the `decidesk` register using the `meeting` schema. The system MUST support scheduling recurring meetings: a recurrence pattern (`frequency`, `interval`, `until`, `exceptions` — per meeting-series REQ-MSR-001) on a template meeting MUST be expandable into individual meeting instances sharing a series identifier, capped at 52 instances. The Meeting schema MUST expose Schema.org `eventAttendanceMode` and `virtualLocation` properties with property-level `x-openregister` schemaType annotations.

**Feature tier**: MVP

#### Scenario: Create a board meeting with physical location

- GIVEN a user with meeting management access
- WHEN they create a meeting with title "Board Meeting Q1 2026", date "2026-04-15 14:00", location "Boardroom A", body "Board of Directors", type "regular"
- THEN the system MUST create an OpenRegister object with `@type` set to `schema:Event`
- AND the `eventAttendanceMode` MUST be set to `schema:OfflineEventAttendanceMode`
- AND the meeting MUST appear in the meeting list

#### Scenario: Create a hybrid ALV meeting

- GIVEN a user with meeting management access
- WHEN they create a meeting with title "ALV 2026", type "general_assembly", and attendance mode "hybrid" with both physical address and video conference link
- THEN the `eventAttendanceMode` MUST be set to `schema:MixedEventAttendanceMode`
- AND both `location` (physical) and `virtualLocation` (video link) MUST be stored

#### Scenario: Schedule a recurring monthly meeting

- GIVEN a user with meeting management access
- WHEN they create a meeting with recurrence "monthly, every 2nd Tuesday at 14:00"
- THEN the system MUST generate individual meeting instances for the specified period
- AND each instance MUST be independently editable

#### Scenario: Generate a meeting series from a recurrence pattern

- GIVEN a meeting detail page with the Series tab open
- WHEN the user configures frequency "monthly", interval 1, and an until date, and triggers series generation
- THEN the system MUST show a preview of how many instances the pattern will create
- AND on generation each created instance MUST share the series identifier with the template meeting
- AND the instances MUST be listed in the Series tab sorted by scheduled date

#### Scenario: Series generation skips exception dates and caps instances

@e2e exclude backend date-expansion semantics; covered by MeetingSeriesServiceTest (PHPUnit)

- GIVEN a recurrence pattern with an exception date and an until date more than a year away
- WHEN the series is expanded
- THEN no instance MUST be created on the exception date
- AND at most 52 instances MUST be generated, with a warning logged when the cap truncates the series

#### Scenario: Meeting schema maps attendance mode and virtual location to Schema.org

@e2e exclude schema-level mapping; asserted by RegisterJsonTest (PHPUnit), no UI surface

- GIVEN the decidesk register configuration
- WHEN the Meeting schema is inspected
- THEN the `eventAttendanceMode` property MUST enumerate `schema:OfflineEventAttendanceMode`, `schema:OnlineEventAttendanceMode`, and `schema:MixedEventAttendanceMode` with an `x-openregister` schemaType annotation
- AND the `virtualLocation` property MUST carry the `schema:VirtualLocation` schemaType annotation

### Requirement: Meeting Convocation and Notice

The system MUST support sending meeting convocations (uitnodigingen) to all members of the governing body within configurable notice periods. The system MUST track delivery status per recipient: sending a notice MUST record one delivery entry per board member (recipient, channel, status, send timestamp) on the meeting. The notice period MUST be configurable per meeting (`noticePeriodDays`, default 15) and the system MUST warn when the convocation is sent within 3 days of — or after — the statutory deadline.

**Feature tier**: MVP
**Legal reference**: BW 2:225 (42-day notice for NV, 15-day for BV), BW 2:38 (ALV notice per statutes)

#### Scenario: Send ALV convocation within statutory deadline

- GIVEN an ALV meeting scheduled for 2026-06-01 and a notice period of 15 days
- WHEN the secretary sends the convocation on 2026-05-10
- THEN the system MUST distribute the convocation to all voting members
- AND the system MUST record the send timestamp per recipient
- AND a warning MUST be shown if sending within 3 days of the deadline

#### Scenario: Include agenda and supporting documents in convocation

- GIVEN a meeting with a finalized agenda and attached documents
- WHEN the convocation is sent
- THEN the convocation MUST include the complete agenda
- AND links to all supporting documents MUST be included
- AND recipients MUST be able to access documents via the member portal

#### Scenario: Convocation records per-recipient delivery status

- GIVEN a board meeting in the scheduled state with board members
- WHEN the secretary sends the notice
- THEN the meeting MUST store one delivery entry per board member with recipient, channel, status, and send timestamp
- AND the meeting detail page MUST show the per-recipient delivery table after sending

#### Scenario: Warn when convocation is sent close to the statutory deadline

@e2e exclude time-dependent deadline arithmetic; pinned-clock coverage in agendaRules.spec.js (vitest, over src/services/noticeRules.js): computes the deadline from meetingDate minus noticePeriodDays, defaults the period to 15 days, warns within 3 days, and flags an overdue deadline. The warning surface itself is asserted by the delivery-table e2e test

- GIVEN a meeting whose statutory notice deadline (scheduled date minus `noticePeriodDays`) is at most 3 days away
- WHEN the secretary sends the notice
- THEN the send result MUST include a warning that the convocation is sent within 3 days of the deadline
- AND sending after the deadline MUST produce an after-deadline warning instead

### Requirement: Attendance and Quorum Tracking

The system MUST track attendance for each meeting and automatically calculate quorum based on the governing body's rules. Proxy votes (volmachten) MUST count toward quorum. The system MUST prevent voting from starting until quorum is confirmed.

**Feature tier**: MVP
**Legal reference**: BW 2:38 (ALV quorum), Gemeentewet 20 (council quorum)

#### Scenario: Register attendance and verify quorum is met

- GIVEN a meeting for a body with 15 members and quorum requirement of 50%+1 (8 members)
- WHEN 10 members check in (8 present, 2 via proxy)
- THEN the system MUST show quorum status as "met" with 10/15 (67%)
- AND voting MUST be enabled for the meeting

#### Scenario: Quorum not met — meeting cannot proceed to voting

- GIVEN a meeting for a body with 15 members and quorum requirement of 50%+1
- WHEN only 5 members have checked in
- THEN the system MUST show quorum status as "not met" with 5/15 (33%)
- AND the voting button MUST be disabled with a message explaining quorum is not met
- AND the chair MUST be offered the option to adjourn or wait

#### Scenario: Track proxy votes toward quorum

- GIVEN a member who has granted a digital proxy (volmacht) to another member
- WHEN the proxy holder checks in to the meeting
- THEN both the proxy holder and the represented member MUST count toward quorum
- AND the proxy relationship MUST be visible in the attendance list

---

### Requirement: Meeting List and Calendar View

The system MUST provide a list view and calendar view of meetings. Users MUST be able to filter by body, status, date range, and meeting type.

**Feature tier**: MVP

#### Scenario: View upcoming meetings in calendar format

- GIVEN the user navigates to the meetings calendar view
- WHEN meetings exist for the current month
- THEN meetings MUST be displayed on their scheduled dates
- AND each meeting MUST show title, time, body, and status indicator

---

### Requirement: Hybrid and Digital Meeting Support

The system MUST support fully digital meetings with identity verification, live participation (audio/video link), and real-time voting. Remote participants MUST have equal participation rights.

**Feature tier**: MVP

#### Scenario: Join meeting remotely with full participation rights

- GIVEN a hybrid meeting with a video conference link
- WHEN a member joins remotely via the meeting link
- THEN they MUST be able to view the agenda, participate in discussions, cast votes, and submit motions
- AND their attendance MUST be recorded as "remote"
- AND they MUST count toward quorum

### Requirement: REQ-MM-CORP — Corporate board meeting is the universal meeting (mode=corp)
A corporate board meeting MUST be the universal `meeting` (CalDAV VEVENT per
ADR-002) with mode=corp labels — never a separate schema. Accordingly the
parallel `BoardMeeting` schema (slug `board-meeting`), the
`BoardMeetingList` / `BoardMeetingDetail` Vue views, the
`BoardMeetingCreateModal`, the board-meeting routes (`boardMeeting#*`,
`board#*`, `boardMember#*`, `boardVote#*`, `boardMaterial#*`,
`resolution#*`), the board-meeting controller/service, and the
`BoardMeetingCalDavBridge` listener are REMOVED. A corporate board meeting is a
universal `meeting`; its CalDAV sync uses the universal `meeting` path
(ADR-002).

#### Scenario: No parallel board-meeting schema or routes
- GIVEN the register is imported and the app boots
- WHEN the schemas and routes are inspected
- THEN no `board-meeting` schema and no `boardMeeting#*` / `board#*` route exist
- AND the app boots without a fatal (no DI registration references a deleted board class)

#### Scenario: Board meeting CalDAV sync via the universal meeting path
- GIVEN a corporate `meeting` is created
- WHEN it is persisted
- THEN CalDAV sync occurs via the universal `meeting` listener (no `BoardMeetingCalDavBridge`)

## User Stories

1. **Secretary sending ALV convocation**: As secretary, I want to send the ALV convocation to all voting members via their preferred channel so that I can prove all members were properly notified within the statutory deadline. (Source: intelligence DB #46)

2. **Member receiving invitation**: As a member, I want to receive the ALV invitation with the complete agenda and supporting documents so that I can prepare for the meeting and decide whether to attend. (Source: intelligence DB #47)

3. **Secretary registering attendance**: As secretary, I want to register member attendance (physical and digital) and automatically calculate quorum including proxy votes so that I can confirm the meeting is valid. (Source: intelligence DB #55)

4. **Member joining remotely**: As a member, I want to join the ALV remotely via video/audio with the ability to speak, vote, and submit motions so that distance is not a barrier to participation. (Source: intelligence DB #64)

5. **Secretary handling extraordinary meeting request**: As secretary, I want to verify that an extraordinary ALV request is valid (signed by 10%+ of members) and convene the meeting within 4 weeks so that we comply with BW 2:41. (Source: intelligence DB #48)

## Acceptance Criteria

- Meetings are stored as OpenRegister objects with `@type` of `schema:Event`
- Physical, digital, and hybrid meeting modes are supported with correct Schema.org attendance modes
- Convocation tracks delivery status and respects statutory notice periods
- Quorum is automatically calculated including proxy votes
- Voting is blocked when quorum is not met
- Meeting list supports search, filter, and calendar view
- OpenRaadsinformatie `Vergadering`/`Zitting` mapping is available
