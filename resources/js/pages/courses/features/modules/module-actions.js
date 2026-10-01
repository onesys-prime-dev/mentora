/*
|--------------------------------------------------------------------------
| Module Action Menus
|--------------------------------------------------------------------------
*/

import { bindActionMenu } from "./action-menus.js";

export function bindModuleActionMenus() {
    bindActionMenu("[data-module-actions]", "[data-module-actions-panel]");
}
