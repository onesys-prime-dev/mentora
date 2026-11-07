/*
|--------------------------------------------------------------------------
| Copy and move a module, a learning unit or a learning item to another course
|--------------------------------------------------------------------------
| One dialog, a few steps, by what is being done:
|
|   copy a module   course > what to copy (units and items, ticked)
|   copy a unit     course > module > what to copy (items, ticked)
|   copy an item    course > module > unit
|   move a module   another course > confirm
|   move a unit     another course > module > confirm
|   move an item    another course > module > unit > confirm
|
| "Course" is this course or one of the person's others (copy), or another one
| (move). The server checks everything again (ContentTransferService); this
| only walks the person through it. Titles are text, never markup.
*/

const STEPS = {
    copy: {
        module: ["course", "tree"],
        unit: ["course", "module", "tree"],
        item: ["course", "module", "unit"],
    },
    move: {
        module: ["course", "confirm"],
        unit: ["course", "module", "confirm"],
        item: ["course", "module", "unit", "confirm"],
    },
};

export function stepsFor(action, kind) {

    return STEPS[action][kind];

}

const NOUN = { module: "module", unit: "learning unit", item: "learning item" };

/**
 * @param {object} options
 * @param {{element: HTMLElement, open: Function, close: Function}|null} options.modal
 * @param {{fetchOfferings: Function, fetchModules: Function, fetchUnits: Function, fetchTree: Function, copy: Function, move: Function}} options.api
 * @param {() => Promise<void>|void} options.refresh reloads the page's list after it worked
 * @param {(message: string, tone: string) => void} options.notify
 */
