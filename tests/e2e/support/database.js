// Runs one SQL statement on the end-to-end database (support/sql.php), for
// "time passes" in a case. Never for anything the app lets a person do.
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const script = fileURLToPath(new URL("./sql.php", import.meta.url));

export function runSql(statement) {
    const result = spawnSync("php", [script, statement], {
        encoding: "utf8",
        env: {
            ...process.env,
            E2E_DATABASE: process.env.E2E_DATABASE ?? "mentora_e2e",
            E2E_DB_HOST: process.env.E2E_DB_HOST ?? "127.0.0.1",
        },
    });

    if (result.error || result.status !== 0) {
        throw new Error(`${result.stdout ?? ""}${result.stderr ?? ""}`);
    }

    return result.stdout;
}
