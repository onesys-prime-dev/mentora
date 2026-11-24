<?php
/**
 * The one profile modal every interactive avatar opens (shared/person-card.js fills it
 * from GET /people/{id}/panel). Full screen on a phone.
 */
?>
<?= view('components/dialog', ['id' => 'person-dialog', 'title' => 'Profile', 'body' => '', 'size' => 'lg', 'variant' => 'fullscreen'], ['saveData' => false]) ?>
