/*
|--------------------------------------------------------------------------
| Learning Item Action Menus
|--------------------------------------------------------------------------
|
| Opens and closes the ⋮ dropdown on each learning item row (Edit,
| Publish/Unpublish, Dependencies, Delete). Mirrors learning-unit-actions.js.
|
*/

import { bindActionMenu } from "./action-menus.js";

export function bindLearningItemActionMenus() {
    bindActionMenu("[data-learning-item-actions]", "[data-learning-item-actions-panel]");
}
