import "../css/app.css";

import "./shared/profile-menu";
import "./shared/app-menu";

const page = document.body.dataset.page;

if (page) {
    import(`./pages/${page}/${page}.js`)
        .catch(() => {
            // No page module exists — that's okay.
        });
}