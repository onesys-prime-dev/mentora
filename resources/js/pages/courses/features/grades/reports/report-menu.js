/*
|--------------------------------------------------------------------------
| Grade reports: the Reports menu and the per-student report links
|--------------------------------------------------------------------------
|
| A report is fetched, not just linked to, so that when the server has
| nothing to give ("this section has no grading yet", "your grades are not
| final yet") the person reads that in a message instead of a page of JSON.
| A "print" report opens in its own tab, where the browser prints it.
|
*/

import { showToast } from "../../../../../shared/toast.js";
import { reportUrl } from "../api.js";

/** The file name the server suggested, else a plain fallback. */
export function filenameFrom(contentDisposition, fallback = "report")
{
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(contentDisposition ?? "");

    return match ? decodeURIComponent(match[1]) : fallback;
}

/** Downloads a report file, or shows the reason there is none. */
export async function downloadReport(url)
{
    try {

        const response = await fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } });

        if (!response.ok || (response.headers.get("Content-Type") ?? "").includes("application/json")) {

            let message = "The report could not be made. Try again.";

            try {
                message = (await response.json()).message ?? message;
            } catch {
                // keep the plain message
            }

            showToast(message, { tone: "danger" });

            return false;

        }

        const blob = await response.blob();

        const link = document.createElement("a");

        link.href = URL.createObjectURL(blob);
        link.download = filenameFrom(response.headers.get("Content-Disposition"));

        document.body.append(link);
        link.click();
        link.remove();

        setTimeout(() => URL.revokeObjectURL(link.href), 1000);

        return true;

    } catch (error) {

        console.error(error);

        showToast("The report could not be made. Check your connection and try again.", { tone: "danger" });

        return false;

    }
}

/** Opens a report page (download) or print view for a trigger's data attributes. */
export function openReport(trigger)
{
    const url = reportUrl(trigger.dataset.reportPath, trigger.dataset.reportFormat);

    if (trigger.dataset.reportMode === "print") {
        window.open(url, "_blank", "noopener");
        return Promise.resolve(true);
    }

    return downloadReport(url);
}

/** The toolbar's Reports menu. */
export function bindReportMenu()
{
    const toggle = document.querySelector("[data-toggle-report-menu]");
    const menu = document.querySelector("[data-report-menu]");

    toggle?.addEventListener("click", (event) => {
        event.stopPropagation();
        menu?.classList.toggle("hidden");
        menu?.classList.toggle("flex");
    });

    document.addEventListener("click", (event) => {
        if (menu && !menu.contains(event.target) && event.target !== toggle) {
            menu.classList.add("hidden");
            menu.classList.remove("flex");
        }
    });

    bindReportTriggers(document);
}

/** Any element with data-report-path / -format (/ -mode) downloads or prints that report. */
export function bindReportTriggers(root)
{
    root.querySelectorAll("[data-report-path]").forEach((trigger) => {
        trigger.addEventListener("click", async () => {

            trigger.disabled = true;

            await openReport(trigger);

            trigger.disabled = false;

        });
    });
}
