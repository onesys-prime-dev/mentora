<?php // A stand-in for layouts/app (which asks the database about the user), so a page's own content renders alone. ?>
<main><?= $this->renderSection('content') ?></main>
