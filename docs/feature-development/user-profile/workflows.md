# User Profile: workflows

Status: **draft for approval** (Stage 2). Requirements: `requirements.md` (FR numbers below).

## W1 Look someone up (any institution member)
1. Hover or focus an avatar anywhere (FR3) → popover loads (skeleton first time).
2. Popover → Message (if allowed) or See more.
3. Click the avatar or See more → modal with the full profile, fields per FR2.
4. From a notification or link → `/people/{id}` shows the same content.
5. Not allowed to see the person (parent and non-linked student, other institution) → avatar is plain; the URL answers "not found".

## W2 Complete my profile (owner, any role)
1. Account menu → My Profile → `/profile`.
2. Checklist card lists what is missing → click an item → its dialog opens.
3. Save → toast "Saved", the section updates, the item leaves the checklist; Dismiss hides the card for good.
4. "Preview as a student" shows what a student would see.

## W3 Change my photo (owner)
1. Pencil on the photo → dialog → choose image → crop square → Save.
2. Server checks and re-encodes → photo appears everywhere on next load.
3. Wrong type or too big → inline error naming the limit; nothing saved.
4. Remove → initials return.
5. Institution has photos Off → the photo control explains "Your school has turned profile photos off" and upload is disabled.

## W4 Fix an official detail (owner → institutional admin)
1. `/profile` → Official details → Request a correction → field, new value, reason → Send.
2. Owner sees "Waiting for your school" on that field; one open request per field.
3. Admin gets a bell notification → People → Requests → Review.
4. Approve → value written, audit-logged, requester notified (bell + email). Reject → reason required, requester notified.
5. Email field: approval sends a link to the new address; the change applies when it is opened; the old address is told; after 48 hours unopened it expires and the requester is told.
6. Requester is an institutional admin → request goes to another institutional admin, else to system admins (Users screen).

## W5 Change my password (owner)
1. Settings → Password → current, new, confirm → Change password.
2. Wrong current password → error; repeated failures → temporary block with the wait time.
3. Success → other sessions ended, this one kept, email notice, activity entry.
4. Forgot current password → "Email me a link" → existing reset flow.

## W6 Two-step sign-in (owner)
1. Settings → Two-step sign-in shows On/Off and why.
2. Turn on → confirmation → next login asks for the emailed code.
3. Turn off (own opt-in) → password asked → off. Required by school → no off control.

## W7 Sign out elsewhere (owner)
Settings → Signed-in devices → Sign out of all other devices → confirm → done toast, activity entry, email.

## W8 Notifications (owner)
Settings → Notifications → change Messages email alert, quiet hours (staff), or per-child emails (parents) → Save → same behaviour as before, now from one place.

## W9 Moderate a profile (institutional admin)
1. From the profile modal or the People row menu → Remove photo / Clear field / Upload photo.
2. Confirm → done; audit-logged; owner sees it in Recent activity and gets an email.

## W10 Set an instructor's home department (institutional admin)
People → edit person → Home department → Save → profile shows it instead of "Teaches in …". Imports set it when the source has it.

## W11 Turn photos off or on (institutional admin)
Institution settings → Profile photos → Off → everyone shows initials; stored photos kept. On → photos return.

## W12 Parent and their children
1. Parent `/profile` lists linked children (owner-only).
2. Child's avatar in the portal → popover/modal as staff see it, without contact details (FR2).
3. Settings → Notifications → per-child email switches.

## W13 Deactivated person
Any avatar of a deactivated person → basics only with a "Deactivated" badge; admins see full details on the People screen.

## Role summary

| Role | /profile extras | /settings extras | Admin work |
|---|---|---|---|
| Student | parents-who-can-see card; program/year/section | – | – |
| Instructor | office, hours, phone; home department | quiet hours | – |
| Chairperson | as instructor + department led | quiet hours | – |
| Dean | as instructor + college led | quiet hours | – |
| Parent | linked children | per-child emails | – |
| Institutional admin | office, hours, phone; institution | – | Requests tab, moderation, home department, photos setting |
| System admin | name, email, role only | – | escalated requests on Users |
