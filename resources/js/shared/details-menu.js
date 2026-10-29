// A <details data-menu> is a dropdown: it closes when you click elsewhere or press Escape.
const menus = () => document.querySelectorAll("details[data-menu][open]");

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
