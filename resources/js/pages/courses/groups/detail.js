/*
|--------------------------------------------------------------------------
| The set detail: groups, their members and the ungrouped students
|--------------------------------------------------------------------------
| Drawn from GroupSetsController::show. Every write control carries
| data-group-action; with canEdit false none is drawn (the dean, finalized
| grades, a closed course). Badges use components/status-badge's classes.
*/

export const MODES = {
    manual: "Manual",
    auto: "Auto-generated",
    self_signup: "Self-signup",
    proposed: "Proposed by students",
};

/** The modes whose students join in a window. */
export const SIGNUP_MODES = ["self_signup", "proposed"];

const BADGE = "inline-flex shrink-0 items-center gap-1 rounded-full px-3 py-1 text-xs font-bold";

const TONES = {
    success: "bg-[#E8F5EC] text-[#2B7549]",
    warning: "bg-[#FFF1D6] text-[#946200]",
    neutral: "bg-gray-100 text-[#646464]",
};

export const SMALL = "rounded-full px-3 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11";

const SECONDARY = "inline-flex items-center justify-center gap-2 rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11";

const LABEL = "font-heading text-xs font-semibold uppercase tracking-wide text-[#646464]";

export const escapeHtml = (text) => String(text ?? "").replace(/[&<>"']/g, (c) => (
    { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c]
));

export const badge = (label, tone) => `<span class="${BADGE} ${TONES[tone]}" data-status-badge>${escapeHtml(label)}</span>`;

const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

/** "2026-12-12 17:00:00" -> "12 Dec 2026, 5:00 PM" (the guide's date form). */
export function formatDateTime(value) {

    const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value ?? ""));

    if (!match) {
        return "";
    }

    const [, y, m, d, h, min] = match;
    const hour = Number(h) % 12 || 12;

    return `${Number(d)} ${MONTHS[Number(m) - 1]} ${y}, ${hour}:${min} ${Number(h) < 12 ? "AM" : "PM"}`;

}

/** The size badge of a group: "4 / 5", or "2 / 5 · below minimum" in the warning tone. */
export function capacityBadge(count, set) {

    const below = count < Number(set.min_size);

    return badge(`${count} / ${set.max_size}${below ? " · below minimum" : ""}`, below ? "warning" : "success");

}

function memberChip(student, canEdit, ungrouped) {

    const tone = ungrouped ? "bg-[#FFF1D6] text-[#6F4A00]" : "bg-brand-tint text-[#1A1C1E]";
    const name = escapeHtml(student.name);

    return `
        <li class="inline-flex max-w-full items-center gap-1 rounded-lg ${tone} py-1 ${canEdit ? "pl-3 pr-1" : "px-3"} text-sm" data-member="${student.student_user_id}"${canEdit ? " draggable=\"true\"" : ""}>
            <span class="truncate" data-member-name>${name}</span>
            ${canEdit ? `<button type="button" class="${SMALL} text-brand hover:bg-white" data-group-action="move" data-student="${student.student_user_id}" aria-label="Move ${name}…">Move…</button>` : ""}
        </li>`;

}

/**
 * decorate(group) lets a screen add to a card: { badge, actions } (html, actions sit beside Rename) and locked
 * (members cannot be moved and Delete is gone). The activity groups use it for frozen groups and the leader.
 */
function groupCard(group, set, canEdit, decorate) {

    const name = escapeHtml(group.name);
    const extra = decorate?.(group) ?? {};
    const memberEdit = canEdit && !extra.locked;
    const members = group.members.length === 0
        ? "<p class=\"text-xs text-[#646464]\">No members yet.</p>"
        : `<ul class="flex flex-wrap gap-1.5" aria-label="Members of ${name}">${group.members.map((s) => memberChip(s, memberEdit, false)).join("")}</ul>`;

    return `
        <article class="flex flex-col gap-2 rounded-xl border border-gray-200 bg-white p-3 transition" data-group-card="${group.id}" data-drop-zone="${group.id}" aria-label="${name}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h4 class="font-semibold text-[#1A1C1E]" data-group-name>${name}</h4>
                <div class="flex flex-wrap items-center gap-1" data-group-count="${group.id}">
                    ${capacityBadge(group.members.length, set)}
                    ${extra.badge ?? ""}
                    ${canEdit ? `
                        <button type="button" class="${SMALL} text-brand hover:bg-brand-tint" data-group-action="rename-group" data-group="${group.id}" aria-label="Rename ${name}">Rename</button>
                        ${extra.actions ?? ""}
                        ${extra.locked ? "" : `<button type="button" class="${SMALL} text-[#C33939] hover:bg-[#FDEDEC]" data-group-action="delete-group" data-group="${group.id}" aria-label="Delete ${name}">Delete</button>`}` : ""}
                </div>
            </div>
            ${members}
        </article>`;

}

