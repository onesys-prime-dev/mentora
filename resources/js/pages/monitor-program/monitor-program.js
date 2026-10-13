/*
|--------------------------------------------------------------------------
| Monitor Program — charts
|--------------------------------------------------------------------------
| Every number on this page (snapshot, course comparison, at-risk, audit
| log) is server-rendered — filters (term, date range) live in the address
| and reload the whole page, same convention as the chairperson Courses
| page. This file only draws the two charts, from data the controller
| already embedded on #monitor-program-charts; it never fetches anything
| itself.
|--------------------------------------------------------------------------
*/

import { BarController, BarElement, CategoryScale, Chart, LinearScale, Tooltip } from "chart.js";

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

const BLUE = "#0040A6";
const AMBER = "#946200";

/** @param {Array<{label: string, value: number|null}>} points */
export function completionChartConfig(points) {
    return {
        type: "bar",
        data: {
            labels: points.map((point) => point.label),
            datasets: [{
                label: "Completion %",
                data: points.map((point) => point.value ?? 0),
                backgroundColor: BLUE,
                borderRadius: 4,
            }],
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, max: 100, ticks: { callback: (value) => `${value}%` } } },
        },
    };
}

/** @param {Record<string, number>} distribution bucket label -> student count */
export function gradeDistributionChartConfig(distribution) {
    const labels = Object.keys(distribution);

    return {
        type: "bar",
        data: {
            labels,
            datasets: [{
                label: "Students",
                data: labels.map((label) => distribution[label]),
                backgroundColor: AMBER,
                borderRadius: 4,
            }],
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    };
}

initialize();

function initialize() {
    const root = document.getElementById("monitor-program-charts");

    if (!root) {
        return;
    }

    const completionCanvas = document.getElementById("monitor-program-completion-chart");
    const gradeCanvas = document.getElementById("monitor-program-grade-chart");

    if (completionCanvas) {
        const points = JSON.parse(root.dataset.completion || "[]");
        new Chart(completionCanvas, completionChartConfig(points));
    }

    if (gradeCanvas) {
        const distribution = JSON.parse(root.dataset.gradeDistribution || "{}");
        new Chart(gradeCanvas, gradeDistributionChartConfig(distribution));
    }
}
