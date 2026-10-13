import { describe, expect, it } from "vitest";
import { completionChartConfig, gradeDistributionChartConfig } from "../../../resources/js/pages/monitor-program/monitor-program.js";

describe("completionChartConfig", () => {
    it("maps each point's label and value into the bar dataset", () => {
        const config = completionChartConfig([
            { label: "IT101", value: 75.5 },
            { label: "IT102", value: 40 },
        ]);

        expect(config.data.labels).toEqual(["IT101", "IT102"]);
        expect(config.data.datasets[0].data).toEqual([75.5, 40]);
    });

    it("treats a null completion (nothing published yet) as zero rather than dropping the course", () => {
        const config = completionChartConfig([{ label: "IT101", value: null }]);

        expect(config.data.labels).toEqual(["IT101"]);
        expect(config.data.datasets[0].data).toEqual([0]);
    });

    it("caps the y axis at 100 since it's a percentage", () => {
        const config = completionChartConfig([]);

        expect(config.options.scales.y.max).toBe(100);
    });

    it("renders with no courses as an empty chart, not an error", () => {
        const config = completionChartConfig([]);

        expect(config.data.labels).toEqual([]);
        expect(config.data.datasets[0].data).toEqual([]);
    });
});

describe("gradeDistributionChartConfig", () => {
    it("keeps the bucket order given, worst-grade bucket first", () => {
        const config = gradeDistributionChartConfig({ "0-59": 1, "60-69": 0, "90-100": 2 });

        expect(config.data.labels).toEqual(["0-59", "60-69", "90-100"]);
        expect(config.data.datasets[0].data).toEqual([1, 0, 2]);
    });

    it("renders with no graded offerings as all-zero buckets, not an error", () => {
        const config = gradeDistributionChartConfig({ "0-59": 0, "60-69": 0 });

        expect(config.data.datasets[0].data).toEqual([0, 0]);
    });
});
