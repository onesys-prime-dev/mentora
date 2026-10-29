import { describe, it, expect, vi, beforeEach } from "vitest";

const showToast = vi.fn();
vi.mock("../../../resources/js/shared/toast.js", () => ({ showToast: (...a) => showToast(...a) }));

const { installCsrf, csrfExpired } = await import("../../../resources/js/shared/csrf.js");

const EXPIRED = { error: "csrf_expired", message: "This page was open too long. Reload it and try again." };

let original;

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="tok123">';
    showToast.mockReset();
    original = vi.fn().mockResolvedValue(new Response("{}", { status: 200 }));
    window.fetch = original;
    installCsrf(window);
});

const sent = () => new Headers(original.mock.calls.at(-1)?.[1]?.headers ?? {});

describe("csrf", () => {
    it("adds the token to a same-origin POST", async () => {
        await window.fetch("/x", { method: "POST" });

        expect(sent().get("X-CSRF-TOKEN")).toBe("tok123");
    });

    it("adds it to PATCH and DELETE and to a multipart body", async () => {
        const body = new FormData();
        body.append("file", "x");

        await window.fetch("/x", { method: "PATCH", body });
        expect(sent().get("X-CSRF-TOKEN")).toBe("tok123");
        expect(original.mock.calls.at(-1)[1].body).toBe(body);

        await window.fetch("/x", { method: "delete" });
        expect(sent().get("X-CSRF-TOKEN")).toBe("tok123");
    });

    it("leaves GET alone", async () => {
        await window.fetch("/x");

        expect(sent().get("X-CSRF-TOKEN")).toBeNull();
    });

    it("never sends it to another origin", async () => {
        await window.fetch("https://cdn.example.com/a", { method: "POST" });

        expect(sent().get("X-CSRF-TOKEN")).toBeNull();
    });

    it("keeps existing headers", async () => {
        await window.fetch("/x", { method: "POST", headers: { Accept: "application/json" } });

        expect(sent().get("Accept")).toBe("application/json");
        expect(sent().get("X-CSRF-TOKEN")).toBe("tok123");
    });

    it("shows the message when the token expired, and still returns the response", async () => {
        original.mockResolvedValueOnce(new Response(JSON.stringify(EXPIRED), { status: 403, headers: { "Content-Type": "application/json" } }));

        const response = await window.fetch("/x", { method: "POST" });

        expect(response.status).toBe(403);
        expect(showToast).toHaveBeenCalledOnce();
        expect(showToast).toHaveBeenCalledWith(EXPIRED.message, { tone: "danger" });
    });

    it("says nothing for another 403", async () => {
        original.mockResolvedValueOnce(new Response(JSON.stringify({ error: "forbidden" }), { status: 403 }));

        await window.fetch("/x", { method: "POST" });

        expect(showToast).not.toHaveBeenCalled();
    });

    it("accepts null as init, as native fetch does", async () => {
        await window.fetch("/x", null);

        expect(original).toHaveBeenCalledOnce();
    });

    it("says when the toast was already shown", () => {
        expect(csrfExpired({ error: "csrf_expired" })).toBe(true);
        expect(csrfExpired({ error: "reauth_required" })).toBe(false);
        expect(csrfExpired({})).toBe(false);
        expect(csrfExpired(null)).toBe(false);
        expect(csrfExpired(undefined)).toBe(false);
    });

    it("wraps fetch only once", async () => {
        installCsrf(window);

        await window.fetch("/x", { method: "POST" });

        expect(original).toHaveBeenCalledOnce();
    });
});
