import { describe, expect, it } from "vitest";

import { notificationSkeletonRows } from "../../../resources/js/shared/notifications-menu.js";

describe("the notifications panel's loading rows", () => {

    it("draws one skeleton row per requested row, hidden from screen readers", () => {

        const list = document.createElement("ul");

        list.innerHTML = notificationSkeletonRows(4);

        const rows = list.querySelectorAll("[data-notification-skeleton]");

        expect(rows).toHaveLength(4);
        rows.forEach((row) => expect(row.getAttribute("aria-hidden")).toBe("true"));

    });

    it("defaults to a small batch", () => {

        const list = document.createElement("ul");

        list.innerHTML = notificationSkeletonRows();

        expect(list.querySelectorAll("[data-notification-skeleton]").length).toBeGreaterThan(0);

    });

});
