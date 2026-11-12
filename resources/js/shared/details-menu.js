// A <details data-menu> is a dropdown: it closes when you click elsewhere or press Escape. One whose <summary> is
// not displayed is not acting as a dropdown (the module outline from lg up is a sidebar), so it is left alone.
const summaryShown = (menu) => {
    const summary = menu.querySelector(":scope > summary");

    return !summary || getComputedStyle(summary).display !== "none";
};

const menus = () => [...document.querySelectorAll("details[data-menu][open]")].filter(summaryShown);

document.addEventListener("click", (event) => {
    menus().forEach((menu) => {
        if (!menu.contains(event.target)) menu.open = false;
    });
});

document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;

    menus().forEach((menu) => {
        menu.open = false;
        menu.querySelector("summary")?.focus();
    });
});
