# P3 test cases: group activities

Manual cases for the group activities feature. Read [README.md](README.md) first.
The feature is described in [../group-activities.md](../group-activities.md). All cases
are `Draft` (see the README): the expected results come from the code and the build
reports, and the wording on screen is to be confirmed in a browser.

Cases `TC-GRP-01` to `TC-GRP-03` are already taken by `tests/e2e/groups-tab.spec.js`
(sets, drag, 360 px, generate), so the manual cases here start at **TC-GRP-11**.
The Playwright files also use `TC-GA`, `TC-GAS`, `TC-GAL`, `TC-GG`, `TC-GRO` and
`TC-LIG` for their own steps.

## Checks never run during development

The e2e database and a browser were not available while this feature was built. These
have **never been run** and are the first things to do before release:

**Playwright specs** (`tests/e2e/`, written but never executed; expect selector and
fixture fixes on the first run):
- `groups-tab.spec.js`
- `group-activity.spec.js`
- `group-activity-student.spec.js`
- `group-activity-leader.spec.js` (needs `group-activity-student.spec.js` TC-GAS-02 to run first)
- `group-grading.spec.js`
- `group-readonly.spec.js`
- `learning-item-groups.spec.js`

**Browser checks** (nobody has looked at any group screen in a browser):
- Widths 360, 768 and 1440 px on the Groups tab, the activity editor card, the student
  card, the group view of Grade Work, the dean view and the parent tab.
- Dark mode on all of the same screens.
- Keyboard-only use (TC-GRP-45) and the axe scan (no serious or critical findings).
- Live-region announcements with a screen reader (a move is announced twice today:
  once by the toast and once by the live region).

The PHP and Vitest suites did pass at each step (last full run: PHP 10139, Vitest 1243).

## Data

Demo data from the README. Course **IT101-A**, instructor `instructor1@mentora.test`,
students `student1` to `student5`, parent `parent1@mentora.test` (children student1 and
student5). The chairperson and dean accounts are in `p2-chairperson.md` and `p2-dean.md`
(a dean of the college that holds IT101). "Lab 1" is the assignment used below. Unless
a case says otherwise, put the course back as you found it (switch Group activity off).

