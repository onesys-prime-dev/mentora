# Audit Plan 1 — CSRF Everywhere Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every request that changes data needs a valid CSRF token (finding 4: 4.1–4.4), with a plain message when a page was open too long, and sign-out only by a button.

**Architecture:** Turn on CodeIgniter's `csrf` filter globally through a small subclass that answers a failed check the Mentora way (redirect back with the typed input, or a JSON 403 for scripts). One token per session, renewed at sign-in. Browser scripts get the token from a `<meta>` tag, added to every non-GET same-origin `fetch` by one shared wrapper. The feature-test base sends a valid token by default so the existing suite keeps testing what it tested.

**Tech Stack:** CodeIgniter 4.7 (`CodeIgniter\Filters\CSRF`, `Config\Security`), Shield 1.x (routes, `login` event), PHPUnit 10 (`RouteTestCase`), Vitest + jsdom, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-23-audit-fixes-design.md` § Finding 4 (and F4); behaviour from `docs/audit-2026-10-23-decisions.md` 4.1–4.4.

## Global Constraints

- Branch `fix/csrf` (already created from `docs/audit-2026-10-23` at b737483). No commits by Claude: each commit step stages the files and states the message; the user commits. Messages end `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- The one exception to the token is `internal/ai/*` (4.1). Plan 6 will add `internal/scheduler-check` to the same list; nothing else.
- Expired-token copy, exactly: `This page was open too long. Reload it and try again.`
- JSON refusal body, exactly: `{"error":"csrf_expired","message":"This page was open too long. Reload it and try again."}` with status 403.
- Token per session: `Config\Security::$regenerate = false`; token name and header keep their current values (`csrf_test_name`, `X-CSRF-TOKEN`); `$csrfProtection` stays `'session'`.
- Sign-out: POST only. A GET to `/logout` no longer signs anyone out.
- UI matches the existing design language: the sign-out button looks exactly like today's "Log out" menu item; the two-factor "Sign in as someone else" stays a text-styled link look.
- Known local-only failures stay: six `AiWorkerEndpointsTest` tests (finding 14, plan 7).

## Review Focus

- A form whose session expired is posted: the person sees the message and their typed text is still in the form (not a 403 page, not a blank form).
- Two tabs, or a page that fires two fetches at once: both writes succeed (no per-request token rotation).
- A `fetch` to another origin (e.g. a CDN or external API) never receives the token header.
- After signing out and back in, a page left open from the old session can't post with the old token.
- A `multipart/form-data` upload made through `fetch` (announcement attachments, submissions) carries the token in the header, since it has no hidden field.

---

### Task 1: The CSRF filter, switched on, and a test base that sends a token

**Files:**
- Create: `app/Filters/CsrfFilter.php`
- Modify: `app/Config/Filters.php:44` (alias), `app/Config/Filters.php:107-109` (globals)
- Modify: `app/Config/Security.php:74`
- Modify: `tests/_support/RouteTestCase.php`
- Modify: `tests/unit/ComponentViewsTest.php:263`
- Test: `tests/feature/CsrfProtectionTest.php`

**Interfaces:**
- Produces: `App\Filters\CsrfFilter extends CodeIgniter\Filters\CSRF` with `public const MESSAGE = 'This page was open too long. Reload it and try again.'`
- Produces: `RouteTestCase::withoutCsrfToken(): static` (the next requests send no token); by default every POST/PUT/PATCH/DELETE through `call()` carries a valid `X-CSRF-TOKEN` for the request's session.

- [ ] **Step 1: Write the failing tests** in `tests/feature/CsrfProtectionTest.php` (`final class CsrfProtectionTest extends RouteTestCase`). In `setUp`, mount `['post', 'csrf-probe', static fn () => 'ok']` with `withRoutes`, and sign in as `PeopleFactory::person('student')`.

