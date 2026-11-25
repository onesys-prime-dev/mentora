# Existing User Profile implementation: audit (2026-11-23)

Snapshot taken from branch `feat/group-activities-p11-report-group` @ a46a34e6. Retain/replace calls are **recommendations only** until the user decides.

## Data

| Item | What it is | Notes |
|---|---|---|
| `user_profiles` table (`CreateUserProfilesTable`, `AddActiveRoleToUserProfiles`, `StudentNumberIsUniqueInAnInstitution`) | PK `user_id`; `first_name`, `middle_name`, `last_name`, `suffix`, `student_number`, `student_number_institution_id`, `program_id`, `active_role`; soft deletes and timestamps | No photo, phone, bio, pronouns, title, address, birthdate or preference columns. |
| Shield `users` / `auth_identities` | username, email (identity `secret`), password hash | Email lives in Shield, not in `user_profiles`. |
| `message_preferences` (`MessagePreferenceModel`) | messaging email preferences | Has its own page: `GET/POST preferences` → `Messaging\PreferenceController`. |
| parent notification tables (`ParentPreferences`) | per-child email choice for parents | Edited on the parent profile page. |

## Backend

| Item | Behaviour |
|---|---|
| `App\Models\Users\UserProfileModel::retrieveUserProfile()` | One query: name, email, role, student number, program, department, college; for instructors the "teaching home" college comes from their **first** teaching assignment. |
| `current_user_profile()` (`app/Helpers/navigation_helper.php:264`) | Cached per request; used by the header. |
| `Students\StudentProfileController::index` → `GET student/profile` | Read only: name, email, student number, names of linked parents. "Contact your school to change". |
| `Parents\ParentProfileController` → `GET parent/profile`, `POST parent/profile/notifications`, `POST parent/profile/password-link` | Account info, per-child email choices, emailed password-reset link (no password form). |
| `Users\PasswordResetService` | Emailed one-time reset link; also used by admins. |
| `Admin\UsersController`, `Institution\PeopleController`, `Users\UserAdminService::update()` | Admins edit name/email/roles and reset passwords (behind `reauth`). |
| `Sis\SisImporter` (line ~344) | Overwrites `first_name`, `middle_name`, `last_name`, `suffix`, `email`, `student_number` from the SIS on each import. **Any self-edit of these fields will be overwritten by the next SIS run.** |
| `Users\AccountControl::signOutEverywhere()` | Ends all of a user's sessions; used by admin "sign out" (People/Users screens) and password reset. Reuse for D13/D14. |
| `Auth\Actions\AdminEmail2FA` | Email 2FA, admin accounts only. |
| `Admin\Impersonation`, `Auth\ActiveRoleService` | Role switching and impersonation read `user_profiles.active_role`. |

## Frontend

| Item | State |
|---|---|
| Header account menu (`app/Views/layouts/partials/header.php`) | Placeholder photo for everyone (`assets/images/placeholder/profile.png`). "My Profile" links only for student and parent; **"Settings" and "Help & Support" are inert**. |
| `pages/student/profile.php` | Two cards: account (read only) and linked parents. |
| `pages/parent/profile.php` | Account, children email toggles, password-link button. |
| `pages/dashboard/partials/profile-card.php`, `.../chairperson/profile-card.php` | Dashboard identity cards, placeholder photo. |
| Instructor, chairperson, dean, institutional admin, system admin | **No profile page.** |

## Gaps

- No profile page for 5 of 7 roles.
- No profile photo anywhere (the placeholder is hard-coded in at least 3 views).
- No self-service password change for signed-in users except the parent's emailed link.
- No self-service editing of anything.
- 2FA for admins only; no self-managed security (sessions, 2FA).
- Messaging preferences live on a separate page, unrelated to the profile.
- "Settings" menu item does nothing.

## Recommendations (pending user decisions)

- **Keep:** `user_profiles` table and its rows, `retrieveUserProfile` (extend it), `PasswordResetService`, parent email preferences, message preferences (decide where they surface).
- **Redesign:** student and parent pages into one shared profile shell with role-specific sections; the header menu entries.
- **Decide:** SIS-owned field locking; photo storage (the `files` table / `FileModel` already exists); where "Settings" goes.
- **Retire:** nothing until it is replaced and the user approves.

## Status after the rebuild

Retired or replaced:
- `StudentProfileController` and `pages/student/profile.php`: replaced by `/profile` (`Profiles\ProfileController`); `student/profile` GET redirects (`legacy`).
- `ParentProfileController` and `pages/parent/profile.php`: replaced by `/profile` and `/settings`; the GET redirects, the old POSTs answer 303 without acting.
- Messaging `PreferenceController` (own page): its choices moved to Settings, Notifications; GET `messages/preferences` redirects.
- The hard-coded `placeholder/profile.png`: every avatar now comes from `components/avatar` (initials or the stored photo).
- Inert account-menu items: My Profile and Settings are real; Help & Support is shown disabled ("Coming soon").
- `teachingHomeColumns` as the instructor's home: the profile shows the home department, falling back to "Teaches in ..." only when none is set.

Retained and extended:
- `user_profiles` and its rows (byte-identical after migration), `retrieveUserProfile` (extended through `ProfileReader`), `PasswordResetService` (the "Email me a link" path), parent email preferences and message preferences (same data, same behaviour, new home), the `files`/storage conventions for photos.
- Dashboard identity cards keep their layout but use the shared avatar.
