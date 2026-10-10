// Prepares the end-to-end database and serves the app for Playwright
// (playwright.config.js runs this as its webServer command).
//
// Order matters, because this resets and reseeds a database:
//   1. refuse any database whose name does not contain "e2e" or "test";
//   2. drop and recreate that database (reset-database.php, same guard);
//   3. ask the app which database it really connects to, and stop unless it is
//      this one (a wrong .env must never be able to point the reseed elsewhere);
//   4. run the app's migrations and the demo seeder;
//   5. serve the app, built assets included, as production would.
//
// Needs `npm run build` first (the pages load public/build) and a database
// server (MySQL or MariaDB) the E2E_DB_* settings can reach.
import { spawn, spawnSync } from "node:child_process";
import { existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { adminAccount } from "./sign-in.js";

const root = fileURLToPath(new URL("../../../", import.meta.url));

const port = process.env.E2E_PORT ?? "8089";
const database = process.env.E2E_DATABASE ?? "mentora_e2e";
const dbHost = process.env.E2E_DB_HOST ?? "127.0.0.1";
const baseUrl = `http://127.0.0.1:${port}`;

// variables_order=EGPCS lets the process environment (CI_ENVIRONMENT) win over .env.
const php = ["-d", "variables_order=EGPCS"];
const env = {
    ...process.env,
    CI_ENVIRONMENT: "production",
    E2E_DATABASE: database,
    E2E_DB_HOST: dbHost,
    E2E_BASE_URL: baseUrl,
    // The app files every email it "sends" in writable/e2e-mail (app/Config/Email.php),
    // where the cases read the administrator's sign-in code and invitation links.
    E2E_MAIL_SINK: `${root}tests/e2e/support/mail-sink.php`,
};

const say = (message) => console.log(`[e2e] ${message}`);

function fail(message) {
    console.error(`[e2e] ${message}`);
    process.exit(1);
}

// Runs a PHP command quietly and shows its output only if it fails.
function run(label, args, options = {}) {
    say(label);
    const result = spawnSync("php", args, { cwd: root, env, encoding: "utf8", ...options });

    if (result.error || result.status !== 0) {
        console.error(`${result.stdout ?? ""}${result.stderr ?? ""}`);
        fail(`Failed: ${label}${result.error ? ` (${result.error.message})` : ""}`);
    }
}

// 1. The name guard.
if (!/^[A-Za-z0-9_]+$/.test(database) || !/e2e|test/i.test(database)) {
    fail(`Refusing to use '${database}': E2E_DATABASE must be a plain name containing 'e2e' or 'test'.`);
}

if (!existsSync(`${root}public/build/.vite/manifest.json`)) {
    fail("public/build is missing. Run `npm run build` first.");
}

// 2. An empty database of our own.
run(`resetting database '${database}' on ${dbHost}`, ["tests/e2e/support/reset-database.php"]);

// 3. Does the app really connect where we think? (read-only: db:table prints
// the connection first, then waits for a table choice; closed input ends it.)
say("checking which database the app connects to");
const probe = spawnSync("php", [...php, "spark", "db:table"], {
    cwd: root,
    env,
    input: "",
    encoding: "utf8",
    timeout: 60_000,
});
const rows = String(probe.stdout)
    .split(/\r?\n/)
    .filter((line) => line.startsWith("|"))
    .map((line) => line.split("|").slice(1, -1).map((cell) => cell.trim()));
const connection = rows.length >= 2 ? Object.fromEntries(rows[0].map((name, i) => [name, rows[1][i]])) : null;

if (!connection || connection.database !== database || connection.hostname !== dbHost) {
    fail(
        `The app connects to '${connection?.database}' on '${connection?.hostname}', not '${database}' on '${dbHost}'. ` +
            "Nothing was migrated or seeded."
    );
}

say(`the app connects to '${connection.database}' on ${connection.hostname}: ok`);

// 4. Schema and demo data (dates in the seed are relative to today, so every run reseeds).
run("migrating", [...php, "spark", "migrate", "--all"]);
run("seeding the demo data", [...php, "spark", "db:seed", "DatabaseSeeder"]);

// The system administrator the cases sign in as. The demo seeder refuses to run in
// production (its password is published), so this creates the account the way a
// real one is made, with the development rules (a placeholder address is fine here;
// the code is "emailed" to the sink above).
run("creating the end-to-end administrator", [...php, "spark", "admin:create", adminAccount.email, "admin.e2e", adminAccount.password], {
    env: { ...env, CI_ENVIRONMENT: "development" },
});

// 5. Serve.
say(`serving ${baseUrl}`);
const server = spawn(
    "php",
    [...php, "-S", `127.0.0.1:${port}`, "-t", "public", "vendor/codeigniter4/framework/system/rewrite.php"],
    // The server's request log is noisy; E2E_VERBOSE=1 shows it. PHP errors still reach writable/logs.
    { cwd: root, env, stdio: ["ignore", "inherit", process.env.E2E_VERBOSE ? "inherit" : "ignore"] }
);

for (const signal of ["SIGINT", "SIGTERM"]) {
    process.on(signal, () => server.kill());
}

server.on("exit", (code) => process.exit(code ?? 0));