```php
public function testAFormPostWithoutATokenIsSentBackWithTheMessageAndTheTypedInput(): void
{
    $result = $this->withoutCsrfToken()->post('csrf-probe', ['title' => 'My draft']);

    $result->assertRedirect();
    $this->assertSame(CsrfFilter::MESSAGE, session('error'));
    $this->assertSame('My draft', session('_ci_old_input')['post']['title'] ?? null);
}

public function testAScriptPostWithoutATokenGetsA403WithTheMessage(): void
{
    $result = $this->withoutCsrfToken()
        ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post('csrf-probe');

    $result->assertStatus(403);
    $this->assertSame(['error' => 'csrf_expired', 'message' => CsrfFilter::MESSAGE], json_decode($result->getJSON(), true));
}

public function testAPostWithTheTokenGoesThrough(): void
{
    $this->post('csrf-probe')->assertOK()->assertSee('ok');
}

public function testTheSameTokenWorksForASecondRequest(): void
{
    $this->step('post', 'csrf-probe')->assertOK();
    $this->step('post', 'csrf-probe')->assertOK();
}

public function testCsrfIsOnForEveryRequestExceptTheWorker(): void
{
    $before = config(\Config\Filters::class)->globals['before'];

    $this->assertSame(['except' => ['internal/ai/*']], $before['csrf'] ?? null);
}

public function testTheWorkerInterfaceIsNotAskedForAToken(): void
{
    $result = $this->withoutCsrfToken()->withHeaders(['Accept' => 'application/json'])->post('internal/ai/claim');

    // The worker's own guard may refuse it (no secret, not loopback), but never the CSRF check.
    $this->assertStringNotContainsString('csrf_expired', (string) $result->getBody());
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/feature/CsrfProtectionTest.php`
Expected: FAIL: `withoutCsrfToken` undefined, then (once it exists) the first two tests fail because nothing refuses the post.

- [ ] **Step 3: Implement `CsrfFilter::before(RequestInterface $request, $arguments = null)`**

Call the parent inside `try`. On `SecurityException`: if `$request->isAJAX()` or its `Accept` contains `application/json`, return `service('response')->setStatusCode(403)->setJSON(['error' => 'csrf_expired', 'message' => self::MESSAGE])`. Otherwise return `redirect()->back()->withInput()->with('error', self::MESSAGE)`. This answers the same way in every environment (it no longer depends on `Security::$redirect`). Mirror the JSON-or-redirect test used in `ReadOnlyFilter.php`.

- [ ] **Step 4: Switch it on**
  - `Filters::$aliases['csrf'] = CsrfFilter::class` (replacing `CSRF::class`, `Filters.php:44`).
  - In `Filters::$globals['before']`, replace the comment `// 'csrf',` with `'csrf' => ['except' => ['internal/ai/*']],`, placed first, before `maintenance`. The comment says why: an expired page is answered before anything else looks at the request.
  - `Security::$regenerate = false`, with a one-line comment: one token per session, so a second tab and parallel fetches keep working.

- [ ] **Step 5: Give `RouteTestCase` a default token**

