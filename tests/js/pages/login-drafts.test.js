import { afterEach, describe, expect, it, vi } from "vitest";

/** The login page is where a sign-out lands: unsent Messages drafts from this browser go, other storage stays. */
describe("the login page", () => {
    afterEach(() => {
        localStorage.clear();
    });

    it("drops every saved Messages draft and keeps the rest", async () => {
        localStorage.setItem("messages:draft:7:12:0", "Hi there");
        localStorage.setItem("messages:draft:9:3:5", "About your child");
        localStorage.setItem("theme", "dark");

        vi.resetModules();
        await import("../../../resources/js/pages/login/login.js");

        expect(localStorage.getItem("messages:draft:7:12:0")).toBeNull();
        expect(localStorage.getItem("messages:draft:9:3:5")).toBeNull();
        expect(localStorage.getItem("theme")).toBe("dark");
    });
});
