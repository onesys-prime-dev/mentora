import { applyFieldErrors } from "../../../shared/dialog.js";
import { showToast } from "../../../shared/toast.js";

import { sendChange } from "./api.js";
import { capacityBadge, escapeHtml } from "./detail.js";

/*
|--------------------------------------------------------------------------
| Generate groups (group activities, screen 4A)
|--------------------------------------------------------------------------
| Step 1 asks how to split (size or count, pairs to keep together or apart); step 2
| shows the server's preview, "Nothing has been saved yet". The save sends the
| previewed groups; the server checks them again. Saving over existing groups
| asks first, naming what will be deleted.
*/

const CHIP = "inline-flex max-w-full items-center rounded-lg bg-brand-tint px-3 py-1 text-sm text-[#1A1C1E]";
const ALERT = "rounded-2xl border border-[#946200]/30 bg-[#FFF1D6] px-4 py-3 text-sm text-[#6F4A00]";
const REMOVE = "rounded-full px-3 py-1 text-xs font-semibold text-[#C33939] transition hover:bg-[#FDEDEC] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11";
const NETWORK = "We could not reach the server. Nothing was saved. Check your connection and try again.";

const plural = (n, word) => `${n} ${word}${n === 1 ? "" : "s"}`;

/**
 * @param {object} ctx
 * @param {HTMLElement} ctx.page the Groups page
 * @param {Record<string, {open: Function, close: Function}>} ctx.dialogs
 * @param {() => object} ctx.getDetail the open set's detail (set, groups, ungrouped)
 * @param {Function} ctx.onSubmit (form, send) the tab's shared submit wrapper
 * @param {() => Promise<void>} ctx.onSaved called after a save, to redraw the set
 */
