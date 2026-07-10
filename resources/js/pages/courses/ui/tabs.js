export function activate(feature)
{
    document

        .querySelectorAll("[data-course-feature]")

        .forEach(button => {

            button.classList.remove("active");

            if (
                button.dataset.courseFeature === feature
            ) {
                button.classList.add("active");
            }

        });
}