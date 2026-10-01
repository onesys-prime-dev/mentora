/*
|--------------------------------------------------------------------------
| Learning Unit Action Menus
|--------------------------------------------------------------------------
*/

import { bindActionMenu } from "./action-menus.js";

export function bindLearningUnitActionMenus() {
    bindActionMenu("[data-learning-unit-actions]", "[data-learning-unit-actions-panel]");
}
