# Messages Slice 1 — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lay the base the rest of Messages stands on: schema and models, the `messages` flag and `messages.use` permission, the message cipher and its key, the complete `MessagingPolicy`, and the navigation tile. No routes, no controllers, no UI.

**Architecture:** `MessagingPolicy` is pure (no database): it takes roles plus a small value object of facts and answers yes/no, so the whole role matrix is one data-provider unit test. A database-backed resolver that builds those facts from `CourseMembership`, `ChairpersonAccessService`, `DeanAccessService` and `ParentAccessService` arrives in slice 2. `MessageCipher` wraps CodeIgniter's encrypter with a dedicated key and binds each ciphertext to its conversation and sender.

**Tech Stack:** PHP 8.2, CodeIgniter 4.7, Shield, MySQL/MariaDB, PHPUnit (`composer test`).

**Spec:** `docs/superpowers/specs/2026-11-02-messages-design.md` (this is slice 1 of section 7, branch `feat/messages-foundation`).

## Global Constraints

- Work on branch `feat/messages-foundation` in this worktree. Commit locally; **do not push or open a PR** until told.
- `git status` before staging; stage only the files named in the task.
- Run only the tests named in each step. CI runs the full suite.
- Match neighbouring code: `declare(strict_types=1)` in new files, comment density and naming of the files you copy from.
- Migrations: new files **must sort after `2026-11-20-000004_…`**. The spec says "after 2026-11-17", but the repo has moved on; use `2026-11-21-00000N_`.
- Message text has **no plaintext column**; never log or audit message text.
- Role names: `student`, `instructor`, `chairperson`, `dean`, `parent`, `institutional_admin`, `admin`.
- The system administrator (`admin`) has no access to Messages at all; `messages.use` is offered to every role except `admin`.
- Flag key `messages`, default **off**. Permission key `messages.use`. Encryption key setting `messages.encryptionKey` (≥ 32 bytes).
- Instructors and institutional admins cannot be blocked (spec 3.3 rule 6).

## Review Focus

- `admin` as sender or recipient is refused for every counterpart, including `institutional_admin` → `admin` (Task 4).
- A `blocked` fact against an instructor or institutional-admin recipient is ignored, but against a student, chairperson, dean or parent it refuses (Task 4).
- A key shorter than 32 bytes, an empty key, and malformed hex make the cipher unusable and `encrypt` throw, never silently weaken (Task 2).
- A body that is empty, 4000 multibyte characters, emoji or right-to-left text round-trips unchanged (Task 2).
- Two direct conversations (both `offering_id` NULL, distinct `direct_key`) coexist, but a second course chat for one offering is refused (Task 3).

---

### Task 1: `messages` flag and `messages.use` permission

**Files:**
- Modify: `app/Config/FeatureFlags.php` (add the flag after `parent.portal`)
- Modify: `app/Config/AuthGroups.php` (permission description, `DEFAULT_MATRIX`, `APPLICABLE`)
- Modify: `app/Services/Institutions/InstitutionRoles.php:34` (the list that holds `children.view`)
- Modify: `docs/roles-and-permissions.md`
- Test: `tests/unit/AuthGroupsConfigTest.php`, `tests/database/ApplicablePermissionsTest.php`

**Interfaces:**
- Produces: flag key `messages` (read with `feature_enabled('messages')` or `(new FeatureFlags())->isEnabled('messages', $institutionId)`); permission key `messages.use`, held by `institutional_admin`, `dean`, `chairperson`, `instructor`, `student`, `parent`, and by no `admin` row.

