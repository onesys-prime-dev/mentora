import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { bindExportModal } from "../../../resources/js/pages/courses/features/assessments/import-export/export-modal.js";

/** The parts the script finds by data attribute: a trimmed copy of partials/modals/export-questions.php. */
const FIXTURE = `
    <button type="button" data-open-export-questions aria-haspopup="dialog">Export</button>

    <div id="export-questions-modal" class="hidden" role="dialog" aria-modal="true">
        <div>
            <button type="button" data-close-export-questions-modal aria-label="Close">x</button>

            <label><input type="radio" name="export-kind" value="editable" data-export-kind> Editable file</label>
            <label><input type="radio" name="export-kind" value="printable" data-export-kind checked> Printable test</label>

            <fieldset data-export-formats="editable" class="hidden">
                <label><input type="radio" name="export-format-editable" value="docx" data-export-format-option checked> Word</label>
                <label><input type="radio" name="export-format-editable" value="rtf" data-export-format-option> RTF</label>
                <label><input type="radio" name="export-format-editable" value="csv" data-export-format-option> CSV</label>
                <label><input type="radio" name="export-format-editable" value="gift" data-export-format-option> GIFT</label>
            </fieldset>

            <fieldset data-export-formats="printable">
                <label><input type="radio" name="export-format-printable" value="test-pdf" data-export-format-option checked> PDF</label>
                <label><input type="radio" name="export-format-printable" value="test-docx" data-export-format-option> Word</label>
                <div data-print-options>
                    <select data-print-versions>
                        <option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option>
                    </select>
                    <select data-print-paper>
                        <option value="A4">A4</option><option value="Letter">Letter</option><option value="Legal">Legal</option>
                    </select>
                    <input type="checkbox" data-print-shuffle-questions>
                    <input type="checkbox" data-print-shuffle-choices>
                    <input type="checkbox" data-print-key-only>
                    <p data-print-hint class="hidden">With several versions...</p>
                </div>
            </fieldset>

            <button type="button" data-close-export-questions-modal>Cancel</button>
            <button type="button" data-export-questions data-assessment-id="9">Export</button>
        </div>
    </div>`;

const memory = (initial = null) => {
    let value = initial;

    return {
        getItem: () => value,
        setItem: (_key, v) => {
            value = v;
        },
        peek: () => (value ? JSON.parse(value) : null),
    };
};

const $ = (selector) => document.querySelector(selector);
const modal = () => $("#export-questions-modal");
const isOpen = () => !modal().classList.contains("hidden");
// Showing, inside the modal: neither it nor anything holding it up to the modal is hidden (the print options hide
// with their picker). Whether the modal itself is open is isOpen().
const visible = (selector) => {
    for (let el = $(selector); el && el !== modal(); el = el.parentElement) {
        if (el.classList.contains("hidden")) {
            return false;
        }
    }

    return true;
};
const click = (selector) => $(selector).dispatchEvent(new MouseEvent("click", { bubbles: true }));
const choose = (selector) => {
    const input = $(selector);
    input.checked = true;
    input.dispatchEvent(new Event("change", { bubbles: true }));
};

let navigate;

const bind = (storage = memory()) => {
    navigate = vi.fn();

    bindExportModal({ ids: () => ({ offeringId: 1, assessmentId: 9 }), navigate, storage });

    return storage;
};

beforeEach(() => {
    window.coursesConfig = { apiBaseUrl: "/api/courses/1" };
    document.body.innerHTML = FIXTURE;
});

afterEach(() => {
    document.body.innerHTML = "";
    delete window.coursesConfig;
});

describe("opening and closing", () => {
    it("opens when the export icon is pressed, and not before", () => {
        bind();

        expect(isOpen()).toBe(false);

        click("[data-open-export-questions]");

        expect(isOpen()).toBe(true);
    });

    it("closes from the X, from Cancel, from the backdrop and on Escape", () => {
        bind();

        for (const close of [
            () => document.querySelectorAll("[data-close-export-questions-modal]")[0].click(),
            () => document.querySelectorAll("[data-close-export-questions-modal]")[1].click(),
            () => modal().dispatchEvent(new MouseEvent("click", { bubbles: true })),
            () => document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true })),
        ]) {
            click("[data-open-export-questions]");
            expect(isOpen()).toBe(true);

            close();
            expect(isOpen()).toBe(false);
        }
    });
});

describe("the two choices", () => {
    it("start on the printable test with its formats and print options showing", () => {
        bind();

        expect($("[data-export-kind]:checked").value).toBe("printable");
        expect(visible('[data-export-formats="printable"]')).toBe(true);
        expect(visible("[data-print-options]")).toBe(true);
        expect(visible('[data-export-formats="editable"]')).toBe(false);
    });

    it("show the editable file's formats and hide the print options when that is chosen", () => {
        bind();

        choose('[data-export-kind][value="editable"]');

        expect(visible('[data-export-formats="editable"]')).toBe(true);
        expect(visible('[data-export-formats="printable"]')).toBe(false);
        expect(visible("[data-print-options]")).toBe(false);
    });

    it("go back to the printable test's formats and print options", () => {
        bind();

        choose('[data-export-kind][value="editable"]');
        choose('[data-export-kind][value="printable"]');

        expect(visible('[data-export-formats="printable"]')).toBe(true);
        expect(visible("[data-print-options]")).toBe(true);
        expect(visible('[data-export-formats="editable"]')).toBe(false);
    });

    it("show the several-versions hint only when more than one version is asked for", () => {
        bind();

        expect(visible("[data-print-hint]")).toBe(false);

        $("[data-print-versions]").value = "2";
        $("[data-print-versions]").dispatchEvent(new Event("change", { bubbles: true }));
        expect(visible("[data-print-hint]")).toBe(true);

        $("[data-print-versions]").value = "1";
        $("[data-print-versions]").dispatchEvent(new Event("change", { bubbles: true }));
        expect(visible("[data-print-hint]")).toBe(false);
    });
});

