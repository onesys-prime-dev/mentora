# General settings

`/admin/settings/general` (view: `settings.view`; save: `settings.manage` and
the password again; every changed value is audited).

| Setting | Effect |
|---|---|
| System name | Shown in the admin header (and where the platform names itself). Default "Mentora". |
| Support contact | An email address or a line of text; shown on the maintenance page. |
| Timezone | An IANA name (for example `Asia/Manila`). Applied at the start of every request, so times shown and calculated use it. Default UTC. |
| Maintenance mode | Everyone but system administrators is turned away with a 503 and the message. |
| Maintenance message | What they see. |
| Announcement | One line shown at the top of every page for everyone (as plain text). People can hide it for themselves; it shows again when the text changes. |
| Warn / grace / read-only days | The default subscription timeline for institutions with no timeline of their own. |
| Backup alert age | Hours before the last good backup counts as stale. |

## Maintenance mode

- Administrators carry on, including one viewing as someone, so they can switch
  it off. The sign-in pages, sign-out, `/health`, invitation and password-reset
  links stay open.
- Others get HTTP 503 with `Retry-After: 300` (a script gets a JSON error
  `maintenance`). Nothing is changed or signed out: turning it off lets
  everyone straight back in.
- If settings cannot be read (database down) the filter lets the request
  through rather than locking everyone out.

## Not here yet
- **Logo** upload: needs file storage and a place in the layouts; left out.
- **Locale**: the app has one language; no setting yet.
- The announcement is one line of text, not formatted.
