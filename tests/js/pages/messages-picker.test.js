import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { createPicker } from "../../../resources/js/pages/messages/picker.js";

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

const found = (...people) => ({ status: 200, data: { recipients: people } });
const ann = { id: 4, name: "Ann Lee", role: "instructor", subject_student_id: null, subject_name: null };

describe("the new-message picker", () => {

    it("searches once, after the typing pauses", async () => {

        const search = vi.fn().mockResolvedValue(found(ann));
        const picker = createPicker({ search, onPick: () => {}, debounce: 250 });

        picker.input("An");
        picker.input("Ann");
        picker.input("Ann L");
        await vi.advanceTimersByTimeAsync(249);
        expect(search).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(search).toHaveBeenCalledTimes(1);
        expect(search).toHaveBeenCalledWith("Ann L");
        expect(picker.results).toEqual([ann]);

    });

    it("does not search for fewer than two characters and clears the results", async () => {

        const search = vi.fn().mockResolvedValue(found(ann));
        const picker = createPicker({ search, onPick: () => {}, debounce: 10 });

        picker.input("Ann");
        await vi.advanceTimersByTimeAsync(10);
        expect(picker.results).toHaveLength(1);

        picker.input("A");
        await vi.advanceTimersByTimeAsync(100);

        expect(search).toHaveBeenCalledTimes(1);
        expect(picker.results).toEqual([]);
        expect(picker.state).toBe("idle");

    });

    it("ignores an answer for a query that is no longer current", async () => {

        let releaseFirst;
        const search = vi.fn()
            .mockImplementationOnce(() => new Promise((resolve) => { releaseFirst = resolve; }))
            .mockResolvedValueOnce(found({ ...ann, id: 9, name: "Bo Ann" }));
        const picker = createPicker({ search, onPick: () => {}, debounce: 10 });

        picker.input("ann");
        await vi.advanceTimersByTimeAsync(10);
        picker.input("bo ann");
        await vi.advanceTimersByTimeAsync(10);
        expect(picker.results.map((r) => r.id)).toEqual([9]);

        releaseFirst(found(ann));
        await vi.advanceTimersByTimeAsync(0);

        expect(picker.results.map((r) => r.id)).toEqual([9]);

    });

    it("says so when nobody matches and when the search fails", async () => {

        const search = vi.fn().mockResolvedValueOnce(found()).mockResolvedValueOnce({ status: 0, data: null });
        const picker = createPicker({ search, onPick: () => {}, debounce: 10 });

        picker.input("zz");
        await vi.advanceTimersByTimeAsync(10);
        expect(picker.state).toBe("empty");

        picker.input("zzz");
        await vi.advanceTimersByTimeAsync(10);
        expect(picker.state).toBe("error");
        expect(picker.results).toEqual([]);

    });

    it("passes the chosen person, with the child they are about, on", () => {

        const onPick = vi.fn();
        const picker = createPicker({ search: vi.fn(), onPick });
        const parentEntry = { ...ann, subject_student_id: 12, subject_name: "Stu Dent" };

        picker.pick(parentEntry);

        expect(onPick).toHaveBeenCalledWith(parentEntry);

    });

    it("tells its owner when it changes", async () => {

        const onChange = vi.fn();
        const picker = createPicker({ search: vi.fn().mockResolvedValue(found(ann)), onPick: () => {}, onChange, debounce: 10 });

        picker.input("Ann");
        await vi.advanceTimersByTimeAsync(10);

        expect(onChange).toHaveBeenCalled();
        expect(picker.state).toBe("idle");

    });

});
