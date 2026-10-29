import { describe, expect, it } from "vitest";

import { curveRequest, methodInputs } from "../../../resources/js/pages/courses/features/grades/curve/curve-dialog.js";

describe("what a curve form sends", () => {

    it("curves one item by its id", () => {

        expect(curveRequest("12", "add_points", { points: "5" }, "Long exam")).toEqual({
            scope: "item",
            item_id: 12,
            method: "add_points",
            input: { points: 5 },
            note: "Long exam",
        });

    });

    it("curves the final grade without an item", () => {

        const request = curveRequest("final", "set_mean", { target_mean: "75" });

        expect(request.scope).toBe("final");
        expect(request.item_id).toBeNull();
        expect(request.input).toEqual({ target_mean: 75 });

    });

    it("leaves out the numbers that were not filled in", () => {

        expect(curveRequest("3", "linear", { new_low: "60", new_high: "" }).input).toEqual({ new_low: 60 });

    });

});

describe("which numbers each method asks for", () => {

    it("matches the server's methods", () => {

        expect(methodInputs("add_points")).toEqual(["points"]);
        expect(methodInputs("scale_to_max")).toEqual([]);
        expect(methodInputs("linear")).toEqual(["new_low", "new_high"]);
        expect(methodInputs("set_mean")).toEqual(["target_mean"]);
        expect(methodInputs("unknown")).toEqual([]);

    });

});