| ID | Case | Role |
|---|---|---|
| [TC-GRP-11](#tc-grp-11) | Create a group set | Instructor |
| [TC-GRP-12](#tc-grp-12) | Set limits and validation | Instructor |
| [TC-GRP-13](#tc-grp-13) | Place students by hand with the picker | Instructor |
| [TC-GRP-14](#tc-grp-14) | Auto-generate: preview, save, replace | Instructor |
| [TC-GRP-15](#tc-grp-15) | Attach a set to an assignment | Instructor |
| [TC-GRP-16](#tc-grp-16) | Later set edits do not change the activity | Instructor |
| [TC-GRP-17](#tc-grp-17) | A quiz has no group section | Instructor |
| [TC-GRP-18](#tc-grp-18) | Self-signup: join, switch, leave, window | Student |
| [TC-GRP-19](#tc-grp-19) | Self-signup race for the last seat | Student |
| [TC-GRP-20](#tc-grp-20) | Proposed mode: propose, invite, accept, approve | Student, instructor |
| [TC-GRP-21](#tc-grp-21) | Decline a proposal | Instructor |
| [TC-GRP-22](#tc-grp-22) | Leader vote and rename | Student |
| [TC-GRP-23](#tc-grp-23) | Instructor sets the leader | Instructor |
| [TC-GRP-24](#tc-grp-24) | Shared submission | Student |
| [TC-GRP-25](#tc-grp-25) | Leader-only submission | Student |
| [TC-GRP-26](#tc-grp-26) | Per-member submission | Student |
| [TC-GRP-27](#tc-grp-27) | Ungrouped policy: must join, and may work alone | Student, instructor |
| [TC-GRP-28](#tc-grp-28) | First submission freezes the group | Student |
| [TC-GRP-29](#tc-grp-29) | Unlock a frozen group | Instructor |
| [TC-GRP-30](#tc-grp-30) | Extend a group's deadline | Instructor, student |
| [TC-GRP-31](#tc-grp-31) | Grade collectively | Instructor |
| [TC-GRP-32](#tc-grp-32) | Collective with an adjustment | Instructor, student |
| [TC-GRP-33](#tc-grp-33) | Group and individual rubric criteria | Instructor |
| [TC-GRP-34](#tc-grp-34) | Individual grading and the average | Instructor |
| [TC-GRP-35](#tc-grp-35) | A student dropped before grading | Instructor |
| [TC-GRP-36](#tc-grp-36) | A student dropped after grading: keep or excuse | Instructor |
| [TC-GRP-37](#tc-grp-37) | A late enrollee: grade separately or inherit | Instructor |
| [TC-GRP-38](#tc-grp-38) | The gradebook after group grading | Instructor, student |
| [TC-GRP-39](#tc-grp-39) | Student, parent and dean views | Student, parent, dean |
| [TC-GRP-40](#tc-grp-40) | Learning item groups and completion modes | Instructor, student |
| [TC-GRP-41](#tc-grp-41) | Notifications in the app and by email | All |
| [TC-GRP-42](#tc-grp-42) | Copy an offering | Institutional administrator |
| [TC-GRP-43](#tc-grp-43) | A finalized offering refuses group changes | Instructor |
| [TC-GRP-44](#tc-grp-44) | Phone layout at 360 px | Instructor, student |
| [TC-GRP-45](#tc-grp-45) | Keyboard-only walk-through | Student, instructor |
| [TC-GRP-46](#tc-grp-46) | Dark mode | All |
| [TC-GRP-47](#tc-grp-47) | Chairperson and wrong-role access | Chairperson, student, administrator |

---

### TC-GRP-11
**Create a group set**
- **Role:** Instructor. **Priority:** P1
- **Preconditions:** signed in as `instructor1`, IT101-A open, no group sets.
- **Steps:**
  1. Open the Groups tab. Note the "No group sets yet" empty state.
  2. Choose to create a set. Name it "Lab teams", mode "Self-signup", smallest 2, largest 4, set open and close times.
  3. Save.
- **Expected:**
  - The set appears in the table with its mode badge, 0 groups and 0 students placed. The usage line reads "1 of 20 sets used".
  - A toast confirms the save and the dialog closes.
  - The open and close fields show only for Self-signup and Proposed modes.
  - Open the set and add three groups. Each card shows "0 / 4", and a group under the smallest size shows "below minimum".

### TC-GRP-12
**Set limits and validation**
- **Role:** Instructor. **Priority:** P2
- **Steps:**
  1. Try to save a set with an empty name.
  2. Try a largest size smaller than the smallest.
  3. Try a close time before the open time.
  4. Add groups until there are 50, then try a 51st.
- **Expected:**
  - Each problem is shown beside its field in the dialog, which stays open; nothing is saved.
  - The 51st group is refused with a message. The limits of 30 members per group and 20 sets per course are refused the same way.

### TC-GRP-13
**Place students by hand with the picker**
- **Role:** Instructor. **Priority:** P1
- **Preconditions:** a set with three groups and students in the Ungrouped pool.
- **Steps:**
  1. In the set, press "Move..." on a student, pick "Team 1", confirm.
  2. Move another student to Team 2, then back to "Ungrouped".
  3. Drag a student chip onto a group card.
  4. Try to move a fifth student into a full group of 4, and to put one student in two groups.
- **Expected:**
  - Each move shows a toast and the counts update. A polite message announces the move.
  - Ungrouped is how a member leaves a group.
  - A full group refuses with a message, and a student is never in two groups of the set.
  - The picker alone is enough to do everything (no drag needed).

### TC-GRP-14
**Auto-generate: preview, save, replace**
- **Role:** Instructor. **Priority:** P1
- **Preconditions:** an empty set, five students enrolled.
- **Steps:**
  1. In the set press "Generate groups". Choose "by group size", 2.
  2. Add one keep-together pair and one keep-apart pair.
  3. Preview. Read the note. Cancel once and confirm nothing was saved.
  4. Preview again and save.
  5. Generate again over the groups that now exist, and save.
- **Expected:**
  - The preview says "Nothing has been saved yet". Cancelling leaves the set unchanged.
  - Pairs are respected. The leftover student is placed into a group that still has room, otherwise left ungrouped.
  - Saving over existing groups asks for a confirmation first.
  - The placed students get a notice (TC-GRP-41).

### TC-GRP-15
**Attach a set to an assignment**
- **Role:** Instructor. **Priority:** P1
- **Preconditions:** the set "Lab teams" with groups and members, Lab 1 as an assignment.
- **Steps:**
  1. Open Lab 1 in the editor. Switch "Group activity" on.
  2. In the dialog pick "Lab teams" and attach.
  3. Look at the card: submission style, grading mode, ungrouped policy, formation mode and window, the board of groups.
  4. Change a setting, reload the page.
- **Expected:**
  - The card says "Copied from Lab teams. Later edits to the set will not change this activity".
  - The groups and their members are copied (only students assigned to Lab 1). The settings persist after reload.
  - Switching it off asks first, then removes the activity's groups. "Start from scratch" is also offered, and "Re-copy" and "Copy from another activity" each ask first.

### TC-GRP-16
**Later set edits do not change the activity**
- **Role:** Instructor. **Priority:** P2
- **Steps:** Attach a set (TC-GRP-15). On the Groups tab rename a group of the set and move a student. Open Lab 1.
- **Expected:** Lab 1's groups and members are as they were at attach time.

### TC-GRP-17
**A quiz has no group section**
- **Role:** Instructor. **Priority:** P2
- **Steps:** Open any quiz in the editor.
- **Expected:** No Group activity card on the quiz. The server also refuses a group attach for a quiz.

### TC-GRP-18
**Self-signup: join, switch, leave, window**
- **Role:** Student (`student1`). **Priority:** P1
- **Preconditions:** Lab 1 is a group activity in Self-signup mode with the window open, policy "must join a group", two groups with room, student1 ungrouped.
- **Steps:**
  1. Open Lab 1. Read the "Your group" card and the submit area.
  2. Join Team 1. Switch to Team 2. Leave (read the confirm text), then join again.
  3. As the instructor close the window (close time in the past), then as student1 try to join or switch.
  4. As the instructor set the window to open in the future and check an ungrouped student.
- **Expected:**
  - Before joining: "You're not in a group yet", the open groups with Join or Full, and the submit form shown disabled with a link to the group card.
  - Join, switch and leave each show a toast, a live announcement and a refreshed card.
  - After the window closes, or before it opens, joining is refused with a message. Leaving is still allowed until the first submission.
  - Other groups show only when the instructor turned that on.

### TC-GRP-19
**Self-signup race for the last seat**
- **Role:** Two students. **Priority:** P1
- **Preconditions:** a group with largest size 2 and one seat left. student1 and student2 both ungrouped, in two browsers.
- **Steps:** Press Join in both browsers as close together as possible. Repeat a few times, resetting the group.
- **Expected:** Exactly one student gets the seat. The other sees "Group is full" and stays ungrouped. The group never exceeds its largest size.

### TC-GRP-20
**Proposed mode: propose, invite, accept, approve**
- **Role:** Students `student1` and `student2`, then the instructor. **Priority:** P1
- **Preconditions:** Lab 1 in "Students propose groups" mode, window open, smallest size 2.
- **Steps:**
  1. As student1 open the card, "Propose a new group", name it "Night Owls", invite student2, send.
  2. As student1 see the waiting state ("Waiting for your instructor to approve this group", "Invited: ...").
  3. As student2 see the invitation and accept it.
  4. As the instructor open Lab 1's editor. In the proposals queue find Night Owls with the minimum-met badge. Approve it.
  5. Check that the instructor was told when the group reached the minimum (TC-GRP-41).
- **Expected:**
  - The group is not a normal group until approved. Approve is offered only once the group has the smallest size.
  - After approval both students see it as their group. An invitee already in a group, or not in the activity, cannot be invited.
  - A declined invitation never puts the student in the group.

### TC-GRP-21
**Decline a proposal**
- **Role:** Instructor. **Priority:** P2
- **Steps:** Create a proposal. As the instructor press Decline with no reason, then with a reason.
- **Expected:** A reason is required. A declined group is hidden from the board and does not count toward the 50 limit. The proposer can propose again.

### TC-GRP-22
**Leader vote and rename**
- **Role:** Students in a group of three (`student1`, `student2`, `student3`). **Priority:** P1
- **Preconditions:** Lab 1 in leader-only style, a group of three with no leader.
- **Steps:**
  1. As student1 nominate student1.
  2. As student2 press Agree. Look at the card: "n of N agreed".
  3. Check the card as student3, who has not voted.
  4. Try to rename the group as student3.
  5. As student1 (leader) rename the group.
- **Expected:**
  - With 3 members, 2 agreeing is more than half, so the leader is final and the card says "Leader: student1". 1 of 3 is not.
  - Once the leader is final, a non-leader cannot rename or vote unless the instructor enabled those toggles. The leader can rename.
  - A vote by someone who left the group does not count.
  - The group's other members are told when a vote starts and when the leader is final.

### TC-GRP-23
**Instructor sets the leader**
- **Role:** Instructor. **Priority:** P2
- **Steps:** In Lab 1's editor press "Set leader" on a group and choose a member. Then make that leader drop the course.
- **Expected:** The badge shows the leader at once, overriding votes. When a final leader drops, the member who joined next becomes the leader with no vote.

### TC-GRP-24
**Shared submission**
- **Role:** Students in the same group. **Priority:** P1
- **Preconditions:** Lab 1 shared style, Team 1 with student1 and student2.
- **Steps:**
  1. As student2 submit a file.
  2. As student1 open Lab 1 and the submission history.
  3. Open the file as student1.
- **Expected:**
  - The submission is recorded once, with "Submitted by <student2> on <date>".
  - student1 sees the same submission and attempts used, can open the file, and cannot go over the attempt limit.
  - Lateness and attempts are the group's, not each student's.

### TC-GRP-25
**Leader-only submission**
- **Role:** Students. **Priority:** P1
- **Preconditions:** leader-only style, Team 1 with a final leader student1.
- **Steps:** As student2 open Lab 1. As student1 submit.
- **Expected:**
  - student2 sees a message naming the leader, with the form disabled, and cannot submit.
  - student1 submits. Both then see the group's submission.
  - Until a leader is final, any member can submit.

### TC-GRP-26
**Per-member submission**
- **Role:** Students. **Priority:** P1
- **Preconditions:** per-member style, Team 1 with two students.
- **Steps:** As student1 submit. As student2 look at Lab 1, then submit after the deadline.
- **Expected:** Each student has their own submission, attempts and deadline. student1's submission does not appear for student2. The group exists for grading and roster only. An extension on the group does not change per-member deadlines.

### TC-GRP-27
**Ungrouped policy: must join, and may work alone**
- **Role:** Student, instructor. **Priority:** P1
- **Steps:**
  1. Policy "must join a group": as an ungrouped student try to submit.
  2. Change the policy to "may work alone" (confirm if submissions exist). Submit as the ungrouped student.
  3. As the instructor open Grade Work for Lab 1.
- **Expected:**
  - Step 1: "You're not in a group yet", form disabled, no submission possible. The instructor's group view lists the student under Ungrouped with a blocker.
  - Step 2: the student submits alone. Past submissions stay valid.
  - Step 3: the solo student has a "Grade" button that opens the ordinary per-student page.

### TC-GRP-28
**First submission freezes the group**
- **Role:** Student, instructor. **Priority:** P1
- **Steps:** After a group submits (TC-GRP-24), as a member try Leave or Switch. As the instructor try to move a member, delete the group, and change the submission style or formation mode.
- **Expected:**
  - The card says the group has submitted and has no Leave. Leaving is refused ("Your group has submitted; ask your instructor").
  - The instructor's board shows a Frozen badge, Unlock and "Add late enrollee", no Move on members and no Delete. Rename stays.
  - Re-copy and Copy from another activity are hidden. A per-member submission also freezes the group.

### TC-GRP-29
**Unlock a frozen group**
- **Role:** Instructor. **Priority:** P1
- **Steps:** On a frozen group press Unlock. Try with an empty reason, then with a reason, and confirm. Move a member.
- **Expected:**
  - A reason is required (browser and server). The dialog is titled "Unlock <group>?" with a warning.
  - After unlock the group can change. The group's members and the instructors are told (TC-GRP-41), and the change is in the audit log with the reason.

### TC-GRP-30
**Extend a group's deadline**
- **Role:** Instructor, student. **Priority:** P1
- **Preconditions:** a shared group, deadline passed or close, late work not allowed.
- **Steps:** In the editor press "Extend deadline" on Team 1, pick a later date, give a reason. As a member open Lab 1 and submit.
- **Expected:**
  - The group shows an "Extended to ..." badge. The new date must be later than the current deadline or earlier extension.
  - Members see an extension notice with the reason on the card and the assignment page, and can submit on time. The notification to members does not include the reason. Another group is unaffected. There is no per-member extension.

### TC-GRP-31
**Grade collectively**
- **Role:** Instructor. **Priority:** P1
- **Preconditions:** a group of four submitted, mode "collective", single score, total 20.
- **Steps:** Open Grade Work for Lab 1. Pick the group. Read the submission and who submitted. Enter 16, add feedback, press "Save grades for 4 members".
- **Expected:**
  - Four members each get 16/20 in one save (all or none). The group status is graded and read-only, with "Reopen grading" behind a confirm.
  - Plain per-student Grade Work refuses this submission ("This is a group submission. Grade it from the group view").
  - The sidebar shows status words with member counts.

### TC-GRP-32
**Collective with an adjustment**
- **Role:** Instructor, then two students. **Priority:** P1
- **Steps:** Mode "collective with adjustment". Group score 16. Adjust one member by -2 with a reason and a private note, adjust another by +1 with no reason, then add the reason. Save. Open Lab 1 as the adjusted member and as a teammate.
- **Expected:**
  - A missing reason is shown beside that member before anything is sent.
  - The adjusted member sees their final 14, the group part, the adjustment and its reason, and the private note.
  - The teammate sees only their own result. No other member's adjustment, reason, note, score or final appears on any of their pages or notices.

### TC-GRP-33
**Group and individual rubric criteria**
- **Role:** Instructor. **Priority:** P1
- **Steps:** In the assignment's rubric make one criterion Scored "group" and one "individual". Grade a group of three, with different individual scores. As a member open the result.
- **Expected:**
  - The "Scored" selector appears only on group activities. Group criteria are scored once, individual ones per member, with live totals.
  - A member's score is the group criteria plus their own individual criteria, kept between 0 and the total. The result lists each criterion with its scope.

### TC-GRP-34
**Individual grading and the average**
- **Role:** Instructor. **Priority:** P2
- **Steps:**
  1. Single score, mode "individual", shared style. Grade each member separately.
  2. Per-member style with "average of members". Two of three students have handed in. Grade.
- **Expected:**
  - Step 1: each member gets their own score.
  - Step 2: the members who handed in get the average of their scores. The member with no hand-in stays ungraded.

### TC-GRP-35
**A student dropped before grading**
- **Role:** Instructor. **Priority:** P1
- **Steps:** Set a group member's enrolment to dropped (enrolment screen, or an SIS import). Open the group.
- **Expected:** The student is out of every group in the course, and the group is not graded for them. If the group had submitted, the instructors and remaining members are told. The dropped student is not told.

### TC-GRP-36
**A student dropped after grading: keep or excuse**
- **Role:** Instructor. **Priority:** P1
- **Steps:** Grade a group (TC-GRP-31), drop one member. In Grade Work open the group and find the removed member. Choose "Keep or excuse...": first keep, then excuse, each with a reason.
- **Expected:**
  - The removed member is shown with their score. Keep is the default and counts the score. Excuse counts the member as excused, with an "Excused" badge, and the gradebook cell is excused.
  - Both choices are audited with the reason.

### TC-GRP-37
**A late enrollee: grade separately or inherit**
- **Role:** Instructor. **Priority:** P1
- **Steps:** After grading a group enrol a new student and press "Add late enrollee" on the group. In the group view press "Decide...". Try Save without a decision. Choose "inherit" with a reason. Repeat with a second new student and choose "grade separately".
- **Expected:**
  - The new member shows "Joined after grading" and pending (no score, never zero). Save is blocked until each late member has a decision.
  - Inherit gives them the group part. "Grade separately" opens their inputs and the student stays pending until graded. After a reload the dialog is offered again until they are graded.

### TC-GRP-38
**The gradebook after group grading**
- **Role:** Instructor, student. **Priority:** P1
- **Steps:** After TC-GRP-32 open the Gradebook as the instructor, and Grades as each member.
- **Expected:**
  - One score per member: the member's own final. A pending member shows no score, and an excused member is shown as excused.
  - Running and final grades include the group score. A reopened group's cells clear. Regrading does not duplicate rows.
  - No export gains a Group column.

### TC-GRP-39
**Student, parent and dean views**
- **Role:** Student, parent (`parent1`), dean. **Priority:** P1
- **Preconditions:** Lab 1 graded for Team 1 including student1, with an adjustment for student1.
- **Steps:**
  1. As student1 open Lab 1 and the module view of the same assignment.
  2. As `parent1` open student1's IT101-A course, Assessments tab.
  3. As the dean open the course and Lab 1's view page. Look for any way to change something.
  4. As `parent2` (no child), and as `parent1` with a teammate's id in the address.
- **Expected:**
  - The student card is on both pages. The student sees their own breakdown.
  - The parent sees "Group: Team 1 (first names)", the child's grade with its group and individual parts and own adjustment and reason, and "Other students' scores and adjustments are not shown." No private note, feedback or work. A plain assignment row shows no score.
  - The dean sees the group list, work, scores, feedback and every member's adjustment, with a "View only" note and no input or button. Writes from the dean are refused.
  - An unlinked parent or a teammate's address answers 404.

### TC-GRP-40
**Learning item groups and completion modes**
- **Role:** Instructor, students. **Priority:** P1
- **Steps:**
  1. Open a reading item in the editor. Switch groups on, attach a set, pick "Any member marks it done for the group". Try Proposed mode.
  2. As student1 (in the group) open the item and press "Mark complete for the group".
  3. As student2 in the same group look at the item.
  4. Change to "Each member completes their own" and repeat with a new group.
  5. Move a student into a completed group. Open the item as an ungrouped student.
- **Expected:**
  - Proposed mode is refused for items. An assignment or quiz item is refused with a message to use the assignment's own settings.
  - Any-member mode: the item is complete for every member, and the card says "Marked complete by <student1> for the group". A student who joins the completed group gets a completion too, and a student who leaves keeps theirs.
  - Each-member mode: every member's card shows "Done" or "Not done yet" for their own group only.
  - Groups on items never freeze. An ungrouped student is told they can still complete the item alone.
  - Other groups appear only if "Students can see other groups" is on, and never with completion status.

### TC-GRP-41
**Notifications in the app and by email**
- **Role:** All. **Priority:** P1
- **Preconditions:** the every-minute job `php spark exports:run` is running so the mail outbox sends. Check the bell and the mailbox for each person.
- **Steps:** Cause each event and check who is told: (1) place a student, (2) a member joins or leaves, (3) start a leader vote, (4) a group submits, (5) grade a group, (6) let a signup window come within 24 hours of closing, (7) a proposal reaches the smallest size, (8) a window closes with a group below the minimum, (9) extend a deadline, (10) unlock a group, or add a late enrollee to a frozen group.
- **Expected:**
  - Each event reaches the people listed in `group-activities.md`, in the app and in one email each, under "About your groups".
  - The person who acted is never told. Repeating the same action does not tell anyone twice. Auto-generating for many students tells each once.
  - The grade notice has the same words for everyone and no score, adjustment, reason, note or name. The extension notice has no reason.
  - A student removed by an instructor gets no notice.
  - Events 6 and 8 fire only while the every-minute job runs.

### TC-GRP-42
**Copy an offering**
- **Role:** Institutional administrator. **Priority:** P2
- **Preconditions:** IT101-A with an active set (with groups and members), an archived set, and an assignment that uses groups.
- **Steps:** Copy the offering to a new term. Open its Groups tab and the copied assignment.
- **Expected:** The active set and its groups are there, with no members, and signup times shifted like other dates. The archived set is not copied. The copied assignment has no groups and its rubric criteria keep their scope. No leaders, scores, invitations, votes or extensions come across.

### TC-GRP-43
**A finalized offering refuses group changes**
- **Role:** Instructor. **Priority:** P1
- **Preconditions:** the offering's grades are finalized.
- **Steps:** Open the Groups tab, a group activity's card and Grade Work. Try to create a set, move a student, grade a group, unlock a group.
- **Expected:** Write controls are hidden or disabled with the lock-note giving the reason, and the server refuses each write with the lock message. A closed course shows "This course is closed, so its groups cannot be changed." A drop on a finalized offering leaves the group rows as they are.

### TC-GRP-44
**Phone layout at 360 px**
- **Role:** Instructor, student. **Priority:** P2
- **Steps:** At 360 px open: the Groups tab and a set, the activity editor card and proposals queue, Grade Work's group view, the student card with the leader and invitations rows, the learning item card, the dean view and the parent tab.
- **Expected:** No horizontal page scroll. Buttons are full width and at least 40 px tall. Member rows in the group view stack. Dialogs fit and trap focus. The picker, not drag, is the way to move students.

### TC-GRP-45
**Keyboard-only walk-through**
- **Role:** Student, instructor. **Priority:** P2
- **Steps:** With no mouse: as the instructor create a set, place a student with the picker, attach it, grade a group with an adjustment and save. As a student join, vote, rename and leave.
- **Expected:** Every control is reachable in a sensible order with a visible focus ring. Dialogs trap focus and close with Escape, returning focus. Status is never colour alone. Results (join, full, saved) are announced to a screen reader, and an axe scan finds nothing serious.

### TC-GRP-46
**Dark mode**
- **Role:** All. **Priority:** P2
- **Steps:** Switch to dark mode and open every group screen listed in TC-GRP-44, including badges, the lock-note, alerts and dialogs.
- **Expected:** Text and badges stay readable, and no light panels are left over. The group screens look like the neighbouring tabs.

### TC-GRP-47
**Chairperson and wrong-role access**
- **Role:** Chairperson, student, institutional administrator. **Priority:** P1
- **Steps:**
  1. As a chairperson of the course repeat TC-GRP-11, 15 and 31.
  2. As a student type the instructor group addresses by hand (sets, activity groups, group-grading, unlock).
  3. As an institutional administrator look for a Groups tab.
  4. As a student of another course use a group id from IT101-A by address.
- **Expected:**
  - The chairperson can do everything the instructor can.
  - The student is refused or sent back, and has no group set routes.
  - The administrator sees no Groups tab. A foreign course, activity, group or invitation id answers 404.
