## What and why

<!-- One or two sentences: what changes, and why it is needed. -->

## How to check it

<!-- Steps a reviewer (or future you) can follow to see it working. -->

## Checklist

- [ ] CI is green (the "CI passed" check below)
- [ ] Tests are included, or the reason there are none is given here: <!-- reason -->
- [ ] If this fixes a bug, I added a test that fails without the fix
- [ ] If this changes the database, there is a migration and `php spark migrate --all` works on an empty database
- [ ] No secrets, `.env` values or personal data are in the diff
- [ ] I looked through the diff on this page once before asking for a merge
