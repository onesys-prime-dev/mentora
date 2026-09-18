/*
|--------------------------------------------------------------------------
| Learning Item Navigation
|--------------------------------------------------------------------------
*/

import { loadLearningItem } from "./learning-item.js";

export function bindLearningNavigation(refresh) {

    document

        .querySelectorAll(".learning-item-link")

        .forEach(button => {

            button.addEventListener(

                "click",

                async event => {

                    event.preventDefault();

                    if (button.disabled) {
                        return;
                    }

                    const learningItemId = Number(
                        button.dataset.learningItem
                    );

                    if (!learningItemId) {
                        return;
                    }

                    await loadLearningItem(
                        learningItemId,
                        refresh
                    );

                }

            );

        });

}