- [ ] **Step 1: Write the failing tests.**
  - In `AuthGroupsConfigTest`: add `'messages.use'` to the `ACADEMIC` constant, and add `testEveryRoleExceptTheSystemAdminHoldsMessagesUse` asserting `messages.use` is in `DEFAULT_MATRIX[$role]` and `APPLICABLE[$role]` for the six institution roles and in neither for `admin`.
  - In `ApplicablePermissionsTest`: add `testMessagesUseIsOfferedToEveryInstitutionRole`, asserting `PermissionMatrix::applicable($role)` contains `messages.use` for the six roles and `applicable('admin')` does not.
  - Add `testMessagesFlagShipsOff` (in `tests/unit/FeatureFlagResolverTest.php` or the nearest flag-config test): `(new \Config\FeatureFlags())->flags['messages']['default'] === false`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/unit/AuthGroupsConfigTest.php tests/unit/FeatureFlagResolverTest.php tests/database/ApplicablePermissionsTest.php`. Expected: the new tests FAIL.
- [ ] **Step 3: Implement.** Add the flag with description "Messages: course chats, direct messages and staff groups." and `default => false`. Add `'messages.use' => 'Use Messages'` to the permission list, to each of the six roles in both `DEFAULT_MATRIX` and `APPLICABLE`, and to the `InstitutionRoles` list beside `children.view`. Add a `messages.use` row/mention to `docs/roles-and-permissions.md` in the same style as its neighbours.
- [ ] **Step 4: Run** the same command. Expected: PASS. Also run `vendor/bin/phpunit --no-coverage tests/database/PermissionMatrixTest.php` and fix any list it pins.
- [ ] **Step 5: Commit.** Stage the files above only: `git commit -m "feat(messages): add the messages flag and messages.use permission"`.

---

### Task 2: `MessageCipher` and the key config

**Files:**
- Create: `app/Config/Messages.php`
- Create: `app/Services/Messaging/MessageCipher.php`
- Modify: `.env.example` (document `messages.encryptionKey` and `messages.previousKeys`), `tests/_support/ci-full.env` (add a fixed test key in the same `hex2bin:` form as `encryption.key`)
- Test: `tests/unit/MessageCipherTest.php`

**Interfaces:**
- Produces:
  - `Config\Messages extends BaseConfig` with `public string $encryptionKey = ''` and `public array|string $previousKeys = ''` (comma-separated string or array, same convention as `Config\Encryption`).
  - `App\Services\Messaging\MessageCipher::__construct(?Config\Messages $config = null)`.
  - `isUsable(): bool` — true only when the current key resolves to ≥ 32 bytes.
  - `encrypt(string $body, int $conversationId, int $senderId): string` — returns base64 text safe for a MEDIUMTEXT column; throws `\RuntimeException` when not usable.
  - `decrypt(string $stored, int $conversationId, int $senderId): ?string` — returns `null` on any failure (bad base64, tamper, wrong key, binding mismatch); never throws.

- [ ] **Step 1: Write the failing tests** in `MessageCipherTest` (extends `CIUnitTestCase`, builds `Config\Messages` by hand with a 32-byte `hex2bin:` key; no database). One test per line:
  - `testRoundTrip` — `decrypt(encrypt('hello', 5, 9), 5, 9) === 'hello'`.
  - `testSameTextEncryptsDifferentlyEachTime` — two ciphertexts of one body differ.
  - `testStoredStringContainsNoPlaintext` — `assertStringNotContainsString('hello', $stored)`.
  - `testTamperedCiphertextIsUnreadable` — flip one byte of the decoded payload, re-encode, `decrypt` is `null`.
  - `testCiphertextMovedToAnotherConversationOrSenderIsUnreadable` — `decrypt($stored, 6, 9)` and `decrypt($stored, 5, 8)` are `null`.
  - `testAPreviousKeyStillDecrypts` — encrypt with key A; a new cipher with key B current and A in `previousKeys` decrypts it, and new encryptions use B.
  - `testNoKeyFailsClosed` — empty key: `isUsable()` false, `encrypt` throws, `decrypt` of anything is `null`.
  - `testShortKeyIsNotUsable` — a 16-byte key and a malformed `hex2bin:zz` key are both unusable and `encrypt` throws.
  - `testUnusualBodiesRoundTrip` — data provider: `''`, 4000 × `'é'`, `'👩‍🏫 ok'`, `'שלום עולם'`, a body containing `"\n"` and `"</script>"`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/unit/MessageCipherTest.php`. Expected: FAIL, class not found.
- [ ] **Step 3: Implement** both classes. Build a `Config\Encryption` copy whose `key`/`previousKeys` come from `Config\Messages` (leave driver, cipher, digest at their defaults) and hand it to `Services::encrypter($config, false)`; the encrypted payload is the JSON envelope `{"c": conversationId, "s": senderId, "b": body}`, and `decrypt` returns the body only when `c` and `s` equal the arguments. Check how `Encryption` treats the `hex2bin:` prefix before deciding where to resolve the key for the length check.
- [ ] **Step 4: Run** the same command. Expected: PASS.
- [ ] **Step 5: Commit.** Stage the four files: `git commit -m "feat(messages): add MessageCipher with its own encryption key"`.

---

### Task 3: Migrations and models

