# P2 test cases: Messages

26 cases in the browser, and the PHP tests that stand behind the rules. Read
[README.md](README.md) first. All are `Draft` (see the README).

`tests/e2e/messages.spec.js` holds the cases. They run in order on one database.
Messages ships **off**, so the spec switches it on first (a global `messages`
flag row) and runs `php spark messages:sync-course-chats`, because the demo seeder
writes offerings straight into the tables and nothing has made their chats. The
browser server gets its encryption key from the environment
(`messages_encryptionKey`, see `docs/testing.md`). When the cases finish the spec
switches Messages off again and clears the chats, notifications, blocks, reports
and settings it made, because the next spec (`parent.spec.js`) expects four tabs
on a parent's course page.

The demo data they use (`app/Database/Seeds/DemoUsersSeeder.php` and friends):

| Person | Account | Where they are |
|---|---|---|
| Michael Villanueva | `student5@mentora.test` | IT101-A and IT201-A |
| Juan Dela Cruz | `student1@mentora.test` | IT101-A and IT102-A |
| Pedro Reyes | `student2@mentora.test` | used by TC-MSG-05 (enrolled in IT101-A and dropped again) |
| Maria Santos (instructor) | `instructor1@mentora.test` | teaches IT101-A |
| Joseph Garcia | `instructor2@mentora.test` | IT sub-department |
| Antonio Fernandez | `chairperson1@mentora.test` | chairs the IT sub-department |
| Daniel Reyes | `dean1@mentora.test` | dean of the General department, which has no courses |
| Roberto Cruz | `parent1@mentora.test` | follows Juan and Michael |
| Ines Administrador | `institution.admin@mentora.test` | institutional administrator |

What these prove, in the order of the design (`docs/superpowers/specs/2026-11-02-messages-design.md`):

1. Nobody reaches someone outside the rules, by search, address or button
   (TC-MSG-04, 07, 09, 10). The role matrix itself is `MessagingPolicyTest`, which
   checks every ordered pair of roles.
2. A course chat is made for every offering, kept in step with who is enrolled and
   locked by the instructor (TC-MSG-01, 02, 05).
3. A parent's conversations are about their child and invisible to the child
   (TC-MSG-06, 07, 08).
4. Blocking, reporting and the administrator's review work as a person would use
   them (TC-MSG-11), and quiet hours hold notifications without hiding messages
   (TC-MSG-12).
5. The screen works on a phone, and the system administrator has no Messages
   (TC-MSG-13, 14).
6. A person can pin, mute, archive and delete a chat for themselves, and react to,
   reply to, pin, forward, bump and delete messages; the people who manage a chat can
   turn most of those off (TC-MSG-15 to 26).