describe("exporting", () => {
    it("a printable test goes to the export address with the print settings, and closes the modal", () => {
        bind();
        click("[data-open-export-questions]");

        choose('[data-export-format-option][value="test-docx"]');
        $("[data-print-versions]").value = "3";
        $("[data-print-paper]").value = "Legal";
        $("[data-print-shuffle-questions]").checked = true;
        $("[data-print-key-only]").checked = true;
        click("[data-export-questions]");

        expect(navigate).toHaveBeenCalledTimes(1);

        const url = new URL(navigate.mock.calls[0][0], "http://localhost");

        expect(url.pathname).toBe("/api/courses/1/assessments/9/export");
        expect(url.searchParams.get("format")).toBe("test-docx");
        expect(url.searchParams.get("versions")).toBe("3");
        expect(url.searchParams.get("paper")).toBe("Legal");
        expect(url.searchParams.get("shuffle_questions")).toBe("1");
        expect(url.searchParams.get("key_only")).toBe("1");
        expect(url.searchParams.has("shuffle_choices")).toBe(false);
        expect(isOpen()).toBe(false);
    });

    it("an editable file goes out in the chosen format with no print settings", () => {
        bind();
        click("[data-open-export-questions]");

        choose('[data-export-kind][value="editable"]');
        choose('[data-export-format-option][value="csv"]');
        $("[data-print-versions]").value = "4"; // set while the printable test was showing; must not leak
        click("[data-export-questions]");

        expect(navigate).toHaveBeenCalledWith("/api/courses/1/assessments/9/export?format=csv");
    });

    it("the editable file starts on Word, and each choice keeps its own format", () => {
        bind();
        click("[data-open-export-questions]");

        choose('[data-export-format-option][value="test-docx"]');
        choose('[data-export-kind][value="editable"]');
        click("[data-export-questions]");

        expect(navigate).toHaveBeenLastCalledWith("/api/courses/1/assessments/9/export?format=docx");

        click("[data-open-export-questions]");
        choose('[data-export-kind][value="printable"]');
        click("[data-export-questions]");

        expect(new URL(navigate.mock.calls[1][0], "http://localhost").searchParams.get("format")).toBe("test-docx");
    });

    it("exports the assessment the builder is on", () => {
        navigate = vi.fn();
        bindExportModal({ ids: () => ({ offeringId: 1, assessmentId: 77 }), navigate, storage: memory() });

        click("[data-export-questions]");

        expect(navigate.mock.calls[0][0]).toContain("/assessments/77/export");
    });
});

describe("remembering the last choice in this browser", () => {
    it("keeps the choice, the format and the print settings for next time", () => {
        const storage = bind();
        click("[data-open-export-questions]");

        choose('[data-export-kind][value="editable"]');
        choose('[data-export-format-option][value="gift"]');
        click("[data-export-questions]");

        expect(storage.peek()).toMatchObject({ kind: "editable", format: "gift" });

        document.body.innerHTML = FIXTURE;
        bind(storage);

        expect($("[data-export-kind]:checked").value).toBe("editable");
        expect($('[data-export-formats="editable"] [data-export-format-option]:checked').value).toBe("gift");
        expect(visible('[data-export-formats="editable"]')).toBe(true);
        expect(visible("[data-print-options]")).toBe(false);
    });

    it("puts the print settings back", () => {
        const storage = memory(JSON.stringify({ kind: "printable", format: "test-docx", print: { versions: 2, paper: "Letter", shuffleQuestions: true, shuffleChoices: false, keyOnly: true } }));
        bind(storage);

        expect($('[data-export-formats="printable"] [value="test-docx"]').checked).toBe(true);
        expect($("[data-print-versions]").value).toBe("2");
        expect($("[data-print-paper]").value).toBe("Letter");
        expect($("[data-print-shuffle-questions]").checked).toBe(true);
        expect($("[data-print-shuffle-choices]").checked).toBe(false);
        expect($("[data-print-key-only]").checked).toBe(true);
        expect(visible("[data-print-hint]")).toBe(true);
    });

    it("understands what the old export panel remembered, which had no choice, only a format", () => {
        bind(memory(JSON.stringify({ format: "test-rtf", print: { versions: 3 } })));
        expect($("[data-export-kind]:checked").value).toBe("printable");

        document.body.innerHTML = FIXTURE;
        bind(memory(JSON.stringify({ format: "rtf" })));
        expect($("[data-export-kind]:checked").value).toBe("editable");
        expect($('[data-export-formats="editable"] [value="rtf"]').checked).toBe(true);
    });

    it("ignores a remembered format the modal no longer offers, and unusable storage", () => {
        bind(memory(JSON.stringify({ kind: "editable", format: "qti" })));

        expect($("[data-export-kind]:checked").value).toBe("editable");
        expect($("[data-export-format-option]:checked").value).toBe("docx");

        document.body.innerHTML = FIXTURE;
        bind({ getItem: () => "{not json", setItem: () => {} });
        expect($("[data-export-kind]:checked").value).toBe("printable");

        document.body.innerHTML = FIXTURE;
        bind({ getItem: () => { throw new Error("blocked"); }, setItem: () => { throw new Error("full"); } });
        click("[data-export-questions]");
        expect(navigate).toHaveBeenCalledTimes(1);
    });
});

describe("a page without the modal", () => {
    it("does nothing and does not fail", () => {
        document.body.innerHTML = "";

        expect(() => bindExportModal({ ids: () => ({ offeringId: 1, assessmentId: 9 }), navigate: vi.fn(), storage: memory() })).not.toThrow();
    });
});
