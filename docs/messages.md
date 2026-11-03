# Messages

Messages is where people at a school talk to each other inside Mentora: a group
chat for every course, private messages between people who have a reason to
talk, and small staff groups. It replaced the placeholder "Inbox" tile. This page
is for the people who run it and the people who use it. The design it follows is
`superpowers/specs/2026-11-02-messages-design.md`.

## What people can do

| | |
|---|---|
| **Course chats** | One per course offering, made and kept in step automatically: the instructors and every student who is enrolled or has completed the course. A student who drops or withdraws loses it at once. The instructor can **lock** a chat so only they can post. When the course is closed or archived the chat is read-only; nothing is deleted. Students see messages from the time they joined. |
| **Direct messages** | Between two people. Nobody can message a stranger: the table below is enforced in one place (`MessagingPolicy`) and tested for every pair of roles. Starting a thread that exists opens it. |
| **Staff groups** | A chairperson (instructors of their sub-department) or a dean (instructors and chairpersons of their department) starts a group of 1 to 30 people in their scope. Membership is fixed when the group is made. |
| **Parents** | A parent's thread with an instructor or chairperson is about one of their children and is labelled with the child's name; the child never sees it. A parent also has a read-only **From the instructor** tab in each of the child's courses (the instructors' posts in the course chat, nothing from students). A chairperson can reply to a parent but cannot start with one. If the parent's link to the child ends, the thread stays readable and nobody can post in it. |
| **Blocks and reports** | A person can block someone in a direct thread (that person can no longer start with them or post to them; instructors and administrators cannot be blocked). Any message from another person can be reported; the institution's administrator reviews it. |

### Who can start a conversation with whom

"Start" means open a new thread; once it exists both sides can reply.
"Shared course" means both are active members of the same course that is not closed.

| From ↓ / To → | Student | Instructor | Chairperson | Dean | Parent | Inst. admin |
|---|---|---|---|---|---|---|
| **Student** | classmates in a shared course (if the institution's student switch is on) | instructors of a shared course | no | no | no | no |
| **Instructor** | students of the same course | same department | own department's chairpersons | own department's dean | parents of students in the course | no |
| **Chairperson** | students of the sub-department | instructors of the sub-department | same department | own department's dean | **reply only** | no |
| **Dean** | students of the department | instructors of the department | chairpersons of the department | other deans | no | no |
| **Parent** | no | instructors of a linked child's courses | the linked child's chairperson | no | no | no |
| **Inst. admin** | yes | yes | yes | yes | yes | yes |

The system administrator has no access to Messages at all. People never message across
institutions. A person who holds two roles messages under the one they are using.

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
  kept when it was reported. A reported direct message can be dismissed or marked as actioned
  but never removed; messages in a course chat or staff group can be removed by the
  administrator from the report, by the instructor in their own course chat, and by the
  creator in a staff group. A removed message becomes "Message removed" for everyone.
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

- **Students messaging each other** (Institution settings, Messages): on by default. Off:
  students cannot start new messages with each other; existing student conversations stay
  readable but nobody can add to them. Messages with instructors are unaffected. The
  change is in the audit log.
- **Reports** (Messages, Reports): open reports of the institution, the snapshot, and the
  decision (dismiss, mark as actioned, or remove the message where that is allowed).

## What each person can set

- **Email alerts** for new messages (everyone). At most one email per conversation per hour.
- **Quiet hours** (instructors, chairpersons and deans): during them nothing is notified or
  emailed; the notification is made afterwards, once. Messages still arrive and the unread
  count still shows them.

## Limits

Per person: 30 messages a minute, 10 new conversations (direct or group) an hour, 20 reports
a day. Over a limit the screen says so in a sentence (HTTP 429) and nothing is written.
Messages are plain text, 1 to 4000 characters. There are no attachments, editing,
reactions, typing indicators or search inside messages in this version.

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
| Someone says "I can't message X" | They are not allowed to by the table above, X blocked them, or the institution has student messages off | This is by design; nothing to fix. |

## For developers

- Policy lives only in `app/Services/Messaging/MessagingPolicy.php`; facts come from
  `MessagingContextResolver`. Controllers and services never decide permissions.
- Tests: `tests/unit/MessagingPolicyTest.php` (every role pair), `tests/database/Message*Test.php`,
  `Report*`, `Block*`, `Group*`, `CourseChat*`, `Parent*`, `AdminMessagingTest`,
  `MessagesNoTextCanaryTest` (nothing leaks), `tests/feature/Messages*Test.php`
  (including the id-guessing sweep), `tests/js/pages/messages-*.test.js`, and
  `tests/e2e/messages.spec.js` (`docs/test-cases/p2-messages.md`).
