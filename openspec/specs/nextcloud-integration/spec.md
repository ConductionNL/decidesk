---
status: done
status-note: Completed 2026-06-12 via nc-platform-integration-v1 (Activity provider + publisher hooks, unified search provider, meeting Files folder tree on creation, hourly voting-deadline reminders) on top of the leaf integrations + BoardCalDavSyncService. Known limitation — per-member folder ACLs need the groupfolders app (documented in the spec).
---

# Nextcloud Integration Specification

## Purpose
@e2e exclude V1 feature — all scenarios are OCP backend integrations (Calendar IManager, Files IRootFolder, Talk IBroker, Activity IManager, Notification IManager, Search IProvider). None of the integrations have a dedicated UI page built in the SPA; they are server-side hooks with no Playwright-accessible surface.

Decidiq leverages Nextcloud's platform capabilities to provide a seamless governance experience without reinventing existing functionality. This specification covers integration with Nextcloud Calendar (meeting scheduling), Files (document management), Mail (convocation delivery), Talk (meeting communication), Tasks (action item tracking), Activity (audit feed), Notifications (alerts), Search (universal search), and References (rich link previews). Each integration uses the appropriate OCP interface.

**Standards**: Nextcloud OCP interfaces, CalDAV (RFC 4791), WebDAV
**Feature tier**: V1
## Requirements

---

### Requirement: Calendar Integration

The system MUST create Nextcloud Calendar events for scheduled meetings via `OCP\Calendar\IManager`. Calendar events MUST include meeting title, date/time, location, body, and a link back to the Decidiq meeting. Changes to the meeting schedule MUST update the calendar event.

**Feature tier**: V1

#### Scenario: Create calendar event when meeting is scheduled

- GIVEN a meeting "Board Meeting Q2" scheduled for 2026-07-15 14:00-16:00 in "Boardroom A"
- WHEN the meeting is created in Decidiq
- THEN a calendar event MUST be created in each attendee's Nextcloud Calendar
- AND the event MUST include the meeting link, agenda summary, and document links
- AND the event MUST have a reminder set to the user's configured preference

#### Scenario: Update calendar event when meeting is rescheduled

- GIVEN a meeting with associated calendar events
- WHEN the meeting date is changed from July 15 to July 22
- THEN all attendee calendar events MUST be updated to the new date
- AND attendees MUST receive a notification about the schedule change

#### Scenario: Cancel calendar event when meeting is cancelled

- GIVEN a meeting with associated calendar events
- WHEN the meeting is cancelled
- THEN all attendee calendar events MUST be cancelled
- AND attendees MUST receive a cancellation notification

---

### Requirement: Files Integration

The system MUST store and manage meeting documents using Nextcloud Files. Each meeting MUST have a dedicated folder structure created on meeting creation: `Decidesk/<body name>/<date> <title>/` with `Agenda Documents` and `Minutes` subfolders, created idempotently via OpenRegister's `FileService` under the OpenRegister-managed root (the same mechanism as adopted-motion dossier folders). Folder path components MUST be sanitised against path traversal. Folder access follows OpenRegister register sharing; per-member read-vs-write ACL differentiation (body members read, secretary/chair write) REQUIRES the groupfolders app and is out of scope for plain `OCP\Files` app code — this residue is tracked in the capability status-note.

**Feature tier**: V1

#### Scenario: Create meeting folder structure on meeting creation

@e2e exclude server-side OR-event hook with no decidiq SPA surface (folders appear in NC Files chrome); covered by PHPUnit (MeetingFolderServiceTest, MeetingFolderListenerTest)
- GIVEN a meeting "Board Meeting Q2 2026" for body "Board of Directors" scheduled 2026-07-15
- WHEN the meeting object is created (app UI or OR API)
- THEN a folder MUST be created at `Decidesk/Board of Directors/2026-07-15 Board Meeting Q2 2026/`
- AND subfolders MUST be created for "Agenda Documents" and "Minutes"
- AND re-processing the same meeting MUST NOT fail or duplicate folders (idempotent get-or-create)

#### Scenario: Attach document to agenda item via file picker

