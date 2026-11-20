# Task 4.1 report: GroupSignupService

Files: app/Services/Groups/GroupSignupService.php, tests/database/GroupSignupServiceTest.php. No other files changed; no helpers added to existing services.

Red phase: `Tests: 22, Assertions: 0, Errors: 22` (class not found). Green: `--filter 'GroupSignup|ActivityGroup|Group'` gives `OK ... Tests: 346, Assertions: 1528`.

Service: join(configId, groupId, studentId), leave(groupId, studentId), switchTo(configId, toGroupId, studentId), candidateGroups(configId, studentId). All refusals are StructureException. Each write runs in one transaction whose FIRST query is `SELECT ... FOR UPDATE` on the group row(s) (switch locks both in id order), so the capacity count and the already-in-a-group check see what earlier joiners committed. Non-rule failures (lock timeout, unique-key clash) roll back and become a plain message.

Concurrency tests (what they prove):
- testTwoConcurrentJoinsForLastSeatOnlyOneWins: a real second PHP process with its own mysqli connection locks the group row, signals, sleeps 1.5s, inserts the last seat and commits; meanwhile this process's join blocks on the row lock (asserted > 0.5s), then recounts and throws "Group is full". Exactly max members remain, and the loser has no row. Proves lock + recount after wait (a stale count would have over-filled).
- testJoinTakesTheGroupRowLock: a second connection holds the row lock, innodb_lock_wait_timeout=1; the join fails and leaves no row.
- Both commit the fixtures, then TRUNCATE all tables and reopen the isolation transaction (course_offerings left empty).
Not proven: two simultaneous service-driven joins both starting at once (the second joiner is a raw SQL process, not the service).

Notes: audit actor column is actor_user_id. nonleader_can_leave defaults to 1, so the toggle-off test sets it to 0. Leave by a frozen group gives 'Your group has submitted; ask your instructor'.

20-run loop: 20/20 passed (course_offerings left empty by the concurrency tests' cleanup).
