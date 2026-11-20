import "../css/app.css";

// First: it wraps fetch as it loads, before any other module can send a change.
import "./shared/csrf";

import "./shared/profile-menu";
import "./shared/app-menu";
import "./shared/institution-menu";
import "./shared/details-menu";
import "./shared/contents-menu";
import "./shared/notifications-menu";
import "./shared/notification-toast";
import "./shared/role-switch";
import "./shared/select-link";
import "./shared/auto-search";
import "./shared/select-arrow";
import "./shared/announcement";
import "./shared/toast";
import "./shared/theme";
import "./shared/dialog";
import "./shared/action-bar";
import "./shared/student-autocomplete";
import "./shared/data-table";
import "./shared/row-actions";
import "./shared/scroll-x";
import "./shared/check-all";
import "./shared/phone-tabs";
import "./shared/side-panel";
import "./shared/split-divider";
import "./shared/spa/page-progress";
import "./shared/messages-badge";

const page = document.body.dataset.page;

if (page) {
    import(`./pages/${page}/${page}.js`)
        .catch(() => {
            // No page module exists — that's okay.
        });
}