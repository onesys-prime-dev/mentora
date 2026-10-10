import "../css/app.css";

import "./shared/profile-menu";
import "./shared/app-menu";
import "./shared/role-switch";
import "./shared/announcement";

const page = document.body.dataset.page;

if (page) {
    import(`./pages/${page}/${page}.js`)
        .catch(() => {
            // No page module exists — that's okay.
        });
}