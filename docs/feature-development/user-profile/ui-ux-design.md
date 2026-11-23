# User Profile: UI/UX design

Status: mockups **approved 2026-11-23**; this document **draft for approval** with `requirements.md`.

## Approved mockups (open in a browser)
- `mockups/profile-and-modal.html`: own `/profile` (instructor + chair), avatar popover, full profile modal. Approved.
- `mockups/settings.html`: `/settings` desktop (instructor) and phone (parent). Approved.
- `mockups/requests-and-variants.html`: correction request dialog, admin Requests tab + Review dialog, deactivated profile, parent viewing own child. Approved.

Mockups are visual references with placeholder data; the build uses Mentora's real components and tokens.

## Design language
- `layouts/app`, `components/page-head`, white `rounded-2xl` cards on the app background, `rounded-full` buttons (primary brand fill, secondary outlined), Poppins body, Parkinsans for names and page titles, brand tokens only (`bg-brand`, `bg-brand-tint`, ...; institution branding overrides them).
- The one bold element: the profile header band (brand gradient) with the large avatar overlapping it. Used on `/profile`, the modal and `/people/{id}`. A deactivated profile uses a grey band.
- Avatars: photo, or initials on a colour picked stably from the person's id from a fixed accessible set (white text passes contrast).

## Screens

### `/profile` (owner)
Page head "My profile" + "This is what people in your school see when they open your profile." · Preview toggle (Me / As a student sees it) · checklist card (dismissable) · header band card (photo with pencil, preferred name + pronouns, legal name line, role chips highest first, "Edit name & pronouns") · About · Office & consultation (staff) · Official details (locked, "Managed by your school", Request a correction) · role cards (This term / Parents who can see your progress / Your children).

### Popover
~300px card anchored to the avatar with an arrow; photo, preferred name + pronouns, legal name if different, top role in brand colour; Message (primary) and See more (secondary).

### Profile modal and `/people/{id}`
Same partial. Header band, large photo, names, pronouns, role chips; Message + Copy email; sections About, Office & consultation, Contact, This term; admin-only row (Remove photo, Clear field, Open in People). Phones: full-screen sheet.

### `/settings`
Page head "Settings" + "Your password, sign-in and the emails Mentora sends you." Desktop: sticky jump list (Password, Two-step sign-in, Signed-in devices, Notifications, Recent activity) + section cards. Phones: "Jump to" menu + stacked cards, full-width buttons.

### Correction request dialog (owner)
Field select · Now (read-only) · Should be · Why · info note for email · Cancel / Send request.

### People → Requests tab (institutional admin)
Tab with count badge; table: person (avatar, name, role, number), change (old struck through → new, "From SIS" tag), sent, Review. Review dialog: title "Change {name}'s {field}?", reason, SIS warning, note (required to reject), Reject / Approve change.

### Variants
Deactivated profile (grey band, "Deactivated" badge, roles only, explanatory line). Parent viewing own child (no contact section).

## States (every new surface)
| Surface | Loading | Empty | Error | Filled |
|---|---|---|---|---|
| Popover | skeleton lines in the card | – | "Couldn't load this profile. Try again." with retry | as designed |
| Modal / page | skeleton header + sections | sections with no data hidden; own profile shows "Not added yet" with pencil | message + retry; 404 "This profile isn't available" | as designed |
| `/profile` checklist | – | card hidden when complete | – | items |
| Requests tab | table skeleton | empty state "No requests waiting" | page error alert | rows |
| Recent activity | – | "No security changes in the last 90 days." | – | list |
| Photo upload | progress on Save | initials | inline error naming the limit | photo |

## Copy rules
Sentence case; buttons say what happens ("Send request", "Approve change", "Change password", "Sign out of all other devices"); toasts reuse the verb ("Request sent", "Password changed"); errors say what to do, never apologise.

## Accessibility
Avatar is a button with `aria-haspopup="dialog"` and an accessible name ("Open Maria Santos's profile"); popover opens on focus and hover, Escape closes; modal traps focus and returns it to the avatar; touch: tap opens the modal; photos have alt text "Photo of {name}"; switches are `role="switch"` with `aria-checked`.