**Files:**
- Create: `app/Database/Migrations/2026-11-21-000001_CreateConversationTables.php` (`conversations`, `conversation_participants`)
- Create: `app/Database/Migrations/2026-11-21-000002_CreateMessageTables.php` (`messages`, `message_hidden`)
- Create: `app/Database/Migrations/2026-11-21-000003_CreateMessageSafetyTables.php` (`user_blocks`, `message_reports`, `message_preferences`)
- Create: `app/Models/Messaging/ConversationModel.php`, `ConversationParticipantModel.php`, `MessageModel.php`, `MessageHiddenModel.php`, `UserBlockModel.php`, `MessageReportModel.php`, `MessagePreferenceModel.php`
- Modify: `tests/database/MigrationsTest.php` (add the seven tables to `provideCoreTables`)
- Test: `tests/database/MessagingMigrationsTest.php`

**Interfaces:**
- Produces: the seven tables exactly as spec section 4 lists them (columns, uniques, indexes), with these decisions:
  - Column types follow `CreateDepartmentAnnouncementsTable` (`INT` unsigned ids, `TINYINT` constraint 1 for booleans, `DATETIME` for timestamps, `MEDIUMTEXT` for `body_enc` and `snapshot_enc`); if a foreign key refuses to create, match the referenced column's exact type.
  - `institution_id` and user columns are foreign keys (`CASCADE`, `SET NULL` for nullable `created_by`/`removed_by`/`reviewed_by`, as in neighbouring migrations). `conversations.last_message_id` and `conversation_participants.*_message_id` are plain indexed columns, **no** foreign key (circular).
  - MySQL has no partial indexes: `UNIQUE(offering_id)` and `UNIQUE(direct_key)` on `conversations` are plain uniques, which is correct because both are NULL for the other conversation types.
  - `message_reports.reason` is `VARCHAR(20)` (values `harassment`, `spam`, `inappropriate`, `other`), `note_enc` MEDIUMTEXT NULL.
  - `message_preferences` primary key is `user_id`; `email_alerts` defaults to 1.
- Models: `$table`, `$primaryKey`, `$returnType = 'array'`, `$allowedFields` listing every column except the key, `$useTimestamps = true` only where the table has both `created_at` and `updated_at` (only `conversations`); style of `DiscussionReadModel`. No query methods yet.
- `down()` drops in reverse dependency order (children before parents).

