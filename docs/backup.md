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
off the web server's disk).

**A backup counts only once it is proven whole** (audit 11.1): mysqldump exited
0, every write to the file succeeded, and the file reads back to the end as
gzip with mysqldump's closing `-- Dump completed` line last. Otherwise it is
**failed**, its file is deleted, and the system administrators are told at once
(urgent, so by email), not at the next nightly run.

**The newest 14 good backups are kept** (11.2): only proven-good ones count, so
a failing run never pushes a good one out, and a file no good backup owns (left
by a crashed run) is removed after the next good one. The password is passed to
`mysqldump` in the environment, never on the command line. Each attempt is a
row in `backups` (history, size, status, reason when it failed).

The deploy workflow also takes its own pre-deploy dump in `~/backups`; that is
separate from these.

## The off-server copy (required before the first real institution)

After each good backup, `backup:run` runs the command in `backup.offsiteCommand`
(in `.env`) with two arguments, the dump's path and the uploads folder
(`writable/uploads`), and records the result on the backup row: the **Off-site
copy** column on `/admin/backups` reads Copied, Failed (with why) or "—" (no
command set, or the backup was not good). A failed copy keeps the backup good and
tells the system administrators at once (urgent). The command gets two hours.
The database password is masked in anything it prints.

Production only: leave `backup.offsiteCommand` unset on staging (audit U3a). What
the command does, and where it sends the copy, is set up on the server: see
`server-setup.md`, "Off-server backup copy", and its go-live check.

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

Uploaded files (`writable/uploads`) are not in the database dump. The off-server
copy holds them: restore them from there (`server-setup.md`).

## Not here yet
A test restore of each backup (11.3: the read-back check is enough until real
institutions arrive), and encryption of the dump files on the server itself.
