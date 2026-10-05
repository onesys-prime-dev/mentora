import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { initializeAssignmentSubmission } from "../../../../resources/js/shared/assignment/submission.js";

const MB = 1024 * 1024;

const markup = `
<form data-assignment-submission-form data-assessment-id="42">
    <input type="radio" name="work-tab" value="file" checked>
    <div data-assignment-dropzone>
        <input type="file" name="file" data-assignment-file-input>
        <span data-assignment-file-name>No file chosen</span>
    </div>
    <textarea name="submission_text"></textarea>
    <input type="text" name="submission_link">
    <p class="hidden" data-assignment-submission-error></p>
    <button type="submit" data-assignment-submit>Turn in</button>
</form>`;

const $ = (selector) => document.querySelector(selector);

/*
 * jsdom has no DataTransfer and only lets a file input's `files` be set to a
 * real FileList, which it cannot build. These stand in for what a browser
 * does, so the code under test runs unchanged.
 */
class FakeDataTransfer {
    constructor() {
        this.files = [];
        this.items = { add: (file) => this.files.push(file) };
    }
}

const makeInputSettable = () => {
    Object.defineProperty($("[data-assignment-file-input]"), "files", { configurable: true, writable: true, value: [] });
};

const choose = (name, size = 100) => {
    const file = new File(["x"], name, { type: "application/pdf" });
    Object.defineProperty(file, "size", { value: size });

    $("[data-assignment-file-input]").files = [file];
    $("[data-assignment-file-input]").dispatchEvent(new Event("change"));

    return file;
};

const submit = async () => {
    $("form").dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
    await vi.waitFor(() => expect($("[data-assignment-submit]").disabled).toBe(false));
};

const respond = (body, ok = true) => vi.fn(async () => ({ ok, json: async () => body }));

beforeEach(() => {
    document.body.innerHTML = markup;
    makeInputSettable();
    vi.stubGlobal("DataTransfer", FakeDataTransfer);
    window.coursesConfig = { apiBaseUrl: "/student/api/courses/7" };
    vi.spyOn(console, "error").mockImplementation(() => {});
});

afterEach(() => {
    delete window.coursesConfig;
});

describe("without the form", () => {
    it("does nothing", () => {
        document.body.innerHTML = "";

        expect(() => initializeAssignmentSubmission()).not.toThrow();
    });
});

describe("choosing a file", () => {
    beforeEach(() => initializeAssignmentSubmission());

    it("shows its name and size", () => {
        choose("essay.pdf", 1536);

        expect($("[data-assignment-file-name]").textContent).toBe("essay.pdf (1.5 KB)");
    });

    it.each([
        [512, "512 B"],
        [1024, "1.0 KB"],
        [1536, "1.5 KB"],
        [MB, "1.0 MB"],
        [5.25 * MB, "5.3 MB"],
    ])("writes %d bytes as %s", (bytes, label) => {
        choose("f.pdf", bytes);

        expect($("[data-assignment-file-name]").textContent).toBe(`f.pdf (${label})`);
    });

    it("goes back to the original label when the file is cleared", () => {
        choose("essay.pdf");
        $("[data-assignment-file-input]").files = [];
        $("[data-assignment-file-input]").dispatchEvent(new Event("change"));

        expect($("[data-assignment-file-name]").textContent).toBe("No file chosen");
    });

    it("clears an earlier error", () => {
        $("[data-assignment-submission-error]").textContent = "old";
        $("[data-assignment-submission-error]").classList.remove("hidden");

        choose("essay.pdf");

        expect($("[data-assignment-submission-error]").classList.contains("hidden")).toBe(true);
    });
});

describe("dragging a file in", () => {
    beforeEach(() => initializeAssignmentSubmission());

    const drop = (files) => {
        const event = new Event("drop", { cancelable: true, bubbles: true });
        event.dataTransfer = { files };
        $("[data-assignment-dropzone]").dispatchEvent(event);
        return event;
    };

    it("marks the dropzone while a file is over it and unmarks it after", () => {
        const over = new Event("dragover", { cancelable: true });
        $("[data-assignment-dropzone]").dispatchEvent(over);

        expect(over.defaultPrevented).toBe(true);
        expect($("[data-assignment-dropzone]").dataset.dragging).toBeDefined();

        $("[data-assignment-dropzone]").dispatchEvent(new Event("dragleave"));

        expect($("[data-assignment-dropzone]").dataset.dragging).toBeUndefined();
    });

    it("takes the dropped file into the input", () => {
        const dropped = new File(["x"], "dropped.pdf");

        const event = drop([dropped]);

        expect(event.defaultPrevented).toBe(true);
        expect($("[data-assignment-file-input]").files[0].name).toBe("dropped.pdf");
        expect($("[data-assignment-file-name]").textContent).toContain("dropped.pdf");
        expect($("[data-assignment-dropzone]").dataset.dragging).toBeUndefined();
    });

    it("ignores a drop that carries no file", () => {
        drop([]);

        expect($("[data-assignment-file-input]").files.length).toBe(0);
        expect($("[data-assignment-file-name]").textContent).toBe("No file chosen");
    });
});

