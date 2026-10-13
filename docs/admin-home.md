# The administrator's home and menu

`/admin/dashboard` is the landing page after sign-in.

## Home

| Section | What it shows |
|---|---|
| Needs attention | Subscriptions ending soon, in grace, read-only or expired; institutions whose seats, storage or AI use is nearly full or full; a failed, stale or missing backup; a database, storage, disk or scheduled-job problem. Urgent first. Each has a link to where it is dealt with. |
| Snapshot | Institutions by status (pending, active, read-only, past), active users, and people active today. |
| Recent activity | The latest eight audit entries, with a link to the full log. |
| Shortcuts | Add an institution; record a renewal (opens the institutions list, then the institution's Subscription tab). |

It uses the same calculations as System status, Backups and the subscription
pages, so the home page never disagrees with them. The question-builder (AI)
service is not probed here (it is a network call); it stays on System status.

## Menu

Every admin page has the same grouped menu, with the current page marked:

- **Customers**: Institutions, Plans, Users
- **Platform**: Feature flags, Roles and permissions
- **Operations**: System status, Backups, Audit log, Archive
- **Settings**: General, Security

Integrations joins Platform when the SIS connector arrives.

## Not here yet
- "Failed sync runs" in Needs attention arrives with the SIS branches.
- Notifications are in the header link and on `/notifications`, not on the home page.