Override `call(string $method, string $path, ?array $params = null)`. For POST, PUT, PATCH or DELETE, unless `withoutCsrfToken()` was called, put a token into the session the request will use (`$this->session['csrf_test_name']`, merged so `step()`'s carried session survives), send it as `X-CSRF-TOKEN`, then call `parent::call`. Reset the shared `security` service (`Services::resetSingle('security')`) before each call, so it reads the session it is given. `withoutCsrfToken()` sets a flag that `tearDown` clears.

- [ ] **Step 6: Run the new tests**

Run: `vendor/bin/phpunit tests/feature/CsrfProtectionTest.php`
Expected: PASS (6 tests).

- [ ] **Step 7: Make the old vacuous assert real**

In `tests/unit/ComponentViewsTest.php:263`, assert against `$html` alone: `$this->assertStringContainsString('name="csrf_test_name"', $html, 'a CSRF field is rendered by csrf_field()');`.

- [ ] **Step 8: Run the whole PHP suite**

Run: `composer test`
Expected: PASS apart from the six known `AiWorkerEndpointsTest` failures. Any other failure is a test that posts outside `RouteTestCase::call` or a real form missing its token. Fix it at its source, and add a `Ruling:` line to the ledger if it isn't one of those two.

- [ ] **Step 9: Stage**

```bash
git add app/Filters/CsrfFilter.php app/Config/Filters.php app/Config/Security.php tests/_support/RouteTestCase.php tests/unit/ComponentViewsTest.php tests/feature/CsrfProtectionTest.php
```
Message: `fix: every change needs a CSRF token, and an expired page says so plainly`

---

### Task 2: A new token at sign-in

**Files:**
- Modify: `app/Config/Events.php:61` (next to the existing `login` listener)
- Test: `tests/feature/CsrfProtectionTest.php`

**Interfaces:**
- Consumes: Task 1's `CsrfFilter`, `RouteTestCase` token.

- [ ] **Step 1: Write the failing test**

```php
public function testSigningInGivesANewToken(): void
{
    auth()->logout();
    $before = service('security')->getHash();

    auth()->login(PeopleFactory::person('student'));

    $this->assertNotSame($before, service('security')->getHash());
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter testSigningInGivesANewToken tests/feature/CsrfProtectionTest.php`
Expected: FAIL. The hashes are equal, because a session regenerate keeps the session data that holds the token.

- [ ] **Step 3: Implement**

Add `Events::on('login', static fn () => service('security')->generateHash());`, with a one-line comment that the token lives in the session, which survives the session id change at sign-in.

- [ ] **Step 4: Run the CSRF tests**

Run: `vendor/bin/phpunit tests/feature/CsrfProtectionTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Stage**

```bash
git add app/Config/Events.php tests/feature/CsrfProtectionTest.php
```
Message: `fix: a new CSRF token at every sign-in`

---

### Task 3: Scripts send the token, and say so when it expired

**Files:**
- Modify: `app/Views/layouts/partials/head.php` (the one head partial for `app`, `admin` and `auth` layouts)
- Create: `resources/js/shared/csrf.js`
- Modify: `resources/js/app.js` (import first, before the other shared modules)
- Test: `tests/js/shared/csrf.test.js`

**Interfaces:**
- Consumes: Task 1's JSON body `{error: "csrf_expired", message}`.
- Produces: `installCsrf(win = window): void`, which wraps `win.fetch` once (it is idempotent); `csrfToken(doc = document): string | null`.

- [ ] **Step 1: Write the failing tests** (Vitest, jsdom)

```js
import { describe, it, expect, vi, beforeEach } from "vitest";
import { installCsrf } from "../../../resources/js/shared/csrf.js";

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="tok123">';
    window.fetch = vi.fn().mockResolvedValue(new Response("{}", { status: 200 }));
    delete window.__csrfInstalled;
    installCsrf(window);
});

const sentHeaders = () => new Headers(window.fetch.mock.calls.at(-1)?.[1]?.headers ?? {});
```

Write these tests against the wrapped `fetch` (spy on the original before wrapping):
  - `"adds the token to a same-origin POST"`: `fetch("/x", {method: "POST"})` sends header `X-CSRF-TOKEN: tok123`.
  - `"adds it to PATCH and DELETE and to a multipart body"`: a `FormData` body keeps its body and gains the header.
  - `"leaves GET alone"`: no header.
  - `"never sends it to another origin"`: `fetch("https://cdn.example.com/a", {method: "POST"})` has no header.
  - `"shows the message when the token expired"`: the original resolves `403` with the JSON body from Global Constraints. `showToast` (mocked `./toast.js`) is called once with that message and `{ tone: "danger" }`, and the response is still returned to the caller.
  - `"keeps existing headers"`: `{headers: {Accept: "application/json"}}` keeps `Accept`.

- [ ] **Step 2: Run them to verify they fail**

Run: `npx vitest run tests/js/shared/csrf.test.js`
Expected: FAIL: module not found.

- [ ] **Step 3: Implement `resources/js/shared/csrf.js`**

`csrfToken()` reads `meta[name="csrf-token"]`. `installCsrf()` keeps the original `fetch` and replaces it with one that, for a non-GET/HEAD request whose URL resolves to `location.origin`, sets `X-CSRF-TOKEN` on a `Headers` built from the given headers. After the response, if it is `403` and its JSON clone says `error === "csrf_expired"`, it calls `showToast(message, { tone: "danger" })`. It returns the response unchanged. The page and the form are left as they are, so typed text stays. A module comment says why it wraps `fetch`: every current and future script is covered, including the 13 that call `fetch` today.

- [ ] **Step 4: Wire it**
  - In `head.php`, before the `vite()` line: `<meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">`.
  - In `app.js`, add `import { installCsrf } from "./shared/csrf";` and `installCsrf();` as the first statement after the CSS import.

- [ ] **Step 5: Run the JS suite**

