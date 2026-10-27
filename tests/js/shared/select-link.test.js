import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const load = async () => {
    vi.resetModules();
    await import("../../../resources/js/shared/select-link.js");
};

afterEach(() => {
    delete window.__selectLinkNavigate;
    document.body.innerHTML = "";
});

describe("select that goes to a page", () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <select data-select-link>
                <option value="/parent/children/1" selected>Ann</option>
                <option value="/parent/children/2">Ben</option>
                <option value="https://elsewhere.example/steal">Elsewhere</option>
                <option value="">Nothing</option>
            </select>
            <select id="plain"><option value="/x" selected>x</option><option value="/y">y</option></select>`;
    });

    const choose = (select, value) => {
        select.value = value;
        select.dispatchEvent(new Event("change", { bubbles: true }));
    };

    it("goes to the page of the chosen option", async () => {
        const navigate = vi.fn();
        window.__selectLinkNavigate = navigate;

        await load();
        choose(document.querySelector("[data-select-link]"), "/parent/children/2");

        expect(navigate).toHaveBeenCalledWith("/parent/children/2");
    });

    it("never goes to another site", async () => {
        const navigate = vi.fn();
        window.__selectLinkNavigate = navigate;

        await load();
        choose(document.querySelector("[data-select-link]"), "https://elsewhere.example/steal");

        expect(navigate).not.toHaveBeenCalled();
    });

    it("does nothing for an empty choice or for a select that is not marked", async () => {
        const navigate = vi.fn();
        window.__selectLinkNavigate = navigate;

        await load();
        choose(document.querySelector("[data-select-link]"), "");
        choose(document.querySelector("#plain"), "/y");

        expect(navigate).not.toHaveBeenCalled();
    });
});
