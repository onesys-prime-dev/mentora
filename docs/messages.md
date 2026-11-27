# Messages

Messages is where people at a school talk to each other inside Mentora: a group
chat for every course, private messages between anyone in the same institution
(with safety rules), and groups anyone can start. It replaced the placeholder "Inbox" tile. This page
is for the people who run it and the people who use it. The design it follows is
`superpowers/specs/2026-11-02-messages-design.md`, as amended by the institution-wide track
(`feature-development/messages-institution-wide/requirements.md`).

## What people can do

| | |
|---|---|
| **Course chats** | One per course offering, made and kept in step automatically: the instructors and every student who is enrolled or has completed the course. A student who drops or withdraws loses it at once. The instructor can **lock** a chat so only they can post. When the course is closed or archived the chat is read-only; nothing is deleted. Students see messages from the time they joined. |
| **Direct messages** | Between two people of the same institution. Anyone can message anyone there, apart from the safety rules below (enforced in one place, `MessagingPolicy`, and tested for every pair of roles). Choosing a person opens a **draft**: nothing is stored until the first message is sent. If a thread with that person already exists, it opens instead. |
| **Groups** | Anyone except a parent-only account starts a group of up to 50 people from **+ New**: picking two or more people makes a group. The name is optional (up to 80 characters); left blank, each member sees the group named after the others. Every member must be someone the creator could message. The creator **owns** it: the owner adds, removes, renames and closes; any member can leave; an institutional administrator can do what the owner can. When the owner leaves or is deactivated, ownership passes to the longest-standing staff member, otherwise the longest-standing member. A closed group, or one down to a single member, stays readable and is read-only. Each change shows as a grey line in the group and is audited. A minor can be added only if every member could message them, and nobody can join a group with a minor unless they could message that minor. Older staff groups are owned by whoever made them. |
| **Parents** | A parent's thread is with a staff member and is about one of their linked children; it is labelled with the child's name and the child never sees it. Either side can start it. A parent also has a read-only **From the instructor** tab in each of the child's courses (the instructors' posts in the course chat, nothing from students). If the parent's link to the child ends, the thread stays readable and nobody can post in it. A parent with no linked child is told they cannot start a conversation yet. |
| **First contact** | The first message between two people who have not talked before (no thread, no shared course, no parent and child's-staff link) shows the recipient a quiet line, "First message from {name} · {role} · you haven't talked before", with **Block** and **Report**, until they reply or block. A minor always gets the line. The sender sees "First message to {name}". |
| **Chat actions** | Right-click a chat in the list (long-press on a phone, or Shift+F10) to **pin** it to the top (at most 5 chats; course chats are always at the top), **mark it unread**, **mute** it, **archive** it or **delete it for me**. A muted chat keeps its unread count on its row but is left out of the Messages badge and sends no notification or email. An archived chat moves to "Archived chats" and comes back when a new message arrives, unless it is muted. Deleting for me hides the chat and its history from that person only; a new message brings it back showing only what is newer. A course chat can be neither unpinned nor deleted. |
| **Message actions** | Right-click a message (or long-press) to **react** with one of 24 emoji (one reaction per person per message, at most 60 reactions a minute), **reply** with a quote, **copy**, **forward** it to up to 5 other chats, **pin** it (instructors, chairpersons and deans in a chat; either person in a direct thread; at most 3 pinned messages per chat), **bump** your own message (a new copy marked "Bump" at the bottom, once an hour per original), **delete it for me**, **delete it for everyone** (your own message, within 15 minutes) or report it. A message deleted for everyone has its text erased on the server and shows as "You deleted a message" / "{Name} deleted a message"; a message that was reported keeps its report snapshot. A moderator (the instructor in their course chat, a group's owner, an institutional administrator) sees Remove instead of Report on other people's messages. Parent threads cannot be forwarded out of. |
| **Blocks and reports** | Anyone can block anyone in their institution except an institutional administrator; a student cannot block an instructor of a course they are enrolled in, nor a parent their child's instructor ("You can't block your instructor while you share a course."). While an instructor and a student share a course, a block between them is **paused** and comes back by itself when the link ends; the block list says so. A block by an institutional administrator never binds. A block stops new threads and posts in the direct thread; it never removes anyone from a course chat or group. Any message from another person can be reported; the institution's administrator reviews it. |

### Who can start a conversation with whom

"Start" means send the first message of a new thread; once it exists both sides can reply (unless a rule below makes it read-only). Checked in this order, the first refusal wins (`MessagingPolicy::canStart`, requirements FR1 in `feature-development/messages-institution-wide/requirements.md`):

1. **Never:** yourself; someone in another institution; a deactivated account; the system administrator; while impersonating; when your institution is read-only; while you are restricted or paused (below).
2. **Capacity:** every role a person holds counts at once; nobody switches roles for Messages. A thread **about a child** is a parent thread: one side must be a parent linked to that child, the other must hold a staff role (instructor, chairperson, dean or institutional administrator). Any other thread is between two people acting as **staff** (if they hold any staff role) or **student**. A parent-only account can start only parent threads.
3. **Pairs:**
   - parent with student, and parent with parent: never.
   - student with student: follows the institution's **Students messaging each other** setting: Off (never), Classmates only (both in the same active course), Whole institution.
   - **a minor** (a student the institution has marked as one; this applies even if they also hold a staff role): only their instructor, the chairperson of their home program's department, that college's dean, a classmate, or an institutional administrator.
   - everyone else in the institution: allowed, including anyone to an institutional administrator.
4. **Blocks:** refused if the recipient has an active block on you.

A thread that stops qualifying (the student setting narrows, a classmate link ends, a student is marked minor, the other person is deactivated) stays readable and becomes read-only. For a deactivated person the composer says "This account is deactivated"; otherwise it gives no reason. Nothing reveals someone's block, minor mark, restriction or pause: a refused search shows nobody, and a refused start looks like any other "not available".

The system administrator has no access to Messages at all. People never message across institutions (someone in several institutions uses their first).

**Finding people** (+ New): search by name, or by email where you may see that person's email (a staff member's for anyone; a student's for staff; never a parent's). Only people you may start with are listed, 20 at a time with **Show more**. A person who is both staff and a parent can pick "about a child" or not.

### Restrictions and the automatic pause

- **Restrict…** (Messages, Reports, on a reported person's card): the institution's administrator chooses **No new conversations** or **No sending**, for a set time or until lifted. The person sees a notice in Messages naming the limit and when it ends. No sending still lets an instructor post in their own course chats. One restriction per person at a time; **Lift** ends it early; it ends by itself when the time is up.
- **Pause:** when three different people who share no course with a sender report that sender's first-contact messages within 24 hours, the sender cannot start new conversations or groups (or add group members) for up to 72 hours. It ends early when the administrator has reviewed those reports or restricts the person. The administrator is notified when it starts. Institutional administrators are never paused, and a class cannot pause its own instructor this way.
- An administrator can **remove** a reported direct message: it is blanked for both people as "Message removed by an administrator", and audited.
- Reports are grouped by reported person, with Dismiss all, Remove reported messages and Restrict….

## What it protects, and what it does not

- **Message text is encrypted in the database** with its own key (`messages.encryptionKey`),
  separate from the application's general key. Someone who browses the database, finds a
  stolen backup, or is an administrator looking at tables sees ciphertext. Each message is
  also bound to its conversation and sender, so a ciphertext moved to another row cannot be read.
- **It is not end-to-end encryption.** The server has to read messages so that an instructor
  can moderate a course chat, a parent can see the instructors' posts, and the school can act
  on a report. The running application can therefore read them; the encryption protects the
  stored data, not the conversation from the application.
- **Nobody browses messages.** There is no screen that lists message text. The institution's
  administrator sees text only in a **report**: the reported message and up to five before it,
  kept when it was reported. Messages can be removed by the administrator from the report
  (a direct message then reads "Message removed by an administrator" for both people), by the
  instructor in their own course chat, and by the owner in a group. A removed message becomes
  "Message removed" for everyone.
- **Notifications and emails never carry the text.** They say who wrote and where, with a link.
- **Audit entries and logs never carry the text.** They record who did what, to which id.
- **Every conversation id is checked.** If you are not in a conversation it does not exist
  for you: the answer is the same "not found" as for an id that was never used.

## Turning it on

Messages ships **off**. Do this once per environment, in order.

1. **Make a key.** At least 32 random bytes, different from `encryption.key`:

   ```bash
   php8.5 -r "echo 'hex2bin:' . bin2hex(random_bytes(32)), PHP_EOL;"
   ```

   Put it in the site's `.env` as `messages.encryptionKey = hex2bin:...` (the `env` template
   shows the line). **Keep a copy somewhere other than the server and its backups**: the
   messages cannot be read without it. See "Backups" below.
2. **Migrate** (a deploy does this): `php8.5 spark migrate --all`.
3. **Make the course chats** for the offerings that already exist:
   `php8.5 spark messages:sync-course-chats --dry-run`, then without `--dry-run`.
   It is safe to repeat and the scheduler runs it every night.
4. **Check the jobs.** The deploy installs `messages:sync-course-chats` (daily) and
   `messages:notify` (every 15 minutes) with the others; `/admin/monitoring` should show
   them reporting.
5. **Check the health page.** "Messages encryption" must not be down. The flag cannot be
   switched on while the key is missing or too short.
6. **Switch the `messages` feature flag on** for the institution (Admin, Feature flags) with a
   note saying why. Nothing is visible to anyone before this.
7. **Run the go-live checks** below on a copy of production before the first real use.

### Go-live checks (on a copy of production)

- `messages:sync-course-chats --dry-run`, then for real; look at a few chats.
- In phpMyAdmin or the SQL client, open `messages.body_enc`: it must be unreadable.
- Send a message containing a made-up phrase, then search the logs
  (`writable/logs`), a captured notification email and the audit log for that phrase:
  it must be in none of them.
- Restore a backup to a scratch database **with** the key (messages open) and **without**
  it (they show "Message unavailable").
- Rotate the key (below) and confirm old conversations still open.

Because the institution's administrator reviews every report, a report about the administrator's own
messages would be reviewed by the same person. Mentora does not route those anywhere else yet; if that matters
to your institution, have a second administrator account review reports about the first.

## Settings an institution's administrator controls

- **Students messaging each other** (Institution settings, Messages): **Off**, **Classmates only**
  (both in the same active course) or **Whole institution** (the default; an institution that had
  the old switch off reads Off). Narrowing it makes student-to-student threads that no longer
  qualify read-only; nothing is deleted. Messages between students and staff are unaffected.
- **Default quiet hours** (Institution settings, Messages): used by everyone who has not chosen
  their own. Both changes are in the audit log.
- **Minor mark** (People, a student's Actions, **Mark as minor** / **Remove minor mark**, or the
  `minor` column of the students CSV): see "Who can start" above. Shown as a "Minor" badge to
  administrators and staff only; audited.
- **Chat controls** (Institution settings, Messages): a picker of every course chat and group, then that chat's switches (Reactions, Replies, Forwarding, Bump, Delete for everyone, and the lock for course chats).
- **Reports** (Messages, Reports): reports grouped by reported person (Open, Dismissed, Actioned),
  the snapshot, and the decision (dismiss, mark as actioned, remove the message, restrict the
  person). Restrictions are also listed on the People screen.

## What each person can set

- **Email alerts** for new messages (everyone): Off, **Everyone** (the default) or **People I've talked to**; a parent also chooses per child. At most one email per conversation per hour. These choices (and quiet hours) moved from the old Messages preferences page to **Settings, Notifications** (`/settings#notifications`); `GET messages/preferences` redirects there.
- **Blocked people** (everyone): the list of people you have blocked, with Unblock, in Settings.
- **Chat controls** (the institution's administrator only, in **Institution settings, Messages**; not in the Messages page): a picker of every course chat and group of the institution (the administrator does not need to be in the chat), then that chat's switches. It has switches for **Reactions**, **Replies**, **Forwarding**, **Bump** and **Delete for everyone**, and for course chats the lock ("Only the instructor can post"). Turning one off hides it from members' menus and the server refuses it. Two things are never switches: **Delete for me** is always available, and a manager's **Remove** is always available. Turning Forwarding off does not stop someone copying the text by hand. Everyone else cannot change them; the Messages page's Settings is only "My alerts" and blocked people. Instructors keep the **Lock chat** button on their own course chat. Who manages a chat is in `roles-and-permissions.md`.
- **Quiet hours** (everyone): **School default** (the institution's times; what anyone who never
  chose gets), **My own** times, or **None**. During them nothing is notified or emailed; the
  notification is made afterwards, once. Messages still arrive and the unread count still shows
  them. Times are in the platform's time zone.

## Limits

Per person: 30 messages a minute, 20 reports a day, and new conversations (direct or group,
counted when the first message is sent): students and parents 10 an hour and 25 a day; anyone
holding a staff role 20 an hour and 60 a day; institutional administrators are exempt. Over a limit the screen says so in a sentence (HTTP 429) and nothing is written.
Messages are plain text, 1 to 4000 characters. There are no attachments, editing,
typing indicators or search inside messages in this version. Pins: 5 chats per person, 3
messages per chat. Forwarding: 5 chats at a time. Delete for everyone: 15 minutes. Bump: once
an hour per message. Reactions: 60 a minute.

## Scheduled jobs

| Command | Schedule | What it does |
|---|---|---|
| `messages:sync-course-chats [--dry-run]` | daily, 03:45 | Creates missing course chats and repairs who is in them. |
| `messages:notify [--dry-run]` | every 15 minutes | Notifies people of messages a send did not tell them about (big chats, and what quiet hours held). |

Both report to the jobs check (`monitoring.md`). A send tells the other members at once only in
conversations of 30 or fewer people; bigger chats are told by the sweep.

## Backups and the key

Backups hold **ciphertext only**. Restoring messages needs `messages.encryptionKey`, and the key
must be stored **separately** from the backup (a backup that sits next to its key protects
nothing). Without the key a restored database works, but every message shows "Message
unavailable". `backup.md` has the restore steps.

## Rotating the key

1. Make a new key (as above). In `.env` set `messages.encryptionKey` to the **new** key and
   `messages.previousKeys` to the old one (comma-separated if there are several).
2. `php8.5 spark messages:reencrypt --dry-run`, then `php8.5 spark messages:reencrypt`.
   It moves message bodies, report snapshots and report notes onto the new key, in batches. A
   value nothing can open is counted and its row is named (never its content); the command
   then exits with an error.
3. When it reports 0 unreadable, remove the old key from `messages.previousKeys`.

`/admin/monitoring` shows "Messages encryption" as degraded while a previous key in `.env` is
malformed (messages encrypted with it cannot be read) and as down if Messages is on with no
usable key (sending is refused).

## When something looks wrong

| You see | Likely cause | Do this |
|---|---|---|
| "Messages encryption" is down | The flag is on and `messages.encryptionKey` is missing or too short | Set the key in `.env`; check with the health page. Sending is refused until then. |
| Messages show "Message unavailable" | A restore without the key, or a rotation without `previousKeys` | Restore the right key; run `messages:reencrypt` and read the row ids it prints. |
| A student is not in their course chat | The sync has not run since they were enrolled | `php8.5 spark messages:sync-course-chats` (the enrolment screens do it at once; the nightly run repairs the rest). |
| Nobody gets notifications | `messages:notify` is not scheduled or is failing | `/admin/monitoring` shows it late; check the crontab block (`server-setup.md`). |
| Someone says "I can't message X" | The rules above refuse it: X is a minor they have no link to, X blocked them, the student setting is Off or Classmates only, or they are restricted or paused | By design; nothing to fix. Check Reports and People for a restriction or pause. |
| A conversation became read-only | The other person was deactivated, the student setting was narrowed, a classmate link ended, or a student was marked minor | By design; the history stays readable. |

## For developers

- Policy lives only in `app/Services/Messaging/MessagingPolicy.php` (`canStart` is FR1 in order;
  `canPost`/`canReact` add the read-only cases); facts come from `MessagingContextResolver`
  (`forStart`, `forPost`, `parties()`). Controllers and services never decide permissions.
- A person's roles come from `Party` (every role held, read from `auth_groups_users`), never from
  the session's active role. `tests/unit/NoActiveRoleInMessagesTest.php` fails if `active_role`,
  `ActiveRoleService`, `usableGroups(` or `preferredRole(` appear in Messages code. See
  `feature-development/messages-institution-wide/single-identity-coordination.md`.
- `RecipientSearch::reachable()` is an SQL prefilter that restates part of FR1 so search pages
  stay fast; a rule change must update it too (it can only hide people, never add them).
- Tests: `tests/unit/MessagingPolicyTest.php` (every role pair and state, AC1), `PartyTest`,
  `tests/database/Message*Test.php`, `Report*`, `Block*`, `Group*`, `CourseChat*`, `Parent*`,
  `AdminMessagingTest`, `ConversationDraftTest`, `FirstContactTest`, `Restriction*`,
  `AutomaticPauseTest`, `MinorFlagTest`, `ThreadReadOnlyTest`, `RecipientSearchTest`,
  `MessagingDataIntegrityTest` (counts across the 2026-12-03 migrations and their rollback),
  `MessagesNoTextCanaryTest` (nothing leaks), `tests/feature/Messages*Test.php`
  (including the id-guessing sweep), `tests/js/pages/messages-*.test.js`, and
  `tests/e2e/messages.spec.js` (`docs/test-cases/p2-messages.md`).