@e2e exclude the attach control is the integration registry's built-in `files` tab from nextcloud-vue, which the AgendaItemIntegrations page mounts. integration-registry.spec.ts proves that tab mounts, but only on the meeting integrations page. No test picks a file, links it to an agenda item or checks the meeting document package: genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.
- GIVEN a user editing an agenda item
- WHEN they click "Attach Document" and select a file from Nextcloud Files
- THEN the file MUST be linked to the agenda item
- AND the file MUST be accessible to all meeting participants
- AND the file MUST appear in the meeting document package

### Requirement: Talk Integration

The system MUST create Nextcloud Talk conversations for meetings via `OCP\Talk\IBroker`. Meeting participants MUST be automatically added to the conversation. The conversation MUST serve as the communication channel for meeting preparation and follow-up.

**Feature tier**: V1

#### Scenario: Create Talk conversation for a meeting

- GIVEN a meeting "ALV 2026" with 50 participants
- WHEN the meeting is created
- THEN a Talk conversation MUST be created with all participants
- AND the conversation name MUST be "ALV 2026"
- AND the conversation description MUST include the meeting date and agenda link

---

### Requirement: Activity Integration

The system MUST publish Decidiq events to the Nextcloud Activity feed via `OCP\Activity\IManager` under a registered activity type `decidesk_governance` with an `OCP\Activity\IProvider`, an `ActivitySettings` entry, and an `OCP\Activity\IFilter` declared in `appinfo/info.xml`. Events MUST include: decision recorded, decision published (status change), meeting lifecycle transitions (both operational meetings and board meetings), vote initiation (voting rounds and board resolution votes), and resolution adoption. Activity publication MUST be fail-soft: a failure to publish MUST never abort the underlying governance transition.

**Feature tier**: V1

#### Scenario: Decision status change appears in Activity feed

@e2e exclude NC Activity stream is platform chrome (no decidiq SPA surface); provider parse + publish call sites covered by PHPUnit (DecidiqProviderTest, ActivityPublisherServiceTest)
- GIVEN a decision "Approve Budget 2026" is published (status change to published)
- WHEN the transition is completed
- THEN an Activity entry MUST be created with subject `decision_published` and the decision title
- AND the entry MUST be addressed to the members of the governing body resolvable for the event plus the acting user
- AND the entry's link MUST navigate to the decision in Decidiq (`/apps/decidiq/#/decisions/{uuid}`)

#### Scenario: Meeting lifecycle transition appears in Activity feed

@e2e exclude NC Activity stream is platform chrome; covered by PHPUnit on MeetingService/BoardMeetingService call sites
- GIVEN a meeting transitions lifecycle (e.g. scheduled → opened)
- WHEN the transition succeeds
- THEN an Activity entry with subject `meeting_transition` MUST be published carrying the meeting title and the new lifecycle state
- AND a failing Activity backend MUST NOT make the transition fail

#### Scenario: Vote initiation and resolution adoption appear in Activity feed

@e2e exclude NC Activity stream is platform chrome; covered by PHPUnit on VotingService/ResolutionService call sites
- GIVEN a voting round is opened on a motion, or a board resolution vote is opened, or a resolution concludes as adopted
- WHEN the operation succeeds
- THEN an Activity entry MUST be published with subject `vote_initiated` (round/vote opening) or `resolution_adopted` (adoption) and a deep link to the object

### Requirement: Notification Integration

The system MUST send Nextcloud Notifications for time-sensitive governance events: upcoming meeting reminders, pending votes, voting deadlines approaching, and action item due dates. The voting-deadline reminder MUST be produced by a scheduled background job (`TimedJob`, hourly, registered via `appinfo/info.xml` `<background-jobs>`) that notifies each eligible participant who has not yet voted in an open voting round whose deadline falls within the next 24 hours, exactly once per round (a `deadlineReminderSentAt` marker prevents duplicates).

**Feature tier**: V1

#### Scenario: Send pending vote notification