describe("submitting", () => {
    let fetchMock;

    beforeEach(() => {
        initializeAssignmentSubmission({ onSubmitted: (window.onSubmitted = vi.fn()) });
        fetchMock = respond({ success: true });
        vi.stubGlobal("fetch", fetchMock);
    });

    afterEach(() => {
        delete window.onSubmitted;
    });

    const error = () => $("[data-assignment-submission-error]");

    it.each([
        ["nothing at all", () => {}],
        ["only spaces", () => ($("[name=submission_text]").value = "   ")],
    ])("asks for work when given %s, without calling the server", async (_label, fill) => {
        fill();

        $("form").dispatchEvent(new Event("submit", { cancelable: true }));

        expect(error().textContent).toBe("Add your work before submitting.");
        expect(error().classList.contains("hidden")).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
        expect($("[data-assignment-submit]").disabled).toBe(false);
    });

    it("refuses a file over 20 MB", () => {
        choose("huge.pdf", 20 * MB + 1);

        $("form").dispatchEvent(new Event("submit", { cancelable: true }));

        expect(error().textContent).toBe("The file is larger than the 20 MB limit.");
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("accepts a file of exactly 20 MB", async () => {
        choose("limit.pdf", 20 * MB);

        await submit();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it.each(["ftp://example.test/x", "javascript:alert(1)", "example.test/work", "www.example.test", "http:example.test", "https:/example.test"])(
        "refuses the link %s",
        (link) => {
            $("[name=submission_link]").value = link;

            $("form").dispatchEvent(new Event("submit", { cancelable: true }));

            expect(error().textContent).toBe("Enter a full link starting with http:// or https://.");
            expect(fetchMock).not.toHaveBeenCalled();
        }
    );

    it.each(["https://example.test/work", "HTTP://EXAMPLE.TEST/work"])("accepts the link %s", async (link) => {
        $("[name=submission_link]").value = link;

        await submit();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it("posts the work to the assessment's submission route and calls back", async () => {
        $("[name=submission_text]").value = "My answer";

        await submit();

        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe("/student/api/courses/7/assessments/42/assignment-submissions");
        expect(options.method).toBe("POST");
        expect(options.headers).toEqual({ "X-Requested-With": "XMLHttpRequest", Accept: "application/json" });
        expect(options.body.get("submission_text")).toBe("My answer");
        expect(options.body.has("work-tab")).toBe(false);
        expect(window.onSubmitted).toHaveBeenCalledTimes(1);
    });

    it("leaves the empty file field out of a text-only submission", async () => {
        $("[name=submission_text]").value = "text only";

        await submit();

        expect(fetchMock.mock.calls[0][1].body.has("file")).toBe(false);
    });

    it("disables the button and says so while sending, then restores it", async () => {
        let finish;
        fetchMock.mockImplementation(() => new Promise((resolve) => (finish = () => resolve({ ok: true, json: async () => ({ success: true }) }))));
        $("[name=submission_text]").value = "x";

        $("form").dispatchEvent(new Event("submit", { cancelable: true }));

        expect($("[data-assignment-submit]").disabled).toBe(true);
        expect($("[data-assignment-submit]").textContent).toBe("Submitting...");

        finish();
        await vi.waitFor(() => expect($("[data-assignment-submit]").disabled).toBe(false));
        expect($("[data-assignment-submit]").textContent).toBe("Turn in");
    });

    it("shows the server's message when it refuses the work", async () => {
        fetchMock.mockImplementation(respond({ success: false, message: "The deadline has passed." }));
        $("[name=submission_text]").value = "x";

        await submit();

        expect(error().textContent).toBe("The deadline has passed.");
        expect(window.onSubmitted).not.toHaveBeenCalled();
    });

    it("gives a generic message for a failed response without one, or without JSON", async () => {
        $("[name=submission_text]").value = "x";

        fetchMock.mockImplementation(respond({ success: false }, false));
        await submit();
        expect(error().textContent).toBe("Your work couldn't be submitted. Please try again.");

        fetchMock.mockImplementation(async () => ({ ok: false, json: async () => { throw new Error("not json"); } }));
        await submit();
        expect(error().textContent).toBe("Your work couldn't be submitted. Please try again.");
    });

    it("explains a network failure and lets the person try again", async () => {
        fetchMock.mockImplementation(async () => {
            throw new TypeError("Failed to fetch");
        });
        $("[name=submission_text]").value = "x";

        await submit();

        expect(error().textContent).toBe("Your work couldn't be submitted. Check your connection and try again.");
        expect($("[data-assignment-submit]").disabled).toBe(false);
        expect(window.onSubmitted).not.toHaveBeenCalled();
    });

    it("clears the previous error when submitting again", async () => {
        $("form").dispatchEvent(new Event("submit", { cancelable: true }));
        expect(error().textContent).not.toBe("");

        $("[name=submission_text]").value = "now with work";
        await submit();

        expect(error().classList.contains("hidden")).toBe(true);
    });
});
