# UI/UX Design (draft for approval, Stage 2)

Mockups (throwaway HTML, git-ignored): `.superpowers/brainstorm/2011-1795186363/content/01..04-*.html`. They were reviewed with the user screen by screen and not changed.

Design language: `docs/ui-guide.md`. Brand tokens (`bg-brand`, `bg-brand-tint`), rounded-full buttons (one primary per view), white `rounded-2xl` work surfaces, `page-head`, `tabs`, `data-table`, `dialog`, `confirm`, `lock-note`, `empty-state`, `skeleton`, `status-badge`, `alert`. Every screen needs loading, empty, error and filled states, a 360 px layout, keyboard use, and works in dark mode through tokens.

## Screens
| # | Screen | Role | Decisions | Notes |
|---|--------|------|-----------|-------|
| 1 | Course "Groups" tab: set list + set detail (groups, ungrouped pool, "Move..." picker) | Instructor, chairperson | D1, D2, D3, D17, D21 | Drag plus non-drag picker. Below-min flag. Lock-note when the offering is finalized. |
| 2A | Assignment editor "Group activity" section: set picker, ungrouped policy, submission style, grading mode, leader toggles, visibility toggle, rubric criterion scope | Instructor, chairperson | D2, D5, D6, D16, D24 | The "Students can see other groups" toggle comes from D14; confirm it stays. Style and policy changes after submissions need confirmation. |
| 2B | Grade Work group view: group list, submission, group criteria, group feedback, per-member individual criteria + adjustment + private note, atomic save | Instructor, chairperson | D7, D8, D10, D24 | One save writes all members or none. Reason required for any adjustment. |
| 3A | Student: not in a group, open groups with Join/Full, "Propose a new group" | Student | D4, D5, D17 | Blocked-from-submitting alert. |
| 3B | Student: "Your group" card, leader vote, rename/leave (when allowed), own grade with group + individual parts | Student | D14, D15, D16 | Only the leader submits under leader-only. |
| 3C | Student: frozen state, extension notice | Student | D9, D11 | |
| 3D | Learning item with group roster and completion button | Student | D13 | |
| 3E | 360 px phone views | Student | UI guide | Buttons become full-width. |
| 4A | Auto-generate dialog with preview | Instructor | D18 | "Nothing has been saved yet" until Save. Confirms replacing existing groups. |
| 4B | Proposals queue + post-window summary | Instructor | D17 | |
| 4C | Unlock confirm (reason required) | Instructor | D9 | |
| 4D | Remove dropped student from a graded group (keep/excuse) | Instructor | D10 | The add-late-enrollee dialog follows the same pattern (grade separately/inherit). |
| 4E | Dean read-only Grade Work | Dean | D14 | No write buttons. |
| 4F | Parent view of a child's group grade | Parent | D14 | No other students' data. |

## Still to design in Stage 4 (not mocked)
Set edit dialog fields, self-signup window dates picker, keep-together/keep-apart pairs editor, "Copy from another activity" picker, notification wording, exports Group column, the empty/loading/error variants of every screen above, and the phone version of the instructor screens.

## Accessibility notes
Status is never colour alone (badge words). Drag has a keyboard alternative. Dialogs trap focus. Targets are at least 40 px. Live regions announce join, full and save results.
