import "../css/app.css";

// First: it wraps fetch as it loads, before any other module can send a change.
import "./shared/csrf";

import "./shared/profile-menu";
import "./shared/app-menu";
import "./shared/institution-menu";
import "./shared/notifications-menu";
import "./shared/role-switch";
import "./shared/select-link";
import "./shared/auto-search";
import "./shared/select-arrow";
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