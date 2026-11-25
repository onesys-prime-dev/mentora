# Existing implementation audit (permission and discovery)

Stage 1 inventory, 2026-11-23; final calls added in Stage 3 (pending plan approval). The phase that changes each item is in brackets; see `implementation-plan.md`.

## Permission core

| Piece | What it does today | Proposal |
|---|---|---|
| `app/Services/Messaging/MessagingPolicy.php` | Pure logic. `START` table: sender role → recipient role → one fact (`sharedCourse`, `sameCollege`, `inScope`, `linkedChild`, or nothing). Nobody but the inst. admin can start a thread *to* an inst. admin. Chair → parent absent (reply only). `UNBLOCKABLE = instructor, institutional_admin`. Also `canPost`, `canView`, `canModerate`, `canManageControls`, `canReact`, `canSeeParentFeed`. | Keep the class and its "pure, one place" shape; rewrite the `START` table to the new rules. |
| `MessagingContextResolver.php` | Builds `StartContext` / `PostContext` from the DB. `activeRole()` reads the session role for the viewer and the saved `user_profiles.active_role` (or preferred role) for anyone else. Refuses impersonation and the system admin. | Keep fact-building; replace `activeRole()` usage (single-identity). |
| `StartContext.php` / `PostContext.php` | Fact bags. | Keep, change fields as rules change. |
| `StudentMessaging.php` | Institution switch `studentDmEnabled` (on by default; off = no new student↔student threads, existing ones read-only). | Decide in discovery. |
| `BlockService.php` | A blocks B: B cannot start or post in the DM with A. Cannot block instructors or inst. admins. Audited. | Keep; revisit the unblockable list. |
| `MessageRateLimiter.php` | 30 msgs/min, 60 reactions/min, 10 new threads/hour, 20 reports/day. | Keep; revisit thread limit if reach widens. |
| `ReportService.php`, `ReportReviewService.php`, `ReportsPageController` | Reports on messages; admin review page. | Keep. |
| `GroupService.php` | Staff groups: chair adds instructors of their scope, dean adds instructors + chairs; max 30 members. | Decide in discovery. |
| `MessagePreferences.php` | Email alerts for everyone; quiet hours only for active role in `QUIET_ROLES` (instructor, chair, dean). | Decide; drop active-role dependence. |
| `app/Services/Parents/ParentPreferences.php` | Parent's per-child email choice. | Keep. |
| `ParentFeedService.php` | Parent's read-only feed of instructor posts in a child's course chat. | Keep unless discovery changes parent rules. |
| `app/Services/Subscriptions/PlanLimits::institutionOf` | First institution membership by `institution_users.id`. A person in two institutions is in Messages only in the first. | Decide in discovery. |

## Discovery

| Piece | Today | Proposal |
|---|---|---|
| `RecipientSearch.php` + `RecipientController` (`GET messages/recipients`) | Name search (≥2 chars) in the sender's institution, 200 candidates, each run through `canStart`; parents offered once per shared child. | Keep shape; widen with the rules; performance review if rules no longer filter much. |
| `GroupController::candidates` | Same pattern for group members. | Follows the group decision. |
| `MessagesPageController` | `canStartGroups` from the active role. | Replace active-role read. |
| Profile popover/modal (User Profile, not built yet) | D20/D32: Message button iff `canStart`. | Will widen automatically once rules change. |

## Frontend (`resources/js/pages/messages/`)
`picker.js` (recipient picker), `group.js`, `safety.js` (block/report), `settings.js` (preferences), `messages.js`, `composer.js`, `render.js`, `poller.js`, `phone-nav.js` and others. View: `app/Views/pages/messages/index.php`.

## Data (must survive every phase)
Conversations, participants, messages, attachments, reactions, pins, mutes/archives (`ChatStateService`), `user_blocks`, `message_reports`, `message_preferences`, `parent_child_preferences`, the `studentDmEnabled` setting. Migration: `2026-11-21-000001_CreateConversationTables.php` and later.

## Spec vs code notes
- Spec §3.1 table and the code agree. Spec text: "a person who holds two roles messages under their active role", which the single-identity track removes.

## Active-role reads to remove (Phase 1)
`MessagingContextResolver.php` :9, :33-47, :65, :66, :115, :168 · `BlockService.php` :44 · `ChatControls.php` :56, :75, :101 · `ConversationPresenter.php` :45, :122 · `ConversationService.php` :48, :49, :130 · `GroupService.php` :51, :76, :98, :159 · `MessagePreferences.php` :47 · `MessageService.php` :549 · `RecipientSearch.php` :55, :66 · `ReportReviewService.php` :181 · `Controllers/Messaging/MessagesPageController.php` :8, :27, :28 · `ReportController.php` :64 · `ReportsPageController.php` :75 · `Filters/MessagesFilter.php` :8, :34 · JS `messages.js` :1011 (`quiet_hours_allowed`), :1039 (`canGroup`).

## Final calls (Stage 3)
| Item | Call | Phase |
|---|---|---|
| `MessagingPolicy` class, `canView`, `canManageControls`, `canPinMessage`, `canSeeParentFeed` | Reuse | - |
| `MessagingPolicy::START`, `UNBLOCKABLE` | Replace | 7 / 2 |
| `canStart`, `canModerate` signatures (roles → `Party`) | Refactor | 1 |
| `MessagingContextResolver` facts; `activeRole()` | Refactor; retire `activeRole` | 1, 2, 4, 7 |
| `StartContext` / `PostContext` | Replace fields | 1, 2, 4, 7 |
| `BlockService` | Refactor (protected link, paused list) | 2 |
| `user_blocks` rows of instructor/admin-only blockers | Move to `user_block_history` [DATA MOVE] | 2 |
| `ConversationService::startDirect` | Refactor into draft + `MessageService::sendFirst` | 3 |
| `MessageRateLimiter` | Refactor (tiers, daily cap) | 3 |
| `MessageNotifier` | Refactor (email scope, institution quiet default) | 3, 5 |
| `ReportService`, `ReportReviewService`, reports page | Refactor (pause, DM removal, cases by person) | 4 |
| New `RestrictionService`, `message_restrictions` | Add | 4 |
| `StudentMessaging` on/off | Replace with scope; old key kept | 5 |
| `MessagePreferences::QUIET_ROLES`, `quietHoursAllowed` | Retire | 5 |
| New `MinorFlag`, `institution_users.is_minor`, CSV column | Add | 5 |
| `GroupService` (`MEMBER_ROLES`, 30 members) | Refactor (owner model, 50, D26) | 6 |
| `RecipientSearch` 200-candidate scan | Refactor (paged) | 7 |
| Encryption, course chats, parent feed, chat controls/state, poller, reactions | Reuse | - |
| Duplicate route `conversations/(:num)/read` (:587/:601) | Pin with a test, no change | 1 |
