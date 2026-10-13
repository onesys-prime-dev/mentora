import { afterEach, describe, expect, it } from "vitest";
import { enabledFeatures, isFeatureEnabled } from "../../../resources/js/shared/features.js";

/** A body-like element carrying the given data-features value. */
function page(value) {
    const el = document.createElement("div");

    if (value !== undefined) {
        el.dataset.features = value;
    }

    return el;
}

afterEach(() => {
    document.body.removeAttribute("data-features");
});

describe("enabledFeatures", () => {
    it("reads the flags the server printed on the page", () => {
        expect(enabledFeatures(page('{"sis.sync":true,"ai.question_generation":false}'))).toEqual({
            "sis.sync": true,
            "ai.question_generation": false,
        });
    });

    it("reads the page body by default", () => {
        document.body.dataset.features = '{"sis.sync":true}';

        expect(enabledFeatures()).toEqual({ "sis.sync": true });
    });

    it("treats a missing attribute as every flag off", () => {
        expect(enabledFeatures(page())).toEqual({});
        expect(enabledFeatures(page(""))).toEqual({});
    });

    it("treats an unreadable attribute as every flag off", () => {
        expect(enabledFeatures(page("{not json"))).toEqual({});
        expect(enabledFeatures(page("null"))).toEqual({});
        expect(enabledFeatures(page("[true]"))).toEqual({});
        expect(enabledFeatures(page('"sis.sync"'))).toEqual({});
    });

    it("copes with no element at all", () => {
        expect(enabledFeatures(null)).toEqual({});
    });

    it("counts only a real true as on", () => {
        expect(enabledFeatures(page('{"a":true,"b":1,"c":"true","d":null,"e":false}'))).toEqual({
            a: true,
            b: false,
            c: false,
            d: false,
            e: false,
        });
    });
});

describe("isFeatureEnabled", () => {
    it("is true only for a flag that is on", () => {
        const el = page('{"sis.sync":true,"ai.question_generation":false}');

        expect(isFeatureEnabled("sis.sync", el)).toBe(true);
        expect(isFeatureEnabled("ai.question_generation", el)).toBe(false);
    });

    it("is false for a flag the page does not mention", () => {
        expect(isFeatureEnabled("something.else", page('{"sis.sync":true}'))).toBe(false);
    });

    it("is false everywhere when the attribute is missing", () => {
        expect(isFeatureEnabled("sis.sync", page())).toBe(false);
    });
});