/**
 * The groups and the ungrouped students: the Groups tab's set detail and the activity editor's groups share it.
 * set carries min_size/max_size for the size badges.
 */
export function renderGroupBoard({ groups, ungrouped, set, canEdit, decorate = null, emptyText = "The instructor has not added any groups to this set." }) {

    const groupList = groups.length === 0
        ? `<div class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-gray-200 px-6 py-8 text-center" data-groups-empty>
                <span class="text-sm font-semibold text-[#646464]">No groups yet</span>
                <span class="text-xs text-[#646464]">${canEdit ? "Add a group, then place students in it." : emptyText}</span>
           </div>`
        : groups.map((g) => groupCard(g, set, canEdit, decorate)).join("");

    const pool = ungrouped.length === 0
        ? "<p class=\"text-xs text-[#646464]\">Everyone is in a group.</p>"
        : `<ul class="flex flex-wrap gap-1.5" aria-label="Ungrouped students">${ungrouped.map((s) => memberChip(s, canEdit, true)).join("")}</ul>
           <p class="text-xs text-[#6F4A00]">Not in a group yet.</p>`;

    return `
            <div class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr)_14rem]">

                <div class="flex min-w-0 flex-col gap-3">
                    <h4 class="${LABEL}">Groups</h4>
                    ${groupList}
                    ${canEdit ? `<div><button type="button" class="${SECONDARY}" data-group-action="add-group">Add group</button></div>` : ""}
                </div>

                <div class="flex min-w-0 flex-col gap-3">
                    <h4 class="${LABEL}">Ungrouped (<span data-ungrouped-count>${ungrouped.length}</span>)</h4>
                    <div class="flex flex-col gap-2 rounded-xl border border-gray-200 bg-gray-50 p-3 transition" data-drop-zone="">
                        ${pool}
                    </div>
                    ${canEdit ? "<p class=\"text-xs text-[#646464]\">Use Move… to pick a group for a student. Dragging a name onto a group works too.</p>" : ""}
                </div>

            </div>`;

}

/** The whole detail of one set. */
export function renderSetDetail(detail, canEdit) {

    const { set, groups, ungrouped } = detail;
    const placed = groups.reduce((sum, g) => sum + g.members.length, 0);
    const total = placed + ungrouped.length;

    const facts = [
        `Size ${set.min_size}–${set.max_size}`,
        MODES[set.formation_mode] ?? MODES.manual,
    ];

    if (SIGNUP_MODES.includes(set.formation_mode) && set.signup_closes_at) {
        facts.push(`Sign-up closes ${formatDateTime(set.signup_closes_at)}`);
    }

    facts.push(`${placed} of ${total} students placed`);

    return `
        <div class="flex flex-col gap-4" data-set-detail="${set.id}">

            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 flex-col gap-1">
                    <h3 class="text-lg font-bold text-brand" data-set-name>${escapeHtml(set.name)}</h3>
                    <p class="text-xs text-[#646464]" data-set-facts>${facts.map(escapeHtml).join(" · ")}</p>
                </div>
                ${canEdit ? `
                    <div class="flex flex-wrap gap-2 max-xs:w-full max-xs:*:flex-1">
                        <button type="button" class="${SECONDARY}" data-group-action="generate">Generate groups</button>
                        <button type="button" class="${SECONDARY}" data-group-action="edit-set">Edit set</button>
                        <button type="button" class="${SECONDARY}" data-group-action="archive-set">Archive</button>
                    </div>` : ""}
            </div>

            ${renderGroupBoard({ groups, ungrouped, set, canEdit })}

        </div>`;

}

/** The detail's own error: what happened, that nothing was lost, Try again. */
export function renderDetailError() {

    return `
        <div class="flex flex-col items-start gap-3" data-detail-error>
            <div class="flex items-start gap-3 rounded-2xl border border-[#C33939]/30 bg-[#FDEDEC] px-4 py-3 text-sm text-[#8E2424]" role="alert">
                <span class="sr-only">Problem: </span>
                <p>We couldn't load this group set. Nothing was changed. Check your connection and try again.</p>
            </div>
            <button type="button" class="${SECONDARY}" data-detail-retry>Try again</button>
        </div>`;

}
