import { afterEach, describe, expect, it } from "vitest";

import { bindPublishFields } from "../../../resources/js/pages/courses/features/modules/modals/publish-fields.js";

/** A trimmed copy of partials/modals/publish-fields.php. */
const markup = `
    <form id="f">
        <div data-publish-fields>
            <input type="radio" name="status" value="draft" data-publish-status checked>
            <input type="radio" name="status" value="published" data-publish-status>
            <p data-publish-lock class="hidden"></p>
            <span data-hidden-badge class="hidden"></span>
            <div data-schedule-block>
                <input type="checkbox" name="schedule_publish" value="1" data-schedule-toggle>
                <div data-schedule-when class="hidden">
                    <input type="datetime-local" name="scheduled_publish_at" data-schedule-input>
                </div>
            </div>
        </div>
    </form>`;

const $ = (selector) => document.querySelector(selector);
const hidden = (selector) => $(selector).classList.contains("hidden");
const status = (value) => {
    document.querySelector(`[data-publish-status][value="${value}"]`).checked = true;
    document.querySelector(`[data-publish-status][value="${value}"]`).dispatchEvent(new Event("change", { bubbles: true }));
};
const toggle = (on) => {
    $("[data-schedule-toggle]").checked = on;
    $("[data-schedule-toggle]").dispatchEvent(new Event("change", { bubbles: true }));
};

const bind = () => {
    document.body.innerHTML = markup;

    return bindPublishFields($("[data-publish-fields]"));
};

afterEach(() => {
    document.body.innerHTML = "";
});

describe("a new row starts as a draft", () => {
    it("shows the schedule switch and no date picker", () => {
        bind();

        expect(hidden("[data-schedule-block]")).toBe(false);
        expect(hidden("[data-schedule-when]")).toBe(true);
    });
});

describe("Schedule publish", () => {
    it("shows the date and time picker when it is switched on, and hides it when off", () => {
        bind();

        toggle(true);
        expect(hidden("[data-schedule-when]")).toBe(false);

        toggle(false);
        expect(hidden("[data-schedule-when]")).toBe(true);
    });

    it("forgets the time when it is switched off, so none is sent", () => {
        bind();
        toggle(true);
        $("[data-schedule-input]").value = "2030-03-04T09:30";

        toggle(false);

        expect($("[data-schedule-input]").value).toBe("");
    });

    it("is only offered for a draft: publishing hides it and drops the time", () => {
        bind();
        toggle(true);
        $("[data-schedule-input]").value = "2030-03-04T09:30";

        status("published");

        expect(hidden("[data-schedule-block]")).toBe(true);
        expect($("[data-schedule-toggle]").checked).toBe(false);
        expect($("[data-schedule-input]").value).toBe("");

        status("draft");
        expect(hidden("[data-schedule-block]")).toBe(false);
        expect(hidden("[data-schedule-when]")).toBe(true);
    });

    it("asks for a time in the future: the picker's earliest is now", () => {
        bind();

        expect($("[data-schedule-input]").min).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
    });

    it("needs the picker filled in when on (the form will not send without it)", () => {
        bind();

        toggle(true);
        expect($("[data-schedule-input]").required).toBe(true);

        toggle(false);
        expect($("[data-schedule-input]").required).toBe(false);
    });
});

describe("the hidden badge", () => {
    it("shows when published under a draft parent, with the parent named", () => {
        const fields = bind();
        fields.setHiddenBy("module");

        status("published");

        expect(hidden("[data-hidden-badge]")).toBe(false);
        expect($("[data-hidden-badge]").textContent).toBe("Hidden — module is a draft");
    });

    it("is not shown for a draft, nor when the parent is published", () => {
        const fields = bind();
        fields.setHiddenBy("module");
        expect(hidden("[data-hidden-badge]")).toBe(true);

        fields.setHiddenBy(null);
        status("published");
        expect(hidden("[data-hidden-badge]")).toBe(true);
    });

    it("follows the parent changing while published", () => {
        const fields = bind();
        status("published");

        fields.setHiddenBy("unit");
        expect($("[data-hidden-badge]").textContent).toBe("Hidden — unit is a draft");

        fields.setHiddenBy(null);
        expect(hidden("[data-hidden-badge]")).toBe(true);
    });
});

describe("filling and resetting", () => {
    it("fills a scheduled draft", () => {
        const fields = bind();

        fields.fill({ status: "draft", scheduledAt: "2030-03-04 09:30:00" });

        expect($("[data-schedule-toggle]").checked).toBe(true);
        expect(hidden("[data-schedule-when]")).toBe(false);
        expect($("[data-schedule-input]").value).toBe("2030-03-04T09:30");
    });

    it("fills a published row", () => {
        const fields = bind();

        fields.fill({ status: "published", scheduledAt: "" });

        expect($('[data-publish-status][value="published"]').checked).toBe(true);
        expect(hidden("[data-schedule-block]")).toBe(true);
    });

    it("reset goes back to a draft with nothing scheduled", () => {
        const fields = bind();
        fields.fill({ status: "draft", scheduledAt: "2030-03-04 09:30:00" });

        fields.reset();

        expect($('[data-publish-status][value="draft"]').checked).toBe(true);
        expect($("[data-schedule-toggle]").checked).toBe(false);
        expect($("[data-schedule-input]").value).toBe("");
        expect(hidden("[data-schedule-when]")).toBe(true);
    });
});

describe("a status that cannot be chosen", () => {
    it("keeps the row a draft and says why, until it is allowed again", () => {
        const fields = bind();
        status("published");

        fields.lockPublish("A quiz needs a question before it can be published.");

        expect($('[data-publish-status][value="draft"]').checked).toBe(true);
        expect($('[data-publish-status][value="published"]').disabled).toBe(true);
        expect($("[data-publish-lock]").textContent).toBe("A quiz needs a question before it can be published.");
        expect(hidden("[data-publish-lock]")).toBe(false);

        fields.lockPublish(null);
        expect($('[data-publish-status][value="published"]').disabled).toBe(false);
        expect(hidden("[data-publish-lock]")).toBe(true);
    });
});

describe("without the fields", () => {
    it("does nothing", () => {
        document.body.innerHTML = "";

        const fields = bindPublishFields(null);

        expect(() => { fields.reset(); fields.fill({}); fields.setHiddenBy("module"); fields.lockPublish("x"); }).not.toThrow();
    });
});
