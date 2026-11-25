// Mirrors app/Views/components/avatar.php and AvatarData::COLOURS (same order, same id % 8 rule).
export const COLOURS = ["#B91C1C", "#C2410C", "#A16207", "#15803D", "#0F766E", "#1D4ED8", "#6D28D9", "#BE185D"];

const SIZES = {
    xs: "h-6 w-6 text-[10px]",
    sm: "h-8 w-8 text-xs",
    md: "h-12 w-12 text-base",
    lg: "h-14 w-14 text-lg",
    xl: "h-18 w-18 text-2xl",
};

/**
 * person is AvatarData's JSON: interactive true draws the button shared/person-card.js opens; decorative (ignored
 * when interactive) hides an avatar that sits beside the person's visible name from screen readers.
 *
 * @param {{id:number, name:string, initials:string, colour?:string, photoUrl?:?string, photoUrlLg?:?string, interactive?:boolean}} person
 */
export function renderAvatar(person, size = "md", { decorative = false } = {}) {
    const interactive = person.interactive === true;
    const hidden = decorative && !interactive;
    const el = document.createElement("span");
    el.className = `${SIZES[size] ?? SIZES.md} shrink-0 inline-flex items-center justify-center overflow-hidden rounded-full font-bold text-white select-none`;
    // Only the fixed set is ever written into the style, as components/avatar does.
    el.style.backgroundColor = COLOURS.includes(person.colour) ? person.colour : COLOURS[person.id % 8];
    el.dataset.avatar = "";

    if (hidden) {
        el.setAttribute("aria-hidden", "true");
    }

    // The 256 px photo for the larger sizes, as components/avatar does.
    const photo = (size === "lg" || size === "xl") && person.photoUrlLg ? person.photoUrlLg : person.photoUrl;

    if (photo) {
        const img = document.createElement("img");
        img.className = "h-full w-full object-cover";
        img.src = photo;
        img.alt = hidden ? "" : `Photo of ${person.name}`;
        el.append(img);
    } else {
        const text = document.createElement("span");
        if (interactive || hidden) {
            text.setAttribute("aria-hidden", "true");
        } else {
            text.setAttribute("role", "img");
            text.setAttribute("aria-label", person.name);
        }
        text.textContent = person.initials;
        el.append(text);
    }

    if (!interactive) {
        return el;
    }

    // The button is the touch target (44 px on coarse pointers); the circle inside keeps its size, as components/avatar.
    const button = document.createElement("button");
    button.type = "button";
    button.className = "shrink-0 inline-flex items-center justify-center rounded-full pointer-coarse:min-h-11 pointer-coarse:min-w-11";
    button.dataset.personAvatar = String(person.id);
    button.dataset.personName = person.name;
    button.setAttribute("aria-haspopup", "dialog");
    button.setAttribute("aria-label", `Open ${person.name}'s profile`);
    button.append(el);

    return button;
}
