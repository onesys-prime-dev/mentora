# Open questions

## Waiting for the user
- **Stage gate 2 → 3 (asked):** approve `requirements.md`, `workflows.md`, `ui-ux-design.md` + `mockups/`.
- B1 → D31, B2 → D32.
- Builder check settled without the user: student → own linked parents visible (D3 wins over my FR2 table error, fixed); requests from an institution with no institutional admin go to system admins (Claude's call); preview toggle wording follows the approved mockup (Claude's call).
- S6 mockup 3 (`requests-and-variants.html`) **approved 2026-11-23**.
- S5 mockup 2 (`settings.html`) **approved 2026-11-23**.
- S4 mockup 1 (`profile-and-modal.html`: `/profile`, popover, modal) **approved 2026-11-23**. Mockups live in `.superpowers/brainstorm/560-1795402768/content/` (gitignored; copy into `ui-ux-design.md` at the end).
- Mockups still to show: admin Requests tab + correction dialog; phone views; deactivated + parent-child modal variants.

## Stage 2: consistency calls (accepted as D29; S1 → D28)
- **C1 Header avatar:** the header's own avatar keeps opening the account menu (exception to D18); the menu's My Profile leads to `/profile`. Reason: it is the menu trigger today and on phones.
- **C2 Impersonation:** already safe. `ImpersonationFilter` refuses every non-GET while viewing as someone, so password, 2FA, photo and edits cannot be changed by an impersonating admin. No new work.
- **C3 Read-only institution:** follow the existing rule in `ReadOnlyFilter` ("a person's own things stay theirs", decision 8.2): password, 2FA, sign-out-everywhere and notification settings stay usable; profile content (photo, preferred name, pronouns, bio, staff fields) and correction requests are refused like other writes.
- **C4 Several institutions:** `institution_users` allows a user in more than one institution, but the app resolves one (`institutionOf`). D3 "same institution" uses that same resolver.
- **C5 System admin profile:** `/profile` shows name, email, role only (no D7 staff fields, no checklist); `/settings` fully available.

## Stage gate history
- Stage 1 → 2 approved 2026-11-23; out-of-scope list confirmed.

## Answered
- Q1 → D1
- Q2 → D2 (superseded)
- Q2b → D3
- Q3 → D5
- Q4 → D4 (follow-up feature: institution-wide Messages, outside this workspace)
- Q5, Q5b → D6 (also closes the D2 student-photo switch question)
- Q6 → D7
- Q7 → D8
- Q8 → D9 (user direction: profile ignores active role, roles highest first)
- Q9 → D10
- Q10 → D11
- Q11 → D12
- Q12 → D13
- Q13 → D14
- Q14 → D15
- Q15 → D16
- Q16 → D17
- Q17 → D18 (user direction: avatar hover popover + click modal; names not clickable)
- Q18 → D19 (option C minus gradebook rows)
- Q19 → D20
- Q20 → D21
- Q21 → D22
- Q22 → D23
- Q23 → D24
- Q24 → D25
- Q25 → D26
- Q26 → D27

## Out of scope (confirmed by the user 2026-11-23)
- Account deletion / self-deactivation and personal data export (admins keep deactivation)
- Authenticator-app 2FA, device/session list (D14)
- Per-category email switches (D15)
- Photo approval mode (D6), report button (D6)
- Help & Support content (D23)

## Follow-up feature tracks (outside this workspace)
- Institution-wide Messages (D4)
- Single identity + role screen redesign: no role switcher; chair, dean, parent screens in the student/instructor design language; staff consultation-hours dashboard nudge (D10, D11, D27)

## Left for the plan (implementation details, not product decisions)
- Storage shape for `home_department_id`, 2FA opt-in flag, checklist dismissal flag, correction requests table
- Photo size cap value; popover data loading (fetch on hover vs embedded)
- Whether `teachingHomeColumns` callers switch to the D8 home department
- Exact lists of "social" vs "official" name places (D5)
