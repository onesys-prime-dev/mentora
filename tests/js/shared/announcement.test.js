import { beforeEach, describe, expect, it } from "vitest";
import { announcementKey, dismiss, initAnnouncement, isDismissed } from "../../../resources/js/shared/announcement.js";

/** A minimal in-memory Storage. */
function memoryStorage(initial = {}) {
    const data = new Map(Object.entries(initial));

    return {
        getItem: (key) => (data.has(key) ? data.get(key) : null),
        setItem: (key, value) => void data.set(key, String(value)),
    };
}

function page(text = "Exams start Monday.") {
    document.body.innerHTML = `
        <div data-announcement>
            <span data-announcement-text>${text}</span>
            <button type="button" data-announcement-dismiss>Dismiss</button>
        </div>`;

    return document.querySelector("[data-announcement]");
}

beforeEach(() => {
    document.body.innerHTML = "";
});

describe("announcementKey", () => {
    it("is the same for the same text and different for another", () => {
        expect(announcementKey("Exams start Monday.")).toBe(announcementKey("Exams start Monday."));
        expect(announcementKey("Exams start Monday.")).not.toBe(announcementKey("Exams start Tuesday."));
    });

    it("is a short, plain key", () => {
        expect(announcementKey("anything at all, even with ünïcode ✓")).toMatch(/^announcement-dismissed:[0-9a-z]+$/);
    });
});

describe("dismissals", () => {
    it("remember a dismissal", () => {
        const storage = memoryStorage();
        const key = announcementKey("x");

        expect(isDismissed(storage, key)).toBe(false);

        dismiss(storage, key);

        expect(isDismissed(storage, key)).toBe(true);
    });

    it("survive storage that throws, by showing the announcement again", () => {
        const broken = {
            getItem() {
                throw new Error("blocked");
            },
            setItem() {
                throw new Error("blocked");
            },
        };

        expect(() => dismiss(broken, "k")).not.toThrow();
        expect(isDismissed(broken, "k")).toBe(false);
        expect(isDismissed(null, "k")).toBe(false);
    });
});

describe("initAnnouncement", () => {
    it("does nothing when the page has no announcement", () => {
        expect(initAnnouncement(document, memoryStorage())).toBeNull();
    });

    it("shows the announcement and hides it when dismissed", () => {
        const banner = page();
        const storage = memoryStorage();

        initAnnouncement(document, storage);

        expect(banner.hidden).toBe(false);

        banner.querySelector("[data-announcement-dismiss]").click();

        expect(banner.hidden).toBe(true);
        expect(isDismissed(storage, announcementKey("Exams start Monday."))).toBe(true);
    });

    it("stays hidden on the next page for the same text", () => {
        const storage = memoryStorage();
        page();
        initAnnouncement(document, storage);
        document.querySelector("[data-announcement-dismiss]").click();

        const next = page();
        initAnnouncement(document, storage);

        expect(next.hidden).toBe(true);
    });

    it("shows again when the text changes", () => {
        const storage = memoryStorage();
        dismiss(storage, announcementKey("Exams start Monday."));

        const banner = page("Maintenance tonight.");
        initAnnouncement(document, storage);

        expect(banner.hidden).toBe(false);
    });
    it("binds every announcement line: the platform's and the institution's dismiss on their own", () => {
        document.body.innerHTML = `
            <div data-announcement id="platform">
                <span data-announcement-text>Maintenance tonight.</span>
                <button type="button" data-announcement-dismiss>Dismiss</button>
            </div>
            <div data-announcement data-institution-announcement id="school">
                <span data-announcement-text>Enrolment closes Friday.</span>
                <button type="button" data-announcement-dismiss>Dismiss</button>
            </div>`;

        const storage = memoryStorage();
        const first = initAnnouncement(document, storage);

        expect(first?.id).toBe("platform");

        document.querySelector("#school [data-announcement-dismiss]").click();

        expect(document.querySelector("#school").hidden).toBe(true);
        expect(document.querySelector("#platform").hidden).toBe(false);
        expect(isDismissed(storage, announcementKey("Enrolment closes Friday."))).toBe(true);
        expect(isDismissed(storage, announcementKey("Maintenance tonight."))).toBe(false);
    });

    it("hides only the one already dismissed when two are present", () => {
        const storage = memoryStorage();
        dismiss(storage, announcementKey("Maintenance tonight."));

        document.body.innerHTML = `
            <div data-announcement id="platform"><span data-announcement-text>Maintenance tonight.</span><button type="button" data-announcement-dismiss>x</button></div>
            <div data-announcement id="school"><span data-announcement-text>Enrolment closes Friday.</span><button type="button" data-announcement-dismiss>x</button></div>`;

        initAnnouncement(document, storage);

        expect(document.querySelector("#platform").hidden).toBe(true);
        expect(document.querySelector("#school").hidden).toBe(false);
    });
});