export function bindContentTransfer({ modal, api, refresh, notify }) {

    if (!modal) {
        return;
    }

    const root = modal.element;
    const q = (selector) => root.querySelector(selector);

    const title = q("[data-transfer-title]");
    const subtitle = q("[data-transfer-subtitle]");
    const progress = q("[data-transfer-progress]");
    const error = q("[data-transfer-error]");
    const backButton = q("[data-transfer-back]");
    const nextButton = q("[data-transfer-next]");

    const panel = (step) => q(`[data-transfer-step="${step}"]`);

    let state = null;

    const showError = (message) => {

        error.textContent = message ?? "";
        error.classList.toggle("hidden", !message);

    };

    function optionText(select) {

        return select.options[select.selectedIndex]?.textContent ?? "";

    }

    function fill(select, rows, text) {

        const previous = select.value;

        select.replaceChildren();

        rows.forEach((row) => {

            const option = document.createElement("option");

            option.value = String(row.id);
            option.textContent = text(row);
            select.append(option);

        });

        if (rows.some((row) => String(row.id) === previous)) {
            select.value = previous;
        }

    }

    function setBusy(busy) {

        state.busy = busy;
        nextButton.disabled = busy || state.blocked === true;
        backButton.disabled = busy;

    }

    function block(blocked) {

        state.blocked = blocked;
        nextButton.disabled = blocked || state.busy;

    }

    /*
    | Showing a step
    */

    async function enter(step) {

        root.querySelectorAll("[data-transfer-step]").forEach((el) => el.classList.add("hidden"));
        panel(step)?.classList.remove("hidden");

        showError("");
        block(false);

        const steps = state.steps;
        const last = state.index === steps.length - 1;

        progress.textContent = `Step ${state.index + 1} of ${steps.length}`;
        backButton.classList.toggle("hidden", state.index === 0);
        nextButton.textContent = last ? (state.action === "copy" ? "Copy" : "Move") : "Next";

        try {

            setBusy(true);

            if (step === "course") {
                renderCourse();
            } else if (step === "module") {
                await renderModules();
            } else if (step === "unit") {
                await renderUnits();
            } else if (step === "tree") {
                await renderTree();
            } else if (step === "confirm") {
                renderConfirm();
            }

        } catch (failure) {

            console.error(failure);

            showError("That could not be loaded. Check your connection and try again.");
            block(true);

        } finally {

            setBusy(false);

        }

    }

    function renderCourse() {

        const moving = state.action === "move";
        const others = state.offerings.filter((offering) => !offering.is_current);

        q("[data-transfer-this-row]").classList.toggle("hidden", moving);

        const select = q("[data-transfer-offering]");

        fill(select, others, (offering) => `${offering.label} · ${offering.title}${offering.term ? ` (${offering.term})` : ""}`);

        const scope = moving ? "other" : state.scope;

        root.querySelectorAll("[data-transfer-scope]").forEach((radio) => {
            radio.checked = radio.value === scope;
        });

        select.disabled = !(scope === "other");

        q("[data-transfer-no-other]").classList.toggle("hidden", !(moving && others.length === 0));

        // Nowhere to move it to.
        block(moving && others.length === 0);

    }

    async function renderModules() {

        const modules = await api.fetchModules(state.targetOffering);

        fill(q("[data-transfer-module]"), modules, (module) => (module.status === "published" ? module.title : `${module.title} (${module.status})`));

        q("[data-transfer-no-modules]").classList.toggle("hidden", modules.length > 0);
        q("[data-transfer-no-modules]").textContent = "This course has no modules yet. Add one there first.";

        block(modules.length === 0);

    }

    async function renderUnits() {

        const units = await api.fetchUnits(state.targetModule);

        fill(q("[data-transfer-unit]"), units, (unit) => (unit.status === "published" ? unit.title : `${unit.title} (${unit.status})`));

        q("[data-transfer-no-units]").classList.toggle("hidden", units.length > 0);
        q("[data-transfer-no-units]").textContent = "This module has no learning units yet. Add one there first.";

        block(units.length === 0);

    }

    async function renderTree() {

        const tree = await api.fetchTree(state.kind, state.id);
        const box = q("[data-transfer-tree]");

        box.replaceChildren();

        tree.units.forEach((unit) => {

            const group = document.createElement("div");

            group.className = "flex flex-col gap-1";

            if (state.kind === "module") {

                const head = document.createElement("label");
                const unitBox = document.createElement("input");
                const name = document.createElement("span");

                head.className = "flex cursor-pointer items-center gap-2 text-sm font-semibold text-gray-800";
                unitBox.type = "checkbox";
                unitBox.className = "accent-brand";
                unitBox.value = String(unit.id);
                unitBox.checked = true;
                unitBox.dataset.transferUnitBox = "";
                name.textContent = unit.title;

                head.append(unitBox, name);
                group.append(head);

                unitBox.addEventListener("change", () => {

                    group.querySelectorAll("[data-transfer-item]").forEach((item) => {
                        item.checked = unitBox.checked;
                    });

                });

            }

            unit.items.forEach((item) => {

                const row = document.createElement("label");
                const itemBox = document.createElement("input");
                const name = document.createElement("span");
                const type = document.createElement("span");

                row.className = `flex cursor-pointer items-center gap-2 text-sm text-gray-700 ${state.kind === "module" ? "pl-6" : ""}`;
                itemBox.type = "checkbox";
                itemBox.className = "accent-brand";
                itemBox.value = String(item.id);
                itemBox.checked = true;
                itemBox.dataset.transferItem = "";
                name.className = "flex-1 truncate";
                name.textContent = item.title;
                type.className = "shrink-0 text-xs capitalize text-[#767676]";
                type.textContent = item.item_type;

                row.append(itemBox, name, type);
                group.append(row);

                // An item chosen brings its unit.
                itemBox.addEventListener("change", () => {

                    const unitBox = group.querySelector("[data-transfer-unit-box]");

                    if (itemBox.checked && unitBox) {
                        unitBox.checked = true;
                    }

                });

            });

            box.append(group);

        });

    }

    function renderConfirm() {

        const where = [state.offeringLabel, state.moduleTitle, state.unitTitle].filter(Boolean).join(" › ");

        q("[data-transfer-confirm-text]").textContent =
            `Move “${state.title}” to ${where}? It becomes a draft there until you publish it, and it leaves this course. `
            + "Anything that waited on it here stops waiting. Students have not worked on it, or it could not be moved.";

    }

    /*
    | Going on
    */

    function chosen(step) {

        if (step === "course") {

            const scope = root.querySelector("[data-transfer-scope]:checked")?.value ?? null;

            if (scope === "this" && state.action === "copy") {

                const current = state.offerings.find((offering) => offering.is_current);

                state.targetOffering = current?.id ?? null;
                state.offeringLabel = current?.label ?? "";
                state.scope = "this";

            } else if (scope === "other") {

                const select = q("[data-transfer-offering]");

                state.targetOffering = select.value ? Number(select.value) : null;
                state.offeringLabel = optionText(select).split(" · ").slice(0, 2).join(" · ");
                state.scope = "other";

            } else {

                state.targetOffering = null;

            }

            return state.targetOffering === null ? "Choose this course or another course." : null;

        }

        if (step === "module") {

            const select = q("[data-transfer-module]");

            state.targetModule = select.value ? Number(select.value) : null;
            state.moduleTitle = optionText(select);

            return state.targetModule === null ? "Choose a module." : null;

        }

        if (step === "unit") {

            const select = q("[data-transfer-unit]");

            state.targetUnit = select.value ? Number(select.value) : null;
            state.unitTitle = optionText(select);

            return state.targetUnit === null ? "Choose a learning unit." : null;

        }

        return null;

    }

    function target() {

        const where = { offering_id: state.targetOffering };

        if (state.kind !== "module") {
            where.module_id = state.targetModule;
        }

        if (state.kind === "item") {
            where.unit_id = state.targetUnit;
        }

        return where;

    }

    const ticked = (selector) => [...root.querySelectorAll(`${selector}:checked`)].map((box) => Number(box.value));

    async function submit() {

        const payload = { kind: state.kind, id: state.id, target: target() };

        if (state.action === "copy") {

            payload.unit_ids = state.kind === "module" ? ticked("[data-transfer-unit-box]") : null;
            payload.item_ids = state.kind === "item" ? null : ticked("[data-transfer-item]");

        }

        setBusy(true);
        showError("");

        try {

            const result = await (state.action === "copy" ? api.copy(payload) : api.move(payload));

            if (!result?.success) {

                showError(result?.message || `It could not be ${state.action === "copy" ? "copied" : "moved"}.`);

                return;

            }

            modal.close();
            notify(result.message, "success");

            await refresh();

        } catch (failure) {

            console.error(failure);

            showError(`It could not be ${state.action === "copy" ? "copied" : "moved"}. Check your connection and try again.`);

        } finally {

            setBusy(false);

        }

    }

    async function advance() {

        if (!state || state.busy) {
            return;
        }

        const step = state.steps[state.index];
        const problem = chosen(step);

        if (problem) {

            showError(problem);

            return;

        }

        if (state.index === state.steps.length - 1) {

            await submit();

            return;

        }

        state.index++;

        await enter(state.steps[state.index]);

    }

    async function back() {

        if (!state || state.busy || state.index === 0) {
            return;
        }

        state.index--;

        await enter(state.steps[state.index]);

    }

    nextButton.addEventListener("click", advance);
    backButton.addEventListener("click", back);

    root.querySelectorAll("[data-transfer-scope]").forEach((radio) => {

        radio.addEventListener("change", () => {

            q("[data-transfer-offering]").disabled = radio.value !== "other" || !radio.checked;

        });

    });

    /*
    | Opening
    */

    document.querySelectorAll("[data-transfer]").forEach((button) => {

        button.addEventListener("click", async (event) => {

            event.preventDefault();
            event.stopPropagation();

            const action = button.dataset.transferAction;
            const kind = button.dataset.transferKind;

            state = {
                action,
                kind,
                id: Number(button.dataset.transferId),
                title: button.dataset.transferName ?? "",
                steps: stepsFor(action, kind),
                index: 0,
                offerings: [],
                scope: null,
                targetOffering: null,
                targetModule: null,
                targetUnit: null,
                offeringLabel: "",
                moduleTitle: "",
                unitTitle: "",
                busy: false,
                blocked: false,
            };

            title.textContent = `${action === "copy" ? "Copy" : "Move"} “${state.title}”`;
            subtitle.textContent = action === "copy"
                ? `Copy this ${NOUN[kind]} to this course or another one. The copy is a draft.`
                : `Move this ${NOUN[kind]} to another course. It becomes a draft there.`;

            root.querySelectorAll("[data-transfer-step]").forEach((el) => el.classList.add("hidden"));
            showError("");
            backButton.classList.add("hidden");
            progress.textContent = "";

            modal.open();

            try {

                setBusy(true);

                state.offerings = await api.fetchOfferings();

            } catch (failure) {

                console.error(failure);

                panel("course")?.classList.remove("hidden");
                showError("Your courses could not be loaded. Check your connection and try again.");
                state.blocked = true;
                setBusy(false);

                return;

            }

            await enter("course");

        });

    });

}
