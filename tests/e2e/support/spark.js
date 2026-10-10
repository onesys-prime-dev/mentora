// Runs one of the app's spark commands against the end-to-end database, for the
// things the server's scheduler does in real life (the subscription sync).
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const root = fileURLToPath(new URL("../../../", import.meta.url));

export function spark(...args) {
    const result = spawnSync("php", ["-d", "variables_order=EGPCS", "spark", ...args], {
        cwd: root,
        encoding: "utf8",
        env: {
            ...process.env,
            CI_ENVIRONMENT: "production",
            E2E_DATABASE: process.env.E2E_DATABASE ?? "mentora_e2e",
            E2E_DB_HOST: process.env.E2E_DB_HOST ?? "127.0.0.1",
            E2E_MAIL_SINK: `${root}tests/e2e/support/mail-sink.php`,
        },
    });

    if (result.error || result.status !== 0) {
        throw new Error(`php spark ${args.join(" ")} failed:\n${result.stdout ?? ""}${result.stderr ?? ""}`);
    }

    return result.stdout;
}
