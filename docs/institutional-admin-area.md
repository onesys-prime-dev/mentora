# Institutional administrator area

The institutional administrator runs one institution inside Mentora. This page covers the foundation; each later branch adds its own section.

## Scope

- The institution is **decided on the server** from the signed-in person (`InstitutionScope`, built on `PlanLimits::institutionOf`), never from an id in the address or a form.
- Every id a page is handed (department, sub-department, program, course, offering, term, person) is checked with `InstitutionScope::require()` before anything is read or written. A foreign or missing id answers **404**, not 403, so the page does not confirm it exists elsewhere.
- A person removed from the platform (soft-deleted) is not owned.

## Routes

| Path | Does | Read-only institution |
| --- | --- | --- |
| `/institution`, `/institution/dashboard` | Home: snapshot, plan and usage | opens |
| `/institution/export` (+ download) | Data export | works (deliberately outside the `readonly` filter) |

Everything that **writes** goes in the nested `readonly` group in `app/Config/Routes.php`. `InstitutionHomeTest` fails if a write route is added outside it.

## Permissions

Added to `Config\AuthGroups`, granted to `institutional_admin` by default, editable by the system administrator in the roles matrix: `structure.manage`, `terms.manage`, `offerings.manage`, `enrolments.manage`, `people.view`, `people.manage`, `reports.view`, `institution.settings`, `institution.audit`, `institution.impersonate`, `sis.run`.

## Shell

- Same app shell as the student and instructor areas (`layouts/app`, the shared components in `app/Views/components`).
- The Apps menu lists the institution's tools (`global_apps('institutional_admin')`); tiles without a page yet are inert and say "Coming soon".
- `role_home()` maps a role to its area (`institutional_admin` -> `institution`) for the header's logo link.

## Home

Snapshot (people by role, courses, this term's offerings and enrolments) from `InstitutionSnapshot`, and plan usage from `PlanLimits::report()`.

## Confirming the password

Dangerous actions sit behind the `reauth` route filter, the same one the system administrator uses.

- It lets a request through only while the person typed their password within the last 5 minutes (`ReauthService::WINDOW_SECONDS`).
- Otherwise a normal request is sent to the confirm page (`/reauth`; the system administrator's stays `/admin/reauth`) and, once confirmed, returns to the page it came from. The return address can only be inside an administrative area (`admin/`, `institution/`), never another site.
- A script (the on-page dialog) gets `403 {"error":"reauth_required"}` instead. `resources/js/shared/dialog.js` then opens the page's confirm dialog (`layouts/partials/reauth-dialog.php`, opt in with `'reauthDialog' => true`), posts the password as JSON, and repeats the original action with what was typed still in place. A closed dialog repeats nothing and says nothing was saved.
- Five wrong passwords in a row end the session.

To guard a route: `['filter' => ['role:institutional_admin', 'permission:...', 'reauth']]`.
