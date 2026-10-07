import { defineConfig, devices } from "@playwright/test";

// Browser end-to-end tests (see docs/test-cases/ for the cases, docs/testing.md
// for how to run them). tests/e2e/support/serve.js resets a database of its own
// (default mentora_e2e), seeds the demo data and serves the app on this port;
// it is the only thing that starts the server.
const port = process.env.E2E_PORT ?? "8089";
const baseURL = `http://127.0.0.1:${port}`;

export default defineConfig({
    testDir: "tests/e2e",
    testMatch: "**/*.spec.js",

    // One database, and the cases consume demo data: tests run one at a time.
    workers: 1,
    fullyParallel: false,

    // No retries: the tests use up demo data (completing an item, submitting work), so a
    // retry would start from a changed database and fail for a different, confusing reason.
    retries: 0,

    // Each case is a whole journey on a single-threaded PHP server; a CI runner is slower than a laptop.
    timeout: process.env.CI ? 90_000 : 30_000,
    reporter: process.env.CI ? [["list"], ["html", { open: "never" }]] : "list",

    use: {
        baseURL,
        trace: "retain-on-failure",
        screenshot: "only-on-failure",
    },

    projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],

    webServer: {
        command: "node tests/e2e/support/serve.js",
        // /health answers 200 only when the app can reach its (freshly seeded) database.
        url: `${baseURL}/health`,
        reuseExistingServer: false,
        timeout: 180_000,
        stdout: "pipe",
        stderr: "pipe",
    },
});