@e2e exclude the bell is Nextcloud chrome, and no test in this repo checks the vote-opened notification: the voting unit tests construct VotingOpenedNotifier but never assert on it. Reading the code, subjectTitle() looks the subject up in a `motion` schema that no longer exists, so the title falls back to the literal "Motion" (see #391). Genuine coverage gap tracked as e2e debt in ConductionNL/decidiq#1277.
- GIVEN a new vote has been initiated for decision "Policy Update"
- WHEN the vote opens
- THEN all eligible voters MUST receive a Nextcloud notification
- AND the notification MUST include the decision title, body, and voting deadline
- AND tapping the notification MUST open the voting interface

#### Scenario: Send voting deadline reminder

@e2e exclude background-job + NC notification chrome (no decidiq SPA surface); window calculation, selection, skip-already-voted and dedup covered by PHPUnit (VotingDeadlineReminderServiceTest, VotingDeadlineReminderJobTest)
- GIVEN an open voting round with `closedAt` 20 hours from now and a participant who has not yet voted
- WHEN the hourly reminder job runs
- THEN that participant MUST receive a notification "Reminder: your vote on '{motion}' is due soon" with a deep link to the voting round
- AND participants who already voted MUST NOT be notified
- AND a second job run MUST NOT send the reminder again (round is stamped `deadlineReminderSentAt`)

### Requirement: Search Integration

The system MUST register a search provider via `OCP\Search\IProvider` (`IRegistrationContext::registerSearchProvider`) so that decisions, meetings, and resolutions are findable from Nextcloud's universal search. The provider MUST query OpenRegister's `ObjectService` full-text search with RBAC enabled under the searching user's session so that ONLY objects the user is permitted to read are returned (per-user visibility is a security requirement). Results MUST deep-link into the corresponding Decidiq detail route.

**Feature tier**: V1

#### Scenario: Find a decision via Nextcloud search

@e2e exclude NC universal-search UI is platform chrome (no decidiq SPA surface); provider behaviour covered by PHPUnit (DecidiqSearchProviderTest)
- GIVEN decisions exist including "Budget 2026 Approval"
- WHEN the user searches for "budget" in Nextcloud's universal search
- THEN the Decidiq search provider MUST return "Budget 2026 Approval" as a result
- AND the result MUST show the decision title, a status subline, and the Decidiq app icon as thumbnail
- AND clicking the result MUST navigate to the decision detail view in Decidiq

#### Scenario: Search results respect per-user visibility

@e2e exclude RBAC delegation is asserted at the service boundary by PHPUnit; OR RBAC itself is owned and e2e-tested by openregister
- GIVEN a decision the searching user has no OpenRegister read access to
- WHEN the user searches for that decision's title
- THEN the provider MUST NOT return it (the provider queries ObjectService with RBAC enabled and never passes `_rbac: false`)

## User Stories

1. **Board member accessing board pack on mobile**: As a supervisory board member, I want to access the board pack on my tablet or smartphone with offline capability, so that I can prepare for meetings while traveling. (Source: intelligence DB #18)

2. **Shareholder accessing AGM documents online**: As a shareholder, I want to access all AGM documents (agenda, annual report, resolution proposals) through a secure online portal, so that I can prepare for the meeting at my convenience. (Source: intelligence DB #3)

3. **Secretary assembling board pack**: As a board secretary, I want to assemble board packs by combining documents from multiple sources into a structured, indexed package, so that board members receive a complete, well-organized set of meeting materials. (Source: intelligence DB #17)

4. **Board member declaring conflict of interest**: As a board member, I want to formally declare a conflict of interest for a specific agenda item so that I am properly excluded from the decision and this is recorded per WBTR. (Source: intelligence DB #69)

5. **Member accessing decision history**: As a member, I want to access meeting minutes, financial reports, and decision history through a self-service portal so that I can stay informed about association governance. (Source: intelligence DB #80)

## Acceptance Criteria

- Calendar events are created/updated/cancelled for meetings via OCP\Calendar\IManager
- Meeting folders are created in Nextcloud Files with correct access controls
- Talk conversations are created for meetings with participant auto-enrollment
- Activity feed entries are published for all major governance events
- Notifications are sent for pending votes, meeting reminders, and deadlines
- Search provider returns decisions, meetings, and resolutions from universal search
- All integrations use OCP interfaces (not direct database access or internal APIs)
- Integrations degrade gracefully when optional apps (Talk, Mail) are not installed