Run: `npm test`
Expected: PASS. The Vitest timeout in `calendar-read-only.test.js` under parallel load is a known flake; re-run that file alone to confirm.

- [ ] **Step 6: Stage**

```bash
git add app/Views/layouts/partials/head.php resources/js/shared/csrf.js resources/js/app.js tests/js/shared/csrf.test.js
```
Message: `fix: scripts send the CSRF token and say plainly when a page was open too long`

---

### Task 4: Sign-out by button only

**Files:**
- Modify: `app/Config/Routes.php:9`
- Modify: `app/Views/layouts/partials/header.php:296-304`
- Modify: `app/Views/pages/auth/two-factor-show.php:54`, `app/Views/pages/auth/two-factor-verify.php:61`
- Modify: `tests/e2e/auth-login.spec.js:56`
- Test: `tests/feature/CsrfProtectionTest.php`

**Interfaces:**
- Consumes: Task 1's token in forms (`csrf_field()`) and in tests.
- Produces: route `POST logout` → `\CodeIgniter\Shield\Controllers\LoginController::logoutAction`, named `logout`.

- [ ] **Step 1: Write the failing tests**

```php
public function testAPlainLinkCannotSignAnyoneOut(): void
{
    $this->get('logout');

    $this->assertTrue(auth()->loggedIn());
}

public function testTheSignOutButtonSignsOut(): void
{
    $this->post('logout')->assertRedirectTo(site_url('login'));

    $this->assertFalse(auth()->loggedIn());
}

public function testTheMenuSignsOutWithAFormNotALink(): void
{
    $html = (string) $this->get('student/dashboard')->getBody();

    $this->assertMatchesRegularExpression('#<form[^>]+action="[^"]*/logout"[^>]+method="post"#i', $html);
    $this->assertStringNotContainsString('href="' . site_url('/logout') . '"', $html);
}
```

(If the student dashboard needs enrolment fixtures to render, use the page the existing header tests use.)

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter "SignOut|PlainLink|SignsOutWithAForm" tests/feature/CsrfProtectionTest.php`
Expected: FAIL. GET `logout` signs out, and the menu has the link.

- [ ] **Step 3: Implement**
  - `Routes.php:9`: `service('auth')->routes($routes, ['except' => ['logout']]);` then `$routes->post('logout', '\CodeIgniter\Shield\Controllers\LoginController::logoutAction', ['as' => 'logout']);`
  - `header.php`: replace the `<a href=".../logout">` with `<form action="<?= site_url('logout') ?>" method="post">` containing `csrf_field()` and a `<button type="submit">` with the same classes, icon and "Log out" text, full width and left-aligned so it looks unchanged.
  - The two two-factor pages: the same form, with a button styled as the current text link (`text-blue-800 font-medium hover:underline`, no button chrome).

- [ ] **Step 4: Run the PHP tests**

Run: `vendor/bin/phpunit tests/feature/CsrfProtectionTest.php`
Expected: PASS (10 tests).

- [ ] **Step 5: Update the e2e sign-out step**

`tests/e2e/auth-login.spec.js:56`: `getByRole("button", { name: "Log out" })`.

- [ ] **Step 6: Run the whole stack in a browser**

Run: `npm run e2e`
Expected: PASS. Each journey posts real forms and fetches with tokens; a failure means a form or script without the token. Fix it at its source and ledger it.

- [ ] **Step 7: Stage**

```bash
git add app/Config/Routes.php app/Views/layouts/partials/header.php app/Views/pages/auth/two-factor-show.php app/Views/pages/auth/two-factor-verify.php tests/e2e/auth-login.spec.js tests/feature/CsrfProtectionTest.php
```
Message: `fix: sign-out works only through a button`

---

### Task 5: Docs and the full check

**Files:**
- Modify: `docs/audit-2026-10-23-decisions.md` (no change to decisions; only if a ruling changed one: add it under "Added during the design")
- Modify: the security section of the docs that describes request protection, if one exists (`grep -ril csrf docs`); otherwise none.

- [ ] **Step 1: Run every suite**

Run: `composer test`, then `npm test`, then `npm run e2e`
Expected: all PASS, apart from the six known local `AiWorkerEndpointsTest` failures.

- [ ] **Step 2: Stage and give the final list**

Stage any doc change. Hand the user the full `git add` list for the branch, plus the commit messages from Tasks 1–4.
