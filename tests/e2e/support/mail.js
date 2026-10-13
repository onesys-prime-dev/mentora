// Reads the emails the app "sent" during a test run. The app is pointed at
// tests/e2e/support/mail-sink.php (see app/Config/Email.php), which files each
// message in writable/e2e-mail; nothing is ever really sent.
import { mkdirSync, readdirSync, readFileSync, rmSync } from "node:fs";
import { fileURLToPath } from "node:url";

const dir = fileURLToPath(new URL("../../../writable/e2e-mail/", import.meta.url));

/** Forgets every message so far, so a case only sees the mail it caused. */
export function clearMail() {
    rmSync(dir, { recursive: true, force: true });
    mkdirSync(dir, { recursive: true });
}

/** All messages so far, newest first. */
export function messages() {
    try {
        return readdirSync(dir)
            .filter((name) => name.endsWith(".eml"))
            .sort()
            .reverse()
            .map((name) => readFileSync(`${dir}${name}`, "utf8"));
    } catch {
        return [];
    }
}

/**
 * Waits for a message to `recipient` and returns what `pattern` captured in it.
 * The mailer may fold long lines, so soft line breaks are removed first.
 */
export async function waitForMailMatch(recipient, pattern, { timeout = 15_000 } = {}) {
    const deadline = Date.now() + timeout;

    do {
        for (const message of messages()) {
            if (!message.toLowerCase().includes(recipient.toLowerCase())) {
                continue;
            }

            const text = message.replace(/=\r?\n/g, "");
            const found = text.match(pattern);

            if (found) {
                return found[1] ?? found[0];
            }
        }

        await new Promise((resolve) => setTimeout(resolve, 250));
    } while (Date.now() < deadline);

    throw new Error(`No email to ${recipient} matching ${pattern} arrived within ${timeout} ms.`);
}
