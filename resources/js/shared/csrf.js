/**
 * The CSRF token on every change a script makes (audit finding 4).
 *
 * The page carries the session's token in <meta name="csrf-token">. Rather
 * than each module adding it, fetch is wrapped once: a POST, PUT, PATCH or
 * DELETE to this site gets the X-CSRF-TOKEN header, so every script today and
 * later is covered, uploads (FormData) included. Other sites never see it.
 *
 * When the server says the token expired (403, error "csrf_expired"), the
 * plain message is shown as a toast. The page is left as it is, so whatever
 * was typed is still there; the response still goes back to the caller.
 */

import { showToast } from "./toast.js";

export { csrfExpired } from "./csrf-expired.js";

const SAFE = ["GET", "HEAD", "OPTIONS"];

export function csrfToken(doc = document) {
    return doc.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ?? null;
}

export function installCsrf(win = window) {
    if (win.fetch.__csrf) {
        return;
    }

    const original = win.fetch;

    const wrapped = async (input, init) => {
        // Native fetch takes null (or nothing) for the options.
        init = init ?? {};

        const method = (init.method ?? (input instanceof Request ? input.method : "GET")).toUpperCase();
        const url = new URL(input instanceof Request ? input.url : String(input), win.location.href);
        const token = csrfToken(win.document);

        if (!SAFE.includes(method) && url.origin === win.location.origin && token) {
            const headers = new Headers(init.headers ?? (input instanceof Request ? input.headers : undefined));
            headers.set("X-CSRF-TOKEN", token);
            init = { ...init, headers };
        }

        const response = await original(input, init);

        if (response.status === 403) {
            const body = await response.clone().json().catch(() => null);

            if (body?.error === "csrf_expired") {
                showToast(body.message, { tone: "danger" });
            }
        }

        return response;
    };

    wrapped.__csrf = true;
    win.fetch = wrapped;
}

installCsrf();
