# Backups and restoring

## How backups happen

The app does not make dumps inside web requests. The server's scheduler runs

```
php8.5 spark backup:run
```

every few minutes (it exits at once when there is nothing to do). It:

1. takes every backup an administrator asked for with **Back up now**
   (`/admin/backups`, needs `backup.manage` and the password; audited as
   `backup.requested`), and
2. takes the daily backup when none has succeeded in the last 23 hours.

Dumps are `mysqldump | gzip` files named `mentora-YYYYMMDD-HHMMSS.sql.gz` in
`writable/backups` (set `backup.path` in `.env` to put them elsewhere, ideally
off the web server's disk). The newest 14 are kept. The password is passed to
`mysqldump` in the environment, never on the command line. Each attempt is a
row in `backups` (history, size, status, reason when it failed).

The deploy workflow also takes its own pre-deploy dump in `~/backups`; that is
separate from these.

## Alerts

`/admin/backups` shows an alert, and **System status** has a Backups check,
when the latest backup failed, when the last good one is older than the
platform setting `backupAlertHours` (24 by default), or when there never was
one. System administrators are also notified once per problem, by email too
(see `notifications.md`).

## Restoring (manual, on purpose)

There is no restore button: restoring replaces live data and should be a
deliberate act on the server by someone who has read this.

1. **Stop writes.** Put the site in maintenance or stop PHP-FPM for the site.
2. **Take a fresh dump** of the current database first, in case the restore is
   the wrong move: `mysqldump ... | gzip > ~/before-restore.sql.gz`.
3. **Pick the file** from the backup folder (check the date on `/admin/backups`).
4. **Restore** as the site user, reading credentials from the server `.env`:

   ```bash
   cd /home/mentora/htdocs/mentora
   get() { grep -E "^$1[[:space:]]*=" .env | head -1 | sed 's/^[^=]*=[[:space:]]*//' | tr -d "'\""; }
   gunzip -c /path/to/mentora-YYYYMMDD-HHMMSS.sql.gz | MYSQL_PWD="$(get database.default.password)" \
     mysql -h "$(get database.default.hostname)" -u "$(get database.default.username)" "$(get database.default.database)"
   ```

5. **Check:** `php8.5 spark migrate --all` (brings the schema up to the code if
   the dump is older), open `/health` and the login page, sign in as an
   administrator.
6. **Start the site again.**

Uploaded files (`writable/uploads`) are not in the database dump; back them up
with your normal file backup if you need them.

## Not here yet
Offsite copy of the dumps, and encryption of the dump files at rest, are left
to the server's own tooling.