export function bindGenerate({ page, dialogs, getDetail, onSubmit, onSaved }) {

    const root = page.querySelector("#group-generate-dialog");
    const confirm = page.querySelector("#group-generate-replace-confirm");

    if (!root || !confirm) {
        return {};
    }

    const form = root.querySelector("[data-group-generate-form]");
    const saveForm = root.querySelector("[data-group-generate-save-form]");
    const step1 = root.querySelector("[data-generate-step1]");
    const step2 = root.querySelector("[data-generate-step2]");
    const run = { pool: [], pairs: { together: [], apart: [] }, request: null, preview: null };

    const nameOf = (id) => run.pool.find((s) => s.student_user_id === id)?.name ?? "";

    const show = (step) => {
        step1.hidden = step !== 1;
        step2.hidden = step !== 2;
    };

    function renderPairs(kind) {

        const list = root.querySelector(`[data-pair-list="${kind}"]`);

        list.innerHTML = run.pairs[kind].map(([a, b], i) => `
            <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-1 text-sm" data-pair>
                <span>${escapeHtml(nameOf(a))} and ${escapeHtml(nameOf(b))}</span>
                <button type="button" class="${REMOVE}" data-pair-remove="${kind}:${i}" aria-label="Remove ${escapeHtml(nameOf(a))} and ${escapeHtml(nameOf(b))}">Remove</button>
            </li>`).join("");

    }

    function open(opener) {

        const detail = getDetail();

        run.pool = [...detail.groups.flatMap((g) => g.members), ...detail.ungrouped]
            .sort((a, b) => a.name.localeCompare(b.name));
        run.pairs = { together: [], apart: [] };
        run.request = null;
        run.preview = null;

        form.reset();
        applyFieldErrors(form, {});
        applyFieldErrors(saveForm, {});
        root.querySelector("[data-generate-pool]").textContent = `Pool: ${plural(run.pool.length, "student")}`;

        for (const name of ["together_a", "together_b", "apart_a", "apart_b"]) {
            form.elements[name].replaceChildren(...run.pool.map((s) => new Option(s.name, String(s.student_user_id))));
        }

        renderPairs("together");
        renderPairs("apart");
        show(1);
        dialogs["group-generate-dialog"].open(opener);

    }

    function addPair(kind) {

        const a = Number(form.elements[`${kind}_a`].value);
        const b = Number(form.elements[`${kind}_b`].value);
        const problem = !a || !b ? "Pick two students."
            : a === b ? "Pick two different students."
                : run.pairs[kind].some(([x, y]) => (x === a && y === b) || (x === b && y === a)) ? "That pair is already in the list." : "";

        applyFieldErrors(form, {}, problem);

        if (!problem) {
            run.pairs[kind].push([a, b]);
            renderPairs(kind);
        }

    }

    form.addEventListener("click", (event) => {

        const add = event.target.closest("[data-pair-add]");
        const remove = event.target.closest("[data-pair-remove]");

        if (add) {
            addPair(add.dataset.pairAdd);
        } else if (remove) {
            const [kind, index] = remove.dataset.pairRemove.split(":");

            run.pairs[kind].splice(Number(index), 1);
            renderPairs(kind);
        }

    });

    function renderPreview() {

        const { groups, ungrouped, conflicts, replaces } = run.preview;
        const { set } = getDetail();

        root.querySelector("[data-generate-preview]").innerHTML = groups.map((g, i) => `
            <div class="flex flex-col gap-2 rounded-xl border border-gray-200 bg-white p-3" data-preview-group>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h4 class="font-semibold text-[#1A1C1E]">Group ${i + 1}</h4>
                    ${capacityBadge(g.members.length, set)}
                </div>
                <ul class="flex flex-wrap gap-1.5" aria-label="Members of Group ${i + 1}">${g.members.map((m) => `<li class="${CHIP}">${escapeHtml(m.name)}</li>`).join("")}</ul>
            </div>`).join("") + (ungrouped.length === 0 ? "" : `
            <div class="flex flex-col gap-2 rounded-xl border border-gray-200 bg-gray-50 p-3" data-preview-ungrouped>
                <h4 class="font-semibold text-[#1A1C1E]">Ungrouped (${ungrouped.length})</h4>
                <ul class="flex flex-wrap gap-1.5" aria-label="Ungrouped students">${ungrouped.map((m) => `<li class="${CHIP}">${escapeHtml(m.name)}</li>`).join("")}</ul>
            </div>`);

        const alerts = conflicts.map((c) => `<div class="${ALERT}" role="alert" data-preview-conflict><span class="sr-only">Warning: </span>${escapeHtml(c)}</div>`);

        if (replaces > 0) {
            alerts.push(`<div class="${ALERT}" role="alert" data-preview-replaces><span class="sr-only">Warning: </span>This set already has ${plural(replaces, "group")}. Saving replaces them.</div>`);
        }

        root.querySelector("[data-generate-alerts]").innerHTML = alerts.join("");

    }

    /** Asks the server for a preview of the request; the page changes only on a yes. */
    async function requestPreview(body) {

        const result = await sendChange("POST", `/${getDetail().set.id}/generate/preview`, body);

        if (result.ok) {
            run.request = body;
            run.preview = result.body;
            applyFieldErrors(saveForm, {});
            renderPreview();
            show(2);
            saveForm.querySelector("[type=submit]").focus();
        }

        return result;

    }

    onSubmit(form, (data) => requestPreview({
        by: data.get("by"),
        n: Number(data.get("n")),
        together: run.pairs.together,
        apart: run.pairs.apart,
    }));

    root.querySelector("[data-generate-shuffle]").addEventListener("click", async (event) => {

        const button = event.currentTarget;

        button.disabled = true;

        try {
            const result = await requestPreview({ ...run.request, seed: Math.floor(Math.random() * 2 ** 31) });

            if (!result.ok) {
                applyFieldErrors(saveForm, result.body.errors ?? {}, result.body.message);
            }
        } catch (error) {
            console.error(error);
            applyFieldErrors(saveForm, {}, NETWORK);
        } finally {
            button.disabled = false;
        }

    });

    root.querySelector("[data-generate-back]").addEventListener("click", () => {
        applyFieldErrors(form, {});
        show(1);
        form.elements.by.focus();
    });

    async function save(replace) {

        const groups = run.preview.groups.map((g) => g.members.map((m) => m.student_user_id));
        const result = await sendChange("POST", `/${getDetail().set.id}/generate`, { groups, replace });

        if (result.ok) {
            dialogs["group-generate-dialog"].close({ instant: true });
            showToast(`${plural(groups.length, "group")} created`, { tone: "success" });
            await onSaved();
        }

        return result;

    }

    onSubmit(saveForm, async () => {

        if (run.preview.replaces > 0) {
            dialogs["group-generate-replace-confirm"].open(saveForm.querySelector("[type=submit]"));

            return undefined;
        }

        return save(false);

    });

    onSubmit(confirm.querySelector("form"), async () => {

        dialogs["group-generate-replace-confirm"].close({ instant: true });

        try {
            const result = await save(true);

            if (!result.ok) {
                applyFieldErrors(saveForm, result.body.errors ?? {}, result.body.message);
            }
        } catch (error) {
            console.error(error);
            applyFieldErrors(saveForm, {}, NETWORK);
        }

        return undefined;

    });

    return { open };

}
