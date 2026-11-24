// @vitest-environment jsdom
import { describe, expect, it } from "vitest";
import { COLOURS, renderAvatar } from "../../../resources/js/shared/avatar.js";

const person = { id: 10, name: "Ana Reyes", initials: "AR", colour: COLOURS[2], photoUrl: null };

describe("renderAvatar", () => {
    it("shows the given initials with an accessible name and a colour from the set", () => {
        const el = renderAvatar(person, "md");
        const text = el.querySelector("[role=img]");
        expect(text.textContent).toBe("AR");
        expect(text.getAttribute("aria-label")).toBe("Ana Reyes");
        expect(el.querySelector("img")).toBeNull();
        expect(el.style.backgroundColor).not.toBe("");
    });

    it("picks the colour by id % 8 when none is given", () => {
        const el = renderAvatar({ ...person, id: 11, colour: undefined });
        const probe = document.createElement("span");
        probe.style.backgroundColor = COLOURS[3];
        expect(el.style.backgroundColor).toBe(probe.style.backgroundColor);
    });

    it("shows a photo with alt 'Photo of {name}' only when there is one", () => {
        const img = renderAvatar({ ...person, photoUrl: "/p.jpg" }).querySelector("img");
        expect(img.alt).toBe("Photo of Ana Reyes");
        expect(img.getAttribute("src")).toBe("/p.jpg");
    });

    it("uses the 256 px photo for the larger sizes", () => {
        const p = { ...person, photoUrl: "/p64.jpg", photoUrlLg: "/p256.jpg" };
        expect(renderAvatar(p, "sm").querySelector("img").getAttribute("src")).toBe("/p64.jpg");
        expect(renderAvatar(p, "xl").querySelector("img").getAttribute("src")).toBe("/p256.jpg");
    });

    it("sizes by the map and falls back to md", () => {
        expect(renderAvatar(person, "xs").className).toContain("h-6 w-6");
        expect(renderAvatar(person, "xl").className).toContain("h-18 w-18");
        expect(renderAvatar(person, "nope").className).toContain("h-12 w-12");
    });
});
