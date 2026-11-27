# UI/UX design

Stage 2 mockups picked through the visual companion (2-3 options per screen). Source files copied to `mockups/` when Stage 2 closes.
Design language: `layouts/app`, white rounded-2xl cards, brand `#0040A6` tokens, grey `#646464` secondary text, pill buttons, Poppins / Parkinsans, the existing Messages two-pane shell (`app/Views/pages/messages/index.php`).

## Picks
| # | Screen | Pick | Notes |
|---|---|---|---|
| 1 | First-message notice (recipient) + draft notice (sender) | **B: quiet centred system line** in the conversation with Block / Report pills; sender sees a centred grey "First message to [name]" line where the conversation starts | Claude recommended A; user chose B. Styled like existing tombstone lines. Shown until the recipient replies or blocks (BC-1). |
| 2 | Restriction / pause notice (restricted person) | **A: notice card in the list pane, "+ New" greyed out**; clicking it repeats the reason; replies keep working | Same pattern for the D8 pause ("while some reports are reviewed"). "No sending" also replaces reply boxes except course chats they teach. |
| 3 | Admin report review | **C: grouped by the reported person** (one case per person: open reports stacked, pause marker with time left, "Dismiss all", "Remove reported messages", "Restrict…"); each report can still be opened and decided on its own | Claude recommended B; user chose C. Changes the Reports page from a list of reports to a list of people; Open/Dismissed/Actioned tabs stay. |
| 4 | Group management (owner) | **B: "Group settings" dialog with Members / Details tabs**, opened from the thread header "⋯" menu; Members: list, owner badge, Remove, + Add people; Details: name, Leave, Close group | Claude recommended A; user chose B. Matches the existing New group dialog (retained). Full screen on phones like other dialogs. |
| 5 | Institution settings → Messages tab | **B: one explanatory paragraph + segmented switch** (Off / Classmates only / Whole institution), then "Default quiet hours" with two times or None; Chat controls unchanged | Claude recommended A; user chose B. Extends the existing Messages tab of `pages/institution/settings.php`. |
| 6 | Messages settings dialog (incl. blocked people) | **B: two three-way switches**: Email (Off / Everyone / People I have talked to, replacing the checkbox) and Quiet hours (School default / My own / None; time fields appear for My own); blocked list shows a "Paused" badge + "Paused while you share a course." | Extends today's `messages-prefs` dialog; moves to `/settings` with User Profile (UP D15). Screens 6 and 8 merged. |
| 7 | Minor flag (People screen, inst. admin) | **B: own "Mark as minor / Remove minor mark" action** in the person's Actions menu, confirm dialog explaining the effect, "Minor" badge in the People list (admins and staff only) | Refines D25 / S2-f (they said "checkbox in the edit-person dialog"); still on today's People screen, no duplicate with UP D22. CSV import column unchanged. |

Mockup files: `mockups/first-contact.html`, `restriction-notice.html`, `report-actions.html`, `group-manage.html`, `institution-settings.html`, `messages-settings.html`, `minor-flag.html` (each shows all options; the pick is recorded above).

## States and behaviour common to every screen (Claude's calls, from the existing Messages patterns)
- **Loading:** existing skeleton rows/pulse blocks; dialogs show a spinner on the primary button while saving.
- **Empty:** Reports "No open reports"; group members never empty (owner always present); blocked list "No one blocked."
- **Error:** inline `role="status"` red note under the control (existing `group-note` / `controls-note` pattern); a failed save keeps the dialog open with the user's input.
- **Refusal:** generic "You can't message this person" with no reason (privacy NFR).
- **Responsive:** dialogs full screen below `md` (existing dialog component); Reports cases stack; the three-way switches wrap to a vertical stack under 360px; touch targets `pointer-coarse:min-h-11`.
- **Accessibility:** banners and notices `role="status"`; switches are radio groups with visible labels; badge text is real text, not colour alone.

## Deferred until User Profile lands (D23)
- New-message picker with suggestions, search rows with avatars (FR12).
- Moving the settings dialog to `/settings` → Notifications (UP D15).
These get their own mockup round at that phase.
