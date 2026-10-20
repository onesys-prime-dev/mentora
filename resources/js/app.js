import "../css/app.css";

import "./shared/profile-menu";
import "./shared/app-menu";
import "./shared/notifications-menu";
import "./shared/role-switch";
import "./shared/announcement";
import "./shared/toast";
import "./shared/theme";
import "./shared/dialog";
import "./shared/data-table";
import "./shared/spa/page-progress";

const page = document.body.dataset.page;

if (page) {
    import(`./pages/${page}/${page}.js`)
        .catch(() => {
            // No page module exists — that's okay.
        });
}