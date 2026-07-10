import "../css/app.css";

import "./shared/sidebar";

const page = document.body.dataset.page;

if (page) {
    import(`./pages/${page}/${page}.js`)
        .catch(() => {
            // No page module exists — that's okay.
        });
}