// Mirrors app/Views/components/avatar.php and AvatarData::COLOURS (same order, same id % 8 rule).
export const COLOURS = ["#B91C1C", "#C2410C", "#A16207", "#15803D", "#0F766E", "#1D4ED8", "#6D28D9", "#BE185D"];

const SIZES = {
    xs: "h-6 w-6 text-[10px]",
    sm: "h-8 w-8 text-xs",
    md: "h-12 w-12 text-base",
    lg: "h-14 w-14 text-lg",
    xl: "h-18 w-18 text-2xl",
};

/** @param {{id:number, name:string, initials:string, colour?:string, photoUrl?:?string, photoUrlLg?:?string}} person */
export function renderAvatar(person, size = "md") {
    const el = document.createElement("span");
    el.className = `${SIZES[size] ?? SIZES.md} shrink-0 inline-flex items-center justify-center overflow-hidden rounded-full font-bold text-white select-none`;
    el.style.backgroundColor = person.colour ?? COLOURS[person.id % 8];
    el.dataset.avatar = "";

    // The 256 px photo for the larger sizes, as components/avatar does.
    const photo = (size === "lg" || size === "xl") && person.photoUrlLg ? person.photoUrlLg : person.photoUrl;

    if (photo) {
        const img = document.createElement("img");
        img.className = "h-full w-full object-cover";
        img.src = photo;
        img.alt = `Photo of ${person.name}`;
        el.append(img);
    } else {
        const text = document.createElement("span");
        text.setAttribute("role", "img");
        text.setAttribute("aria-label", person.name);
        text.textContent = person.initials;
        el.append(text);
    }

    return el;
}
