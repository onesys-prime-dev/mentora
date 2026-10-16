<?php
/**
 * The slim bar at the top of every page, already running in the server's HTML so it
 * is on screen from the first paint of a new page (a server page reload). Once the
 * page's JavaScript has started, shared/spa/progress.js finishes it and lets it fade.
 */
?>
<div class="spa-progress is-active" data-spa-progress data-page-load role="progressbar" aria-label="Loading"></div>
<noscript><style>[data-page-load] { display: none; }</style></noscript>
