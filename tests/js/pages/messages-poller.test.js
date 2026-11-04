import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { createGuard, createPoller, watchVisibility } from "../../../resources/js/pages/messages/poller.js";

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

describe("the polling schedule", () => {

    it("runs the task every fast interval while the tab is visible", async () => {

        const task = vi.fn().mockResolvedValue("ok");
        const poller = createPoller({ task, visible: () => true, fast: 5000, slow: 30000 });

        poller.start();
        expect(task).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(5000);
        expect(task).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(5000);
        expect(task).toHaveBeenCalledTimes(2);

        poller.stop();

    });

    it("backs off to the slow interval while the tab is hidden, and returns when it is visible", async () => {

        let shown = true;
        const task = vi.fn().mockResolvedValue("ok");
        const poller = createPoller({ task, visible: () => shown, fast: 5000, slow: 30000 });

        poller.start();
        await vi.advanceTimersByTimeAsync(5000);
        expect(task).toHaveBeenCalledTimes(1);

        shown = false;
        await vi.advanceTimersByTimeAsync(5000);
        expect(task).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(29000);
        expect(task).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(1000);
        expect(task).toHaveBeenCalledTimes(3);

        shown = true;
        await vi.advanceTimersByTimeAsync(30000);
        expect(task).toHaveBeenCalledTimes(4);
        await vi.advanceTimersByTimeAsync(5000);
        expect(task).toHaveBeenCalledTimes(5);

        poller.stop();

    });

    it("stops for good when the task says the conversation is gone", async () => {

        const task = vi.fn().mockResolvedValue("gone");
        const poller = createPoller({ task, visible: () => true, fast: 5000 });

        poller.start();
        await vi.advanceTimersByTimeAsync(5000);
        await vi.advanceTimersByTimeAsync(60000);

        expect(task).toHaveBeenCalledTimes(1);

    });

    it("does not start a tick while the last one is still running", async () => {

        let release;
        const task = vi.fn(() => new Promise((resolve) => { release = resolve; }));
        const poller = createPoller({ task, visible: () => true, fast: 1000 });

        poller.start();
        await vi.advanceTimersByTimeAsync(1000);
        poller.nudge();
        expect(task).toHaveBeenCalledTimes(1);

        release("ok");
        await vi.advanceTimersByTimeAsync(1000);
        expect(task).toHaveBeenCalledTimes(2);

        poller.stop();

    });

    it("runs now on nudge and leaves one timer pending", async () => {

        const task = vi.fn().mockResolvedValue("ok");
        const poller = createPoller({ task, visible: () => true, fast: 5000 });

        poller.start();
        await vi.advanceTimersByTimeAsync(2000);
        poller.nudge();
        await vi.advanceTimersByTimeAsync(0);
        expect(task).toHaveBeenCalledTimes(1);
        expect(vi.getTimerCount()).toBe(1);

        await vi.advanceTimersByTimeAsync(5000);
        expect(task).toHaveBeenCalledTimes(2);

        poller.stop();

    });

    it("keeps polling, slowly, after a task throws", async () => {

        const task = vi.fn().mockRejectedValueOnce(new Error("boom")).mockResolvedValue("ok");
        const poller = createPoller({ task, visible: () => true, fast: 5000, slow: 30000 });

        poller.start();
        await vi.advanceTimersByTimeAsync(5000);
        expect(task).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(29000);
        expect(task).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1000);
        expect(task).toHaveBeenCalledTimes(2);

        poller.stop();

    });

    it("clears its timer when stopped", async () => {

        const poller = createPoller({ task: vi.fn().mockResolvedValue("ok"), visible: () => true, fast: 5000 });

        poller.start();
        poller.stop();

        expect(vi.getTimerCount()).toBe(0);

    });

    it("nudges when the tab becomes visible again", () => {

        const poller = { nudge: vi.fn() };
        const doc = new EventTarget();

        doc.visibilityState = "hidden";
        const unwatch = watchVisibility(poller, doc);

        doc.dispatchEvent(new Event("visibilitychange"));
        expect(poller.nudge).not.toHaveBeenCalled();

        doc.visibilityState = "visible";
        doc.dispatchEvent(new Event("visibilitychange"));
        expect(poller.nudge).toHaveBeenCalledTimes(1);

        unwatch();
        doc.dispatchEvent(new Event("visibilitychange"));
        expect(poller.nudge).toHaveBeenCalledTimes(1);

    });

});

describe("the guard against late answers", () => {

    it("treats only the latest request as current", () => {

        const guard = createGuard();
        const first = guard.next();

        expect(guard.isCurrent(first)).toBe(true);

        const second = guard.next();

        expect(guard.isCurrent(first)).toBe(false);
        expect(guard.isCurrent(second)).toBe(true);

    });

});