- [ ] **Step 1: Write the failing test** `MessagingMigrationsTest` (extends `DatabaseTestCase`, same migrate-commits caveat and `tearDown` pattern as `CreateInstitutionInvitationsMigrationTest`). Test names:
  - `testTheTablesExist` — all seven.
  - `testOneCourseChatPerOffering` — second `type='course'` row for one `offering_id` throws `DatabaseException`.
  - `testManyDirectConversationsCoexistButADirectKeyIsUnique` — two rows with NULL `offering_id` and distinct `direct_key` insert; a repeated `direct_key` throws.
  - `testAParticipantJoinsAConversationOnce`.
  - `testARetriedSendDoesNotDuplicate` — repeated `(conversation_id, sender_id, client_uuid)` throws.
  - `testDeletingAnInstitutionRemovesItsConversationsAndMessages`.
  - `testMessagesHaveNoPlaintextColumn` — `getFieldNames('messages')` contains `body_enc` and none of `body`, `text`, `preview`.
  - `testTheMigrationsCanBeUndoneAndRedone` — run `down()` then `up()` for all three, in reverse then forward order.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/database/MessagingMigrationsTest.php`. Expected: FAIL, tables missing.
- [ ] **Step 3: Implement** the migrations and models. Use `ConversationModel` etc. as the thin classes described above.
- [ ] **Step 4: Run** the same command, then `vendor/bin/phpunit --no-coverage tests/database/MigrationsTest.php`. Expected: PASS.
- [ ] **Step 5: Commit.** `git commit -m "feat(messages): add the messaging tables and models"`.

---

### Task 4: `MessagingPolicy`

**Files:**
- Create: `app/Services/Messaging/StartContext.php`, `app/Services/Messaging/PostContext.php`, `app/Services/Messaging/MessagingPolicy.php`
- Test: `tests/unit/MessagingPolicyTest.php`

**Interfaces:**
- Produces (all `final`, namespace `App\Services\Messaging`, no database access):
  - `StartContext` — `readonly` constructor properties, all `bool`: `sameInstitution = true`, `senderActive = true`, `recipientActive = true`, `sharedCourse = false`, `sameDepartment = false`, `inScope = false`, `linkedChild = false`, `studentDmEnabled = true`, `blockedByRecipient = false`. Meanings: `sharedCourse` both active in one non-closed offering; `inScope` the sender's sub-department (chairperson) or department (dean) covers the recipient; `linkedChild` the parent's linked child is taught by that instructor or chaired by that chairperson.
  - `PostContext` — `readonly`: `string $type` (`course|direct|group`), `bool $activeParticipant`, `$accountActive`, `$institutionReadOnly = false`, `$offeringClosed = false`, `$locked = false`, `$isCourseInstructor = false`, `$blocked = false`, `$studentDmBlocked = false`. (`studentDmBlocked` = a student↔student direct thread while the institution switch is off.)
  - `MessagingPolicy::canStart(string $senderRole, string $recipientRole, StartContext $c): bool`
  - `canPost(PostContext $c): bool`
  - `canView(bool $activeParticipant, bool $sameInstitution): bool`
  - `canModerate(string $role, string $conversationType, bool $inScope): bool` — `inScope` is "instructor of that course" for `course`, "created or leads that staff group" for `group`; `institutional_admin` moderates any `course` or `group`; `direct` is never moderated.
  - `canSeeParentFeed(bool $linked, bool $childEnrolled, string $senderRole): bool` — true only when linked, enrolled, and `$senderRole === 'instructor'`.

  Matrix for `canStart` (each cell needs its context fact; every cell also needs `sameInstitution`, `senderActive`, `recipientActive`, and not blocked):

  | sender → recipient | needs |
  |---|---|
  | student → student | `sharedCourse` and `studentDmEnabled` |
  | student → instructor | `sharedCourse` |
  | instructor → student | `sharedCourse` |
  | instructor → instructor / chairperson / dean | `sameDepartment` |
  | instructor → parent | `linkedChild` |
  | chairperson → student / instructor | `inScope` |
  | chairperson → chairperson / dean | `sameDepartment` |
  | dean → student / instructor / chairperson | `inScope` |
  | dean → dean | nothing more |
  | parent → instructor / chairperson | `linkedChild` |
  | institutional_admin → any of the six institution roles | nothing more |
  | everything else (including chairperson → parent, dean → parent, student → chairperson/dean/parent/admin, anyone → institutional_admin except institutional_admin itself, and any use of `admin`) | refused |

  `blockedByRecipient` refuses unless the recipient role is `instructor` or `institutional_admin`.
  `canPost` is false unless `activeParticipant && accountActive && !institutionReadOnly && !offeringClosed`; a locked `course` chat additionally needs `isCourseInstructor`; a `direct` thread is refused when `blocked` or `studentDmBlocked`.

- [ ] **Step 1: Write the failing test** `MessagingPolicyTest` (extends `CIUnitTestCase`):
  - `testStartMatrix` — data provider yielding **every ordered pair of the seven roles** (49 rows) × each of: the fact that unlocks it present, and absent. Expected values are the table above written out as data, so a reviewer can compare it with spec 3.1; a helper builds the `StartContext` with only the named fact set.
  - `testNoStartAcrossInstitutionsOrFromAnInactiveAccount` — a permitted pair with `sameInstitution`, `senderActive` or `recipientActive` false is refused.
  - `testStudentDmSwitchOnlyAffectsStudentToStudent` — switch off refuses student → student, still allows student → instructor.
  - `testABlockStopsEveryoneButInstructorsAndInstitutionalAdmins` — `blockedByRecipient: true` for each recipient role.
  - `testAChairpersonCannotStartWithAParentButMayReply` — `canStart` false; `canPost` true for an active participant in a `direct` thread.
  - `testPostRules` — provider: not participant, deactivated, read-only institution, closed offering, locked course chat (student refused, instructor allowed), blocked direct, switch-off student thread, group chat allowed.
  - `testViewNeedsAnActiveParticipantInTheSameInstitution`.
  - `testModerationRules` — instructor in own course chat yes, in another no; chairperson/dean in own group yes; institutional admin course/group yes; direct never, for every role.
  - `testParentFeedShowsOnlyInstructorPostsOfALinkedEnrolledChild`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/unit/MessagingPolicyTest.php`. Expected: FAIL, class not found.
- [ ] **Step 3: Implement** the three classes. Encode the matrix as a private constant map keyed `sender => [recipient => requirement]` rather than nested conditionals, so the code reads like the table.
- [ ] **Step 4: Run** the same command. Expected: PASS.
- [ ] **Step 5: Commit.** `git commit -m "feat(messages): add MessagingPolicy with the full role matrix"`.

