import "../css/app.css";

// First: it wraps fetch as it loads, before any other module can send a change.
import "./shared/csrf";

import "./shared/profile-menu";
import "./shared/app-menu";
import "./shared/institution-menu";
import "./shared/details-menu";
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
import "./shared/data-table";
import "./shared/spa/page-progress";
import "./shared/messages-badge";

// The administrator's Notifications page has data-page "admin", so it names its script itself.
if (document.querySelector("[data-notifications-page]") && document.body.dataset.page !== "notifications") {
    import("./pages/notifications/notifications.js").catch(() => {});
}

const page = document.body.dataset.page;

if (page) {
    import(`./pages/${page}/${page}.js`)
        .catch(() => {
            // No page module exists — that's okay.
        });
}