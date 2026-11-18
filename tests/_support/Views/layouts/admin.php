<?php // A stand-in for layouts/admin (which asks the database about the user), so a page's own content renders alone. ?>
<main><?= $this->renderSection('content') ?></main>