| ID | Case | Role |
|---|---|---|
| [TC-MSG-01](#tc-msg-01-a-student-posts-in-the-pinned-course-chat-and-the-instructor-sees-it) | A student posts in the pinned course chat and the instructor sees it | Student, Instructor |
| [TC-MSG-02](#tc-msg-02-the-instructor-locks-the-course-chat) | The instructor locks the course chat | Instructor, Student |
| [TC-MSG-03](#tc-msg-03-a-student-starts-a-dm-with-a-classmate-and-the-switch-takes-the-option-away) | A student starts a DM with a classmate, and the switch takes the option away | Student, Institutional administrator |
| [TC-MSG-04](#tc-msg-04-a-student-cannot-find-a-chairperson-dean-or-parent) | A student cannot find a chairperson, dean or parent | Student |
| [TC-MSG-05](#tc-msg-05-enrol-a-student-and-they-join-the-chat-drop-them-and-they-lose-it) | Enrol a student and they join the chat; drop them and they lose it | Student |
| [TC-MSG-06](#tc-msg-06-a-parent-messages-the-childs-instructor) | A parent messages the child's instructor | Parent, Instructor, Student |
| [TC-MSG-07](#tc-msg-07-a-parent-escalates-to-the-childs-chairperson) | A parent escalates to the child's chairperson | Parent, Chairperson |
| [TC-MSG-08](#tc-msg-08-a-parent-sees-the-read-only-instructor-feed) | A parent sees the read-only instructor feed | Parent |
| [TC-MSG-09](#tc-msg-09-a-chairperson-starts-a-staff-group) | A chairperson starts a staff group | Chairperson, Dean |
| [TC-MSG-10](#tc-msg-10-the-administrator-messages-a-student-and-gets-a-reply) | The administrator messages a student and gets a reply | Institutional administrator |
| [TC-MSG-11](#tc-msg-11-block-a-classmate-report-a-message-the-administrator-reviews-it) | Block a classmate, report a message, the administrator reviews it | Student, Institutional administrator |
| [TC-MSG-12](#tc-msg-12-quiet-hours-hold-a-notification) | Quiet hours hold a notification | Instructor |
| [TC-MSG-13](#tc-msg-13-on-a-phone-list-then-thread-then-back) | On a phone: list, then thread, then back | Student |
| [TC-MSG-14](#tc-msg-14-the-system-administrator-has-no-messages) | The system administrator has no Messages | System administrator |
| [TC-MSG-15](#tc-msg-15-pin-a-chat-to-the-top) | Pin a chat to the top | Student |
| [TC-MSG-16](#tc-msg-16-mute-a-chat) | Mute a chat | Student |
| [TC-MSG-17](#tc-msg-17-archive-a-chat) | Archive a chat | Student |
| [TC-MSG-18](#tc-msg-18-delete-a-chat-for-me) | Delete a chat for me | Student |
| [TC-MSG-19](#tc-msg-19-react-to-a-message) | React to a message | Student |
| [TC-MSG-20](#tc-msg-20-reply-to-a-message) | Reply to a message | Student |
| [TC-MSG-21](#tc-msg-21-pin-a-message) | Pin a message | Student, Instructor |
| [TC-MSG-22](#tc-msg-22-forward-a-message) | Forward a message | Student, Parent |
| [TC-MSG-23](#tc-msg-23-bump-a-message) | Bump a message | Student |
| [TC-MSG-24](#tc-msg-24-delete-for-everyone) | Delete for everyone | Student |
| [TC-MSG-25](#tc-msg-25-phone-and-keyboard) | Phone and keyboard | Student |
| [TC-MSG-26](#tc-msg-26-settings-and-chat-controls) | Settings and Chat controls | Institution admin, Student |

Not in the browser (manual, before turning the flag on; the checklist is in
`docs/messages.md`): a captured email for a test phrase, restoring a backup with
and without the key, and rotating the key with `messages:reencrypt`.

---

### TC-MSG-01 A student posts in the pinned course chat and the instructor sees it

- **Role:** Student, then Instructor. **Priority:** P2
- **Data:** `student5@mentora.test`, `instructor1@mentora.test`, IT101-A
- **Steps:**
  1. The student opens Messages.
  2. They open the IT101 chat and write a message.
  3. The instructor opens Messages and the same chat.
- **Expected:**
  - The course chats are the first rows of the list, labelled with the course.
  - The header says "Course chat".
  - The message appears in the student's thread at once and in the instructor's.
- **Already automated:** `tests/database/CourseChatSyncTest.php`,
  `tests/database/MessageServiceTest.php`, `tests/js/pages/messages-page.test.js`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/CourseChatSync.php`, `resources/js/pages/messages/messages.js`
- **Review:** Draft

### TC-MSG-02 The instructor locks the course chat

- **Role:** Instructor, then Student. **Priority:** P2
- **Data:** `instructor1@mentora.test`, `student5@mentora.test`, IT101-A
- **Steps:**
  1. The instructor opens the IT101 chat and chooses "Lock chat".
  2. They post an announcement.
  3. The student opens the chat.
  4. The instructor chooses "Unlock chat".
- **Expected:**
  - The button changes to "Unlock chat" and the instructor can still post.
  - The student reads the announcement, sees "Only the instructor can post here" and
    has no composer.
  - Unlocking restores the student's composer.
- **Already automated:** `tests/database/CourseChatLockTest.php`,
  `tests/feature/MessagesRoutesTest.php`, `tests/js/pages/messages-moderation.test.js`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/ConversationService.php` (`setLocked`), `MessagingPolicy::canPost`
- **Review:** Draft

### TC-MSG-03 A student starts a DM with a classmate and the switch takes the option away

- **Role:** Student, Institutional administrator. **Priority:** P2
- **Data:** `student5@mentora.test` and `student1@mentora.test` (classmates in IT101-A), `institution.admin@mentora.test`
- **Steps:**
  1. The student presses "+ New", searches "Juan", picks him and sends a message.
  2. The administrator opens Institution settings, Messages, and chooses Off.
  3. The student searches "Juan" again and opens the old thread.
  4. The administrator chooses On again.
- **Expected:**
  - The first thread starts. With the switch off the search finds no one, the old
    thread is readable and the composer is gone.
  - Messages between students and their instructors are not affected.
  - The change is in the audit log (`settings.changed`).
- **Already automated:** `tests/database/StudentDmSwitchTest.php`,
  `tests/feature/InstitutionMessagesSettingsTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/StudentMessaging.php`, spec call 7
- **Review:** Draft

### TC-MSG-04 A student cannot find a chairperson, dean or parent

- **Role:** Student. **Priority:** P2
- **Data:** `student5@mentora.test`
- **Steps:** Press "+ New" and search for "Fernandez" (chairperson), "Daniel" (dean) and "Roberto" (a parent).
- **Expected:** Each search says "No one found." and lists nobody. Ids that are
  guessed by hand answer the same 404 as an unknown id (`MessagesIdorTest`).
- **Already automated:** `tests/unit/MessagingPolicyTest.php` (every role pair),
  `tests/database/ParentMessagingTest.php`, `tests/feature/MessagesIdorTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/RecipientSearch.php`
- **Review:** Draft

### TC-MSG-05 Enrol a student and they join the chat; drop them and they lose it

- **Role:** Student. **Priority:** P2
- **Data:** `student2@mentora.test`, IT101-A. The enrolment is changed in the database and the nightly sync run, because the screens for it are covered by the ENR cases; the hooks that do it at once are covered by PHP tests.
- **Steps:**
  1. Enrol the student in IT101-A and run `messages:sync-course-chats`.
  2. The student opens Messages.
  3. Drop them and sync again; they reload Messages and open the chat's address.
- **Expected:**
  - The IT101 chat is in their list.
  - After the drop it is gone, and the address shows "This conversation is no longer available."
  - The demo data is put back as it was.
- **Already automated:** `tests/database/CourseChatSyncTest.php`,
  `tests/database/CourseChatHooksTest.php`, `tests/database/SyncCourseChatsCommandTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/CourseChatSync.php`, `CourseChatHooks.php`
- **Review:** Draft

### TC-MSG-06 A parent messages the child's instructor

- **Role:** Parent, then Instructor, then each Student. **Priority:** P2
- **Data:** `parent1@mentora.test` (follows Juan and Michael), IT101-A
- **Steps:**
  1. The parent opens a child's Introduction to Computing, then the "From the instructor" tab.
  2. They press "Message {instructor}" and send a message.
  3. The instructor opens Messages.
  4. Juan and Michael open Messages.
- **Expected:**
  - The parent lands in the thread, headed with the instructor's name and "About {child}".
  - The instructor sees a thread with the parent, labelled with the child.
  - Neither student has any row with the parent's name.
- **Already automated:** `tests/database/ParentMessagingTest.php`,
  `tests/feature/ParentInstructorFeedTest.php`, `tests/js/pages/parent-instructor-tab.test.js`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/ConversationService.php` (`startDirect`), `ParentFeedService.php`
- **Review:** Draft

### TC-MSG-07 A parent escalates to the child's chairperson

- **Role:** Parent, Chairperson. **Priority:** P2
- **Data:** `parent1@mentora.test`, `chairperson1@mentora.test`
- **Steps:**
  1. The parent searches "Fernandez", picks the chairperson and writes.
  2. The chairperson opens the thread and replies.
  3. The chairperson searches "Roberto".
- **Expected:**
  - The chairperson sees the parent's message and the reply is delivered.
  - The chairperson's search finds no parent: a chairperson never starts with a parent.
- **Already automated:** `tests/database/ParentMessagingTest.php`, `tests/unit/MessagingPolicyTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/MessagingPolicy.php` (`START`)
- **Review:** Draft

### TC-MSG-08 A parent sees the read-only instructor feed

- **Role:** Parent. **Priority:** P2
- **Data:** `parent1@mentora.test`, an instructor post and a student post in the IT101 chat
- **Steps:** The instructor and a student each post in IT101. The parent opens the "From the instructor" tab.
- **Expected:** The instructor's post is shown. The student's is not. There is nothing to type into.
- **Already automated:** `tests/database/ParentFeedServiceTest.php`, `tests/feature/ParentInstructorFeedTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/ParentFeedService.php`
- **Review:** Draft

### TC-MSG-09 A chairperson starts a staff group

- **Role:** Chairperson, Instructor, Dean. **Priority:** P2
- **Data:** `chairperson1@mentora.test`, `instructor2@mentora.test`, `dean1@mentora.test`
- **Steps:**
  1. The chairperson presses "+ New group", names it, adds Joseph Garcia, creates it and posts.
  2. The instructor opens Messages.
  3. The dean presses "+ New group" and searches "Villanueva".
- **Expected:**
  - The group opens for the chairperson; the instructor sees it with the post.
  - The dean, whose department has no courses, finds no one: a student cannot be added.
- **Already automated:** `tests/database/GroupServiceTest.php`, `tests/feature/MessagesGroupRoutesTest.php`,
  `tests/js/pages/messages-group.test.js`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/GroupService.php`
- **Review:** Draft

### TC-MSG-10 The administrator messages a student and gets a reply

- **Role:** Institutional administrator, Student. **Priority:** P2
- **Data:** `institution.admin@mentora.test`, `student5@mentora.test`
- **Steps:** The administrator starts a thread with the student. The student replies, then searches for the administrator.
- **Expected:** The student reads the message and the reply reaches the administrator. The student's search for the administrator finds no one: people reply to an administrator, they do not start with one.
- **Already automated:** `tests/database/AdminMessagingTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/MessagingPolicy.php`
- **Review:** Draft

### TC-MSG-11 Block a classmate, report a message, the administrator reviews it

- **Role:** Student, Institutional administrator. **Priority:** P2
- **Data:** Juan (`student1@mentora.test`), Michael (`student5@mentora.test`), `institution.admin@mentora.test`
- **Steps:**
  1. Juan writes to Michael.
  2. Michael reports the message (reason Spam) and blocks Juan (two clicks).
  3. Juan tries to write again.
  4. The administrator opens Messages, Reports, the report, and chooses "Mark as actioned".
- **Expected:**
  - Michael is thanked; the button becomes "Unblock Juan".
  - Juan cannot post. He can still read.
  - The administrator sees the reported message in the snapshot. The list is empty afterwards.
  - The audit log has the block, the report and the review, with no message text.
- **Already automated:** `tests/database/BlockServiceTest.php`, `tests/database/ReportServiceTest.php`,
  `tests/database/ReportReviewServiceTest.php`, `tests/feature/MessagesReportsPageTest.php`,
  `tests/database/MessagesNoTextCanaryTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/BlockService.php`, `ReportService.php`, `ReportReviewService.php`
- **Review:** Draft

### TC-MSG-12 Quiet hours hold a notification

- **Role:** Instructor (and a Student for the control). **Priority:** P2
- **Data:** `instructor1@mentora.test`, `student5@mentora.test`
- **Steps:**
  1. The instructor opens Alerts and sets quiet hours 00:00 to 23:59.
  2. A student writes to the instructor.
  3. The instructor opens Messages and their notifications.
  4. The instructor clears the hours and replies; the student looks at their notifications.
- **Expected:**
  - The conversation shows an unread count, but the instructor has no "sent you a message" notification.
  - After the hours are cleared and the instructor replies, the student has one.
- **Already automated:** `tests/database/MessageNotifierTest.php`, `tests/database/MessagePreferencesTest.php`,
  `tests/database/NotifyMessagesCommandTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/MessageNotifier.php`
- **Review:** Draft

### TC-MSG-13 On a phone: list, then thread, then back

- **Role:** Student. **Priority:** P2
- **Data:** `student5@mentora.test`, a 390 by 844 screen
- **Steps:** Open Messages, open a chat, press Back.
- **Expected:** One pane at a time: the list, then the thread (list hidden), then the list again.
- **Already automated:** `tests/js/pages/messages-page.test.js`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Views/pages/messages/index.php`
- **Review:** Draft

### TC-MSG-14 The system administrator has no Messages

- **Role:** System administrator. **Priority:** P2
- **Data:** the end-to-end administrator
- **Steps:** Sign in and look for Messages. Open `/messages`, `/messages/api/conversations` and `/messages/reports`.
- **Expected:** There is no Messages entry and each address answers 404.
- **Already automated:** `tests/feature/MessagesRoutesTest.php`, `tests/database/MessagesNavigationTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Filters/MessagesFilter.php`
- **Review:** Draft

---

## Chat and message actions (TC-MSG-15 to 26)

These twelve cases use Michael Villanueva and Juan Dela Cruz and the direct thread
they share from TC-MSG-03 and 11 (TC-MSG-15 first lifts the block TC-MSG-11 leaves).
Each case writes texts of its own. The design is
`docs/superpowers/specs/2026-11-04-messages-actions-design.md`.

### TC-MSG-15 Pin a chat to the top

- **Role:** Student. **Priority:** P2
- **Data:** `student5@mentora.test`, `student1@mentora.test`
- **Steps:**
  1. Right-click the direct chat with Juan in the list and choose Pin to top.
  2. Right-click it again and choose Unpin.
- **Expected:**
  - After the first step it is the first row, above the course chats; Pin to top is no longer offered.
  - After the second the order is back as it was.
- **Already automated:** `tests/database/ChatStateServiceTest.php`, `tests/feature/MessagesChatStateRoutesTest.php`, `tests/js/pages/messages-menu-items.test.js`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/ChatStateService.php`
- **Review:** Draft

### TC-MSG-16 Mute a chat

- **Role:** Student. **Priority:** P2
- **Data:** `student5@mentora.test`, `student1@mentora.test`
- **Steps:**
  1. Michael mutes the chat with Juan.
  2. Juan sends a message.
  3. Michael reloads and looks at the list, the Messages badge and his notifications.
  4. Michael unmutes the chat.
- **Expected:**
  - The row still shows its unread count, but the badge does not change and there is no "sent you a message" notification.
  - After unmuting, the badge counts the message.
- **Already automated:** `tests/database/ConversationServiceTest.php`, `tests/database/MessageNotifierTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/ConversationService.php`
- **Review:** Draft

### TC-MSG-17 Archive a chat

- **Role:** Student. **Priority:** P2
- **Data:** `student5@mentora.test`, `student1@mentora.test`
- **Steps:**
  1. Michael archives the chat with Juan.
  2. He opens "Archived chats (1)", then goes back.
  3. Juan sends a message.
- **Expected:**
  - The row leaves the main list and shows under Archived chats.
  - The message brings the chat back to the main list (it was not muted).
- **Already automated:** `tests/database/ConversationServiceTest.php`, `tests/js/pages/messages-page.test.js`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/ChatStateService.php`
- **Review:** Draft

### TC-MSG-18 Delete a chat for me

- **Role:** Student. **Priority:** P2
- **Data:** `student5@mentora.test`, `student1@mentora.test`
- **Steps:**
  1. Michael opens the menu of the IT101 course chat.
  2. He deletes his chat with Juan.
  3. Juan sends a new message.
- **Expected:**
  - A course chat has no "Delete chat (for me)".
  - The chat disappears for Michael only; Juan still has it.
  - The new message brings it back for Michael showing only that message, not the earlier ones.
- **Already automated:** `tests/database/ChatStateServiceTest.php`, `tests/database/MessageServiceTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/ChatStateService.php`
- **Review:** Draft

### TC-MSG-19 React to a message

- **Role:** Student. **Priority:** P2
- **Data:** `student5@mentora.test`, `student1@mentora.test`
- **Steps:**
  1. Juan writes a message. Michael right-clicks it and picks 👍.
  2. Juan, who has not reloaded, looks at the message.
  3. Michael presses the 👍 chip.
- **Expected:**
  - The chip "👍 1" shows for both, for Juan within the polling interval and without a reload.
  - Pressing it again removes it for both.
- **Already automated:** `tests/database/MessageActionsReactionsTest.php`, `tests/js/pages/messages-thread-state.test.js`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/MessageActions.php`
- **Review:** Draft

### TC-MSG-20 Reply to a message

- **Role:** Student. **Priority:** P2
- **Data:** `student5@mentora.test`, `student1@mentora.test`
- **Steps:**
  1. Michael right-clicks Juan's message and chooses Reply.
  2. He sends an answer.
  3. He clicks the quote in his answer.
- **Expected:**
  - A strip above the composer quotes the original, and goes away when he sends.
  - His message carries the quote; clicking it scrolls to and highlights the original.
- **Already automated:** `tests/database/MessageServiceTest.php`, `tests/js/pages/messages-reactions.test.js`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/MessageService.php`
- **Review:** Draft

### TC-MSG-21 Pin a message

- **Role:** Student, Instructor. **Priority:** P2
- **Data:** `student5@mentora.test`, `student1@mentora.test`, `instructor1@mentora.test`
- **Steps:**
  1. In the direct thread Michael pins Juan's message, then unpins it.
  2. In the IT101 chat a student opens the menu of a message; the instructor opens the same message's menu.
- **Expected:**
  - Both people see the pinned bar while it is pinned.
  - In the course chat the student's menu has no "Pin message"; the instructor's does.
- **Already automated:** `tests/database/MessageActionsPinTest.php`, `tests/database/MessagingPolicyTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/MessagingPolicy.php`
- **Review:** Draft

### TC-MSG-22 Forward a message

- **Role:** Student, Parent. **Priority:** P2
- **Data:** `student5@mentora.test`, `student1@mentora.test`, `parent1@mentora.test`
- **Steps:**
  1. Michael forwards Juan's message to the IT101 chat.
  2. He opens the IT101 chat.
  3. The parent opens the menu of a message in their thread with the instructor.
- **Expected:**
  - The copy arrives marked "Forwarded".
  - A parent's thread offers no Forward.
- **Already automated:** `tests/database/MessageActionsForwardTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/MessageActions.php`
- **Review:** Draft

### TC-MSG-23 Bump a message

- **Role:** Student. **Priority:** P2
- **Data:** `student5@mentora.test`, `student1@mentora.test`
- **Steps:**
  1. Michael bumps his own message.
  2. He bumps the same message again.
  3. He opens the menu of one of Juan's messages.
- **Expected:**
  - A new message with the same text appears at the bottom, marked "Bump".
  - The second bump within the hour is refused with a short note.
  - Someone else's message has no Bump.
- **Already automated:** `tests/database/MessageActionsBumpTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/MessageActions.php`
- **Review:** Draft

### TC-MSG-24 Delete for everyone

- **Role:** Student. **Priority:** P2
- **Data:** `student5@mentora.test`, `student1@mentora.test`
- **Steps:**
  1. Michael deletes one of his messages for everyone (and confirms).
  2. He opens the menu of one of Juan's messages.
  3. Twenty minutes pass (the case moves the message's time back) and he opens the menu of an older message of his.
- **Expected:**
  - Michael sees "You deleted a message", Juan sees "Michael Villanueva deleted a message".
  - The text is gone from the page and from what the server sends.
  - Someone else's message has no "Delete for everyone", and neither has one older than 15 minutes.
- **Already automated:** `tests/database/MessageActionsDeleteEveryoneTest.php`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/MessageActions.php`
- **Review:** Draft

### TC-MSG-25 Phone and keyboard

- **Role:** Student. **Priority:** P2
- **Data:** `student5@mentora.test`, a 390 by 844 screen and a desktop one
- **Steps:**
  1. On the phone, press and hold a message.
  2. On the desktop, focus a message and press Shift+F10, then Escape, then Shift+F10, ArrowDown and Enter.
  3. Right-click the composer.
- **Expected:**
  - The menu opens on a long press and on Shift+F10; Escape closes it and focus returns to the message.
  - The composer keeps the browser's own menu.
- **Already automated:** `tests/js/pages/messages-menu.test.js`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `resources/js/pages/messages/gestures.js`
- **Review:** Draft

### TC-MSG-26 Settings and Chat controls

- **Role:** Institution admin, Student. **Priority:** P2
- **Data:** `institution.admin@mentora.test`, `student5@mentora.test`, IT101-A
- **Steps:**
  1. The student opens Settings.
  2. The administrator opens Settings, Chat controls, picks the IT101 chat and turns off Forwarding, Reactions and Delete for everyone.
  3. The student reloads and opens a message's menu, and tries to react with a request the screen no longer offers.
- **Expected:**
  - The student's Settings has no tab bar and no Chat controls.
  - The student's menu has no Forward, no reaction row and no "Delete for everyone", but still "Delete for me".
  - The server refuses the stale reaction with 403.
  - The case turns the switches back on at the end.
- **Already automated:** `tests/database/ChatControlsTest.php`, `tests/js/pages/messages-settings.test.js`
- **Playwright:** `tests/e2e/messages.spec.js`
- **Basis:** `app/Services/Messaging/ChatControls.php`
- **Review:** Draft
