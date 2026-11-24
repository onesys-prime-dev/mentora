import { describe, expect, it } from "vitest";

import { bindProfileDialogs, countText, syncCustomPronouns } from "../../../../resources/js/pages/profile/dialogs.js";

describe("the custom pronouns box", () => {

    it("shows only while Custom is chosen", () => {

        document.body.innerHTML = `<select><option value="she/her">she/her</option><option value="custom">Custom</option></select><div hidden></div>`;
        const select = document.querySelector("select");
        const box = document.querySelector("div");

        expect(syncCustomPronouns(select, box)).toBe(false);
        expect(box.hidden).toBe(true);

        select.value = "custom";

        expect(syncCustomPronouns(select, box)).toBe(true);
        expect(box.hidden).toBe(false);

    });

    it("follows the select once bound", () => {

        document.body.innerHTML = `<form data-profile-form><div data-field="pronouns">
            <select data-pronoun-select><option value="">None</option><option value="custom">Custom</option></select>
            <div data-pronoun-custom><input maxlength="30"></div><p data-field-error hidden></p></div></form>`;
        bindProfileDialogs();
        const select = document.querySelector("select");

        expect(document.querySelector("[data-pronoun-custom]").hidden).toBe(true);

        select.value = "custom";
        select.dispatchEvent(new Event("change"));

        expect(document.querySelector("[data-pronoun-custom]").hidden).toBe(false);

    });

});

describe("the character count", () => {

    it("counts up to the limit and then says how far over", () => {

        expect(countText(12, 300)).toBe("12 / 300");
        expect(countText(305, 300)).toBe("305 / 300, 5 over");

    });

    it("updates as someone types", () => {

        document.body.innerHTML = `<form data-profile-form><div data-field="bio"><textarea maxlength="300"></textarea><p data-field-error hidden></p></div></form>`;
        bindProfileDialogs();
        const area = document.querySelector("textarea");

        expect(document.body.textContent).toContain("0 / 300");

        area.value = "hello";
        area.dispatchEvent(new Event("input"));

        expect(document.body.textContent).toContain("5 / 300");

    });

});
