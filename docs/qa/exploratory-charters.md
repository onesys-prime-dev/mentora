# Exploratory charters

| | |
|---|---|
| **Date** | 2026-10-28 (Wave 4 of the QA plan) |
| **Code under test** | `ae86042` and later |
| **Method** | The `exploratory-testing` skill: session-based test management (SBTM). |
| **Status** | Charters only. **No session has been run.** |

These cover the 31 exploratory scenarios (`EXP`) of [`coverage-matrix.md`](coverage-matrix.md). An exploratory scenario has no fixed expected result by design: the output of a session is findings, not a pass or fail. Where a decision in [`decision-register.md`](decision-register.md) already says what is right, the charter names it as the oracle.

## How to run a session

1. **Environment:** the dedicated local QA database and the mail sink (register PLN-07), seeded with `php spark db:seed DatabaseSeeder`. Never staging or production. Note the commit you test.
2. **Time box:** 60 to 90 minutes, as set per charter. Roughly: orient 10–15 minutes, explore 40–60, wrap up 10–15. Stop at the time box and debrief, even alone.
3. **Notes during the session**, in this log (one per session, kept with the debrief):

   ```
   Session: [charter ID and summary]
   Date: [date]  |  Tester: [name]  |  Build: [commit]  |  Duration: [minutes]

   | Time | Action / input | Observation | Tag | Follow-up |
   |------|----------------|-------------|-----|-----------|
   ```

   Tags: **BUG** (wrong by an oracle below), **QUESTION** (no oracle says), **IDEA** (a new test), **RISK**, **NOTE**.
4. **Debrief** after each session:

   ```
   Charter: [ID]   Tester:   Date:   Build:   Planned → actual minutes:
   Coverage: covered / partial / unexplored, per bullet of the charter
   Bugs filed (IDs):        Questions for the owner:        Test ideas:
   Follow-up: [ ] file bugs  [ ] write a regression test for each reproducible bug
              [ ] schedule a follow-up session  [ ] update the risk register
   ```

5. **After a bug:** every reproducible bug becomes a regression test (PHPUnit, or Playwright for a screen), seen failing before the fix and passing after. A QUESTION goes to the decision register as a new item.

**Oracles used throughout** (HICCUPS): the decisions in the register (**Claims**), the code's documented behavior and the other screens of the product (**Product**), the interface rules and WCAG 2.2 AA (**Standards**), what the user would expect (**User**), and what it did before (**History**).

## The charters

| ID | Charter | Scenarios | Time box | Priority |
|---|---|---|---|---|
| EC-01 | Sign-in and session tricks | PS-AUT-03, PS-AUT-14, PS-AUT-16, PS-SEC-12 | 60 min | P2 |
| EC-02 | Script in what people type | PS-SEC-04, PS-MOD-14 | 90 min | P1 |
| EC-03 | Hostile uploads and import files | PS-SEC-07, PS-QBK-08 | 90 min | P1 |
| EC-04 | What production errors and the database's mode give away | PS-SEC-13, PS-DAT-03 | 60 min | P2 |
| EC-05 | Two people at the same moment | PS-OFF-10, PS-ENR-08, PS-AIQ-05, PS-DSC-05 | 90 min | P2 |
| EC-06 | Records that cross institutions, or lose their owner | PS-OFF-05, PS-DAT-05, PS-PAR-04, PS-DAT-07 | 90 min | P1 |
| EC-07 | Structure and content edge states | PS-INS-10, PS-CHR-13, PS-OFF-09, PS-MOD-03, PS-MOD-09, PS-ASM-09 | 90 min | P2 |
| EC-08 | When a job or a flag cannot run | PS-JOB-04, PS-FLG-04 | 60 min | P2 |
| EC-09 | The SIS signature and stored credentials | PS-SIS-07, PS-SIS-10 | 60 min | P2 |
| EC-10 | The quiz timer in the browser | PS-QUZ-18 | 60 min | P1 |
| EC-11 | Loading, empty, error and filled states | PS-FE-05 | 90 min | P2 |
| EC-12 | Firefox and WebKit | PS-FE-07 | 60 min | P3 |

### EC-01 Sign-in and session tricks

```
Explore sign-in, the emailed code and the session cookie
  with email case and spacing variants, repeated wrong codes, a stopwatch, and the browser's cookie tools
  to discover ways to dodge the lockout, guess a code, tell real accounts from unknown ones, or reuse a session
```

