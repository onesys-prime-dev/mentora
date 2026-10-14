import { describe, expect, it } from "vitest";
import { jobIsRunning, jobLabel } from "../../../resources/js/pages/courses/features/assessments/ai-generation/ai-generation.js";

describe("jobIsRunning", () => {
    it("is true while a job is queued or running and false once it has an outcome", () => {
        expect(jobIsRunning({ job: { status: "queued" } })).toBe(true);
        expect(jobIsRunning({ job: { status: "running" } })).toBe(true);
        expect(jobIsRunning({ job: { status: "completed" } })).toBe(false);
        expect(jobIsRunning({ job: { status: "failed" } })).toBe(false);
        expect(jobIsRunning({})).toBe(false);
    });
});

describe("jobLabel", () => {
    it("says what the builder is doing in the job's own words", () => {
        expect(jobLabel({ note: "Writing questions", stage: "digest", done: 0, of: 0 })).toBe("Writing questions");
    });

    it("adds how far writing has got", () => {
        expect(jobLabel({ note: "Writing questions", stage: "write", done: 2, of: 6 })).toBe("Writing questions - 2 of 6");
    });

    it("says when it is waiting for the free allowance and for how long", () => {
        expect(jobLabel({ note: "Writing questions", stage: "write", done: 1, of: 6, waiting_seconds: 40 })).toContain("waiting about 40 s");
    });

    it("has words even before the worker has reported anything", () => {
        expect(jobLabel({ note: null, stage: null, done: 0, of: 0 })).toBe("Working on your questions");
    });
});
