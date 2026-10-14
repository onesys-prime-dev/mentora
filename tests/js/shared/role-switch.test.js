import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const load = async () => {
    vi.resetModules();
    await import("../../../resources/js/shared/role-switch.js");
};

afterEach(() => {
    vi.restoreAllMocks();
    delete window.__roleSwitchNavigate;
    document.body.innerHTML = "";
    window.localStorage.clear();
    window.sessionStorage.clear();
});

describe("role switch", () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div data-role-switch data-role-switch-url="/role/switch">
                <button data-role-switch-button data-role="chairperson" disabled aria-current="true">Chairperson</button>
                <button data-role-switch-button data-role="instructor">Instructor</button>
            </div>`;
    });

    it("switches roles, clears browser state, and navigates to the server redirect", async () => {
        const fetch = vi.spyOn(window, "fetch").mockResolvedValue({
            ok: true,
            json: async () => ({ redirect: "/instructor/dashboard" }),
        });
        const navigate = vi.fn();
        window.__roleSwitchNavigate = navigate;

        window.localStorage.setItem("stale", "value");
        window.sessionStorage.setItem("stale", "value");

        await load();
        document.querySelector('[data-role="instructor"]').click();
        await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith("/instructor/dashboard"));

        expect(fetch).toHaveBeenCalledWith("/role/switch", expect.objectContaining({
            method: "POST",
        }));
        expect(fetch.mock.calls[0][1].body.toString()).toBe("role=instructor");
        expect(window.localStorage.getItem("stale")).toBeNull();
        expect(window.sessionStorage.getItem("stale")).toBeNull();
    });

    it("switches to the dean role and follows the dean redirect", async () => {
        document.body.innerHTML = `
            <div data-role-switch data-role-switch-url="/role/switch">
                <button data-role-switch-button data-role="dean">Dean</button>
                <button data-role-switch-button data-role="instructor" disabled aria-current="true">Instructor</button>
            </div>`;

        const fetch = vi.spyOn(window, "fetch").mockResolvedValue({
            ok: true,
            json: async () => ({ redirect: "/dean/dashboard" }),
        });
        const navigate = vi.fn();
        window.__roleSwitchNavigate = navigate;

        await load();
        document.querySelector('[data-role="dean"]').click();
        await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith("/dean/dashboard"));

        expect(fetch.mock.calls[0][1].body.toString()).toBe("role=dean");
        expect(document.querySelector('[data-role="instructor"]').disabled).toBe(true);
    });

    it("does not render behavior when no switcher exists", async () => {
        document.body.innerHTML = "<main>dashboard</main>";

        await expect(load()).resolves.toBeUndefined();
    });

    it("shows an error and resets button state when the switch fails", async () => {
        document.body.innerHTML = `
            <div>
                <div data-role-switch data-role-switch-url="/role/switch">
                    <button data-role-switch-button data-role="chairperson" disabled aria-current="true">Chairperson</button>
                    <button data-role-switch-button data-role="instructor">Instructor</button>
                </div>
                <p data-role-switch-error class="hidden"></p>
            </div>`;

        vi.spyOn(window, "fetch").mockResolvedValue({
            ok: false,
            json: async () => ({ error: "You cannot switch to that role." }),
        });

        await load();

        const chairButton = document.querySelector('[data-role="chairperson"]');
        const instructorButton = document.querySelector('[data-role="instructor"]');
        const error = document.querySelector("[data-role-switch-error]");

        instructorButton.click();
        await vi.waitFor(() => expect(error.classList.contains("hidden")).toBe(false));

        // The attempted (failed) role is clickable again; the real active
        // role stays disabled — not the other way around.
        expect(instructorButton.disabled).toBe(false);
        expect(chairButton.disabled).toBe(true);
        expect(error.textContent).toBe("You cannot switch to that role.");
    });
});