---

### Task 5: Navigation — Inbox becomes Messages

**Files:**
- Modify: `app/Helpers/navigation_helper.php` (`global_apps`)
- Modify: `tests/unit/NavigationHelperTest.php`
- Test: `tests/database/MessagesNavigationTest.php`

**Interfaces:**
- Consumes: `feature_enabled('messages')` from `app/Helpers/feature_helper.php` (load with `helper('feature')`); `RolePermissions::allows($user, 'messages.use', $role)`.
- Produces: `messages_unread_count(): int` in `navigation_helper.php` (stub, returns `0`, docblock says slice 2 fills it in); `global_apps($role)` includes `['key' => 'messages', 'label' => 'Messages', 'icon' => 'inbox', 'url' => site_url('messages'), 'badge' => messages_unread_count()]` for `student`, `instructor`, `chairperson`, `dean`, `parent` and `institutional_admin` **only when `feature_enabled('messages')` is true**; never for `admin`. The dead `inbox` tile is removed.

  Decision (mine, not the spec's): the route does not exist until slice 2, and the parent navigation test requires every tile URL to resolve to a route, so the tile is hidden while the flag is off. It appears where the existing `inbox` tile sat (last of the base apps) and last for the dean, parent and institutional admin.

- [ ] **Step 1: Write the failing tests.**
  - Update `NavigationHelperTest`: remove `'inbox'` from the expected student keys, delete the `assertNull($apps['inbox'])` line, and add `testNoInboxTileAnyMoreAndNoMessagesTileWhileTheFlagIsOff` (every role including `admin` has neither `inbox` nor `messages` when nothing enables the flag).
  - `MessagesNavigationTest` (extends `DatabaseTestCase`): enable the flag globally with `FeatureFlagOverrideModel` as `ParentPortalTest` does, sign in a user with each role, and assert `testTheMessagesTileAppearsForEveryRoleButTheSystemAdmin` (key `messages`, `url === site_url('messages')`, `badge === 0`; `admin` has no such tile), plus `testTheTileIsHiddenWhenTheRoleLosesMessagesUse` (remove `messages.use` for `student` through `PermissionMatrix`, as `ApplicablePermissionsTest` does, and the student's tile disappears).
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/unit/NavigationHelperTest.php tests/database/MessagesNavigationTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.** Remove the `inbox` entry from `$apps`; after the per-role additions at the end of `global_apps`, and in the dean, parent and institutional-admin branches before their `return`, append the tile through one small private helper (`messages_tile(string $role): ?array`) that returns `null` when the role is `admin`, the flag is off, or the signed-in user lacks `messages.use` for that role.
- [ ] **Step 4: Run** the same command plus `vendor/bin/phpunit --no-coverage tests/feature/ParentRoleRoutesTest.php`. Expected: PASS (the parent tile resolves only once slice 2 adds the route; with the flag off it is absent).
- [ ] **Step 5: Commit.** `git commit -m "feat(messages): replace the Inbox tile with Messages behind the flag"`.

---

## Slice 1 close-out

- [ ] Run `vendor/bin/phpstan analyse --memory-limit=1G` (config `phpstan.neon`, no new baseline entries); ESLint is not needed (no JS in this slice). Fix findings in the files this slice touched.
- [ ] `git status` shows only this slice's files, plus the untracked `docs/superpowers/specs/2026-11-02-messages-design.md` and this plan, which belong in the first commit of the stack: `git add docs/superpowers && git commit -m "docs(messages): add the Messages design and the slice 1 plan"`.
- [ ] Leave the branch unpushed. The next branch, `feat/messages-direct`, is cut from this one.

## Self-review notes

- Spec coverage for slice 1: migrations/models (Task 3), `messages` flag and `messages.use` permission and docs (Task 1), cipher and key config (Task 2), full policy matrix (Task 4), navigation with badge hook (Task 5). Not in this slice by the spec's own table: the `student_dm_enabled` institution setting (slice 6), the flag-enable guard and key health check (slice 7), `messages:reencrypt` (slice 7), `docs/deploy.md`/`docs/backup.md` key notes (slice 8), and the resolver that builds `StartContext` from the database (slice 2).
- Spec says Inbox tile becomes Messages "for every role except system admin"; this plan additionally hides it while the flag is off (see Task 5).
