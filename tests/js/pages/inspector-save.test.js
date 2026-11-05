import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const updateAssessment = vi.fn();
const setQuizPassword = vi.fn();
const showToast = vi.fn();

vi.mock("../../../resources/js/pages/courses/features/assessments/api.js", () => ({
    updateAssessment: (...args) => updateAssessment(...args),
    setQuizPassword: (...args) => setQuizPassword(...args),
    fetchAssignees: vi.fn(),
    saveAssignees: vi.fn(),
}));

vi.mock("../../../resources/js/shared/toast.js", () => ({
    showToast: (...args) => showToast(...args),
}));

const load = async () => {
    vi.resetModules();

    return import("../../../resources/js/pages/courses/features/assessments/inspector/inspector.js");
};

const PANEL = (hasPassword, requirePassword) => `
    <div id="editor-inspector-panel" data-assessment-id="9">
        <input type="checkbox" data-inspector-field="randomize_questions" checked>
        <input type="number" data-inspector-field="total_points" value="10">
        <div data-quiz-password data-has-password="${hasPassword ? "1" : "0"}">
            <input type="checkbox" data-inspector-field="require_password" data-password-toggle ${requirePassword ? "checked" : ""}>
            <div data-password-state class="hidden">
                <button type="button" data-password-create>Create password</button>
                <div data-password-set class="hidden"><span data-password-mask>******</span><button type="button" data-password-edit>edit</button></div>
            </div>
        </div>
    </div>
    <button type="button" data-save-draft>Save draft</button>
    <button type="button" data-publish>Publish</button>`;

const click = (selector) => document.querySelector(selector).dispatchEvent(new MouseEvent("click", { bubbles: true }));

let refresh;

beforeEach(() => {
    updateAssessment.mockReset();
    setQuizPassword.mockReset();
    showToast.mockReset();
    refresh = vi.fn().mockResolvedValue(undefined);
    window.coursesConfig = { apiBaseUrl: "/api/courses/1" };
});

afterEach(() => {
    document.body.innerHTML = "";
    delete window.coursesConfig;
});

describe("saving the settings", () => {
    it("sends every setting with the status and then reloads", async () => {
        document.body.innerHTML = PANEL(true, true);
        updateAssessment.mockResolvedValue({ success: true, message: "Assessment updated successfully." });
        const { initializeInspectorPanel } = await load();
        initializeInspectorPanel(1, 9, refresh);

        click("[data-publish]");
        await vi.waitFor(() => expect(refresh).toHaveBeenCalled());

        expect(updateAssessment).toHaveBeenCalledWith(1, 9, { randomize_questions: true, total_points: 10, require_password: true, status: "Published" });
        expect(showToast).not.toHaveBeenCalled();
    });

    it("shows the server's reason and keeps the form when the settings are refused", async () => {
        document.body.innerHTML = PANEL(true, true);
        updateAssessment.mockResolvedValue({ success: false, message: "The closing date must come after the opening date." });
        const { initializeInspectorPanel } = await load();
        initializeInspectorPanel(1, 9, refresh);

        click("[data-save-draft]");
        await vi.waitFor(() => expect(showToast).toHaveBeenCalled());

        expect(showToast).toHaveBeenCalledWith("The closing date must come after the opening date.", { tone: "danger" });
        expect(refresh).not.toHaveBeenCalled();
    });

    it("says so when the request itself fails", async () => {
        document.body.innerHTML = PANEL(true, true);
        updateAssessment.mockRejectedValue(new Error("offline"));
        const { initializeInspectorPanel } = await load();
        initializeInspectorPanel(1, 9, refresh);

        click("[data-save-draft]");
        await vi.waitFor(() => expect(showToast).toHaveBeenCalled());

        expect(showToast.mock.calls[0][1]).toEqual({ tone: "danger" });
        expect(refresh).not.toHaveBeenCalled();
    });

    it("will not save with the password switched on and none created, and says what to do", async () => {
        document.body.innerHTML = PANEL(false, true);
        const { initializeInspectorPanel } = await load();
        initializeInspectorPanel(1, 9, refresh);

        click("[data-publish]");
        await Promise.resolve();

        expect(updateAssessment).not.toHaveBeenCalled();
        expect(showToast).toHaveBeenCalledWith(expect.stringMatching(/Create a password/), { tone: "danger" });
    });

    it("saves the password through its own request and tells the teacher", async () => {
        document.body.innerHTML = PANEL(false, true) + `<div id="quiz-password-modal" class="hidden"><input data-password-input><p data-password-error></p><button data-password-save></button><button data-close-password-modal></button></div>`;
        setQuizPassword.mockResolvedValue({ success: true, message: "Password saved." });
        const { initializeInspectorPanel } = await load();
        initializeInspectorPanel(1, 9, refresh);

        click("[data-password-create]");
        document.querySelector("[data-password-input]").value = "Red-Kite-77";
        click("[data-password-save]");
        await vi.waitFor(() => expect(showToast).toHaveBeenCalled());

        expect(setQuizPassword).toHaveBeenCalledWith(1, 9, "Red-Kite-77");
        expect(showToast).toHaveBeenCalledWith("Password saved.", { tone: "success" });
        expect(updateAssessment).not.toHaveBeenCalled();
    });
});

describe("specific students", () => {
    const SPECIFIC = (on, count) => `
        <div id="editor-inspector-panel" data-assessment-id="9">
            <div data-specific-students data-assignee-count="${count}">
                <input type="checkbox" data-inspector-field="assign_to_specific_students" data-specific-toggle ${on ? "checked" : ""}>
                <div data-specific-state><span data-specific-count></span><button type="button" data-specific-open>open</button></div>
            </div>
        </div>
        <button type="button" data-save-draft>Save draft</button>`;

    it("refuses to save with the switch on and nobody chosen, and says why", async () => {
        document.body.innerHTML = SPECIFIC(true, 0);
        const { initializeInspectorPanel } = await load();
        initializeInspectorPanel(1, 9, refresh);

        click("[data-save-draft]");
        await Promise.resolve();

        expect(updateAssessment).not.toHaveBeenCalled();
        expect(showToast).toHaveBeenCalledWith(expect.stringMatching(/at least one student/i), { tone: "danger" });
    });

    it("saves when somebody is chosen, sending the switch with the settings", async () => {
        document.body.innerHTML = SPECIFIC(true, 2);
        updateAssessment.mockResolvedValue({ success: true });
        const { initializeInspectorPanel } = await load();
        initializeInspectorPanel(1, 9, refresh);

        click("[data-save-draft]");
        await vi.waitFor(() => expect(updateAssessment).toHaveBeenCalled());

        expect(updateAssessment.mock.calls[0][2]).toMatchObject({ assign_to_specific_students: true });
    });
});