- Lock an email, then try `Student5@…`, ` student5@… `, and a trailing dot: is it the same lock? (Oracle: TC-AUTH-06; one email, one count.)
- Enter wrong codes 20 times: is anything counted or slowed? (Decision MISS-AUTH-03 wants re-authentication limits that survive a new session; there is no stated rule for codes: record a QUESTION.)
- Time 20 sign-ins with a real email and 20 with an unknown one: is the difference large enough to tell them apart? (TC-AUTH-03's note.)
- Note the session id before and after signing in; copy a session cookie to another browser; read the cookie's flags. (Oracle: the id changes on sign-in; flags per decision MISS-AUTH-02 in production.)

### EC-02 Script in what people type

```
Explore every place one person's text is shown to another
  with script, event-handler, broken-HTML and long Unicode payloads
  (for example <script>alert(1)</script>, <img src=x onerror=alert(1)>, "><svg onload=alert(1)>)
  to discover stored cross-site scripting
```

- Where: names, announcement subjects and messages, discussion titles and posts, rubric text, question text and choices, feedback, the institution banner, follow-ups, department announcements, audit values, file names, rich-text lesson content.
- Who sees it: put it in as a student, view it as an instructor, dean, parent and administrator, and in exports and PDFs.
- Oracle: nothing runs; the text shows as typed or is cleaned; rich text keeps only safe formatting.

### EC-03 Hostile uploads and import files

```
Explore every upload and the question import
  with disguised and hostile files: a zip bomb, an XML file with an external entity, a Word file with macros,
  a spreadsheet of 1,000,000 rows, an .html or .svg with script, a .php file renamed .png, a PDF-and-ZIP polyglot,
  names with ../ and unusual Unicode
  to discover a file that runs, reads the server, exhausts it, or is served unsafely
```

- Oracle: rejected with a sentence (TC-QUIZ-24's "a damaged or disguised file is a sentence, not an error"); stored outside the web root; served with `nosniff` and inline only when safe (TC-FIL-02); no server file read; memory and time stay bounded.

### EC-04 What production errors and the database's mode give away

```
Explore the app in the production environment
  with broken requests (bad ids, wrong types, a database stopped mid-request) and over-long or invalid values written through forms and APIs
  to discover error pages that show stack traces, SQL or paths, and values the database silently cuts or coerces
```

- Oracle: production error pages show a plain message only (no trace, query or path); an over-long string or invalid choice is refused, not cut short or changed (the database is not in strict mode, inventory "schema"): record each silent change as a BUG.

### EC-05 Two people at the same moment

```
Explore the actions two people can take at once
  with two browsers (or a script firing two requests together)
  to discover double counts, lost updates and capacity overruns
```

- Two enrolments into the last place of a section (by two administrators, and by an SIS run while an administrator adds one). Oracle: capacity is never exceeded.
- Two accepts of one generated question. Oracle: added once.
- Two replies to one discussion thread, and reacting twice quickly. Oracle: the reply count matches the replies; one reaction per person.
- Note anything else that double-counts.

### EC-06 Records that cross institutions, or lose their owner

```
Explore the links between records of different institutions, and records whose owner is deleted
  with direct writes through forms, APIs, the copy tool and the SIS, and deletions on the test database
  to discover a section, enrolment, submission or grade that belongs to two institutions, and orphans
```

- Make a section whose course is institution 1's and term another's (create, copy, SIS import). Oracle: refused (decision D1; MISS-DB-01 asks for database constraints where possible).
- Look for enrolment, submission and gradebook rows whose institution differs from their section's.
- Delete a user who is a parent in a link: is an orphan link left? Is the audit log untouched by a raw query or a cascade (it must be)?

### EC-07 Structure and content edge states

```
Explore structure and course content at their edges
  with soft-deleted institutions, expired chair assignments, class-time rows written directly,
  prerequisite cycles, unknown item types and deleted modules
  to discover states the screens do not handle
```

- Delete an institution (soft) and restore it: do its children survive and come back intact?
- A chair assignment set to `expired`, then reactivated: does `assigned_at` get overwritten?
- Class times written directly with an end before the start, overlapping or duplicate rows: what do the screens show?
- A prerequisite pointing at itself, at another section's item, or in a cycle; an item with an unknown type; a module deleted under an assessment. Oracle: each is refused, or the screen copes and says why.

### EC-08 When a job or a flag cannot run

```
Explore what people are told
  with subscriptions:sync stopped for two days, and the feature-flag table unreadable
  to discover notifications that silently stop, and features that fail open
```

- With `subscriptions:sync` stopped, check every notification kind: which still go (since `5069228` the hourly scheduler check should name the stopped job, TC-SA-19)?
- With the flags unreadable, try AI generation. Oracle: a switch that cannot be read means off (as `ParentPortalTest` pins for the portal); AI generation failing open is a BUG.

### EC-09 The SIS signature and stored credentials

```
Explore the file connector's signature check and the stored connection settings
  with a valid, an invalid and a missing .sig file, and the settings page, the database row, logs and exports
  to discover unsigned files accepted, and credentials that can be read back
```

- Oracle: a file with an invalid or missing signature is refused when signatures are required (`docs/sis-integration.md`); credentials are stored sealed with the application key and are never shown on a page, in an export, the audit log or the logs.

### EC-10 The quiz timer in the browser

```
Explore the quiz attempt page's timer and auto-submit
  with a short quiz, the computer's clock changed, a sleeping laptop, a lost network near the end, and two tabs
  to discover a timer that disagrees with the server, an auto-submit that loses answers, or one that fires twice
```

- Oracle: the server's time rules (TC-QUIZ-17: answers count until the timer plus 60 seconds; an expired attempt scores what was saved in time); one attempt across tabs (TC-QUIZ-04); the page tells the student plainly what happened. The browser code was not read during planning.

### EC-11 Loading, empty, error and filled states

```
Explore every screen of every role
  with an empty account, a full one, a slow network (browser throttling) and a failing API (blocked in the network tools)
  to discover screens missing a loading, empty or error state, or showing a blank or a raw error
```

- Oracle: the interface guide (`docs/ui-guide.md`): every screen has all four states, matching the student and instructor screens' design language.

### EC-12 Firefox and WebKit

```
Explore the main journeys of each role (TC-AUTH-01, TC-QUIZ-01, TC-ASG-01, TC-GRD-01, TC-DEAN-04, TC-PRT-01)
  with Firefox and WebKit (Safari's engine), desktop and a phone-sized window
  to discover anything that works only in Chromium
```

- Oracle: the same result as in Chromium. A difference that matters becomes a reason to run the Playwright suite on those browsers too.
