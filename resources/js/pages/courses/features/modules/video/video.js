

/*
|--------------------------------------------------------------------------
| Video Editor
|--------------------------------------------------------------------------
*/

export function initializeVideoEditor() {

    bindSourceSelector();

    updatePanels();

    updatePreview();

    document
        .querySelector("[data-video-file]")
        ?.addEventListener(
            "change",
            updatePreview
        );

    document
        .querySelector("[data-video-url]")
        ?.addEventListener(
            "input",
            updatePreview
        );

}

/*
|--------------------------------------------------------------------------
| Source Selector
|--------------------------------------------------------------------------
*/

function bindSourceSelector() {

    document

        .querySelectorAll(
            "[data-video-source]"
        )

        .forEach((radio) => {

            radio.addEventListener(
                "change",
                () => {

                    updatePanels();

                    updatePreview();

                }
            );

        });

}

function updatePanels() {

    const source =

        document.querySelector(
            "[data-video-source]:checked"
        )?.value;

    document

        .querySelector(
            "[data-video-upload-panel]"
        )

        ?.classList.toggle(
            "hidden",
            source !== "upload"
        );

    document

        .querySelector(
            "[data-video-link-panel]"
        )

        ?.classList.toggle(
            "hidden",
            source !== "link"
        );

}

/*
|--------------------------------------------------------------------------
| Preview
|--------------------------------------------------------------------------
*/

function updatePreview() {

    const preview =

        document.querySelector(
            "[data-video-preview]"
        );

    if (!preview) {
        return;
    }

    const source =

        document.querySelector(
            "[data-video-source]:checked"
        )?.value;

    /*
    |--------------------------------------------------------------------------
    | Upload
    |--------------------------------------------------------------------------
    */

    if (source === "upload") {

        const file =

            document.querySelector(
                "[data-video-file]"
            )?.files?.[0];

        const fileName =

            document.querySelector(
                "[data-video-file-name]"
            );

        if (fileName) {

            fileName.textContent =
                file
                    ? file.name
                    : "No file selected.";

        }

        const existingVideo =
            preview.dataset.existingVideo;

        if (!file && existingVideo) {

            preview.innerHTML = `

                <video
                    class="h-full w-full object-cover"
                    controls>

                    <source src="${existingVideo}">

                </video>

            `;

            return;

        }

        if (!file) {

            preview.innerHTML = `

                <div class="flex h-full items-center justify-center text-gray-400">

                    Video preview will appear here.

                </div>

            `;

            return;

        }

        const url =
            URL.createObjectURL(file);

        preview.innerHTML = `

            <video
                class="h-full w-full object-cover"
                controls>

                <source
                    src="${url}"
                    type="${file.type}">

            </video>

        `;

        return;

    }

    /*
    |--------------------------------------------------------------------------
    | External Link
    |--------------------------------------------------------------------------
    */

    const input =

        document.querySelector(
            "[data-video-url]"
        );

    const url =
        input?.value.trim();

    if (!url) {

        preview.innerHTML = `

            <div class="flex h-full items-center justify-center text-gray-400">

                Video preview will appear here.

            </div>

        `;

        return;

    }

    preview.innerHTML = `

        <iframe
            class="h-full w-full"
            src="${convertToEmbed(url)}"
            allowfullscreen
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture">

        </iframe>

    `;

}

/*
|--------------------------------------------------------------------------
| URL Helpers
|--------------------------------------------------------------------------
*/

function convertToEmbed(
    url
) {

    /*
    |--------------------------------------------------------------------------
    | youtube.com/watch?v=
    |--------------------------------------------------------------------------
    */

    if (
        url.includes(
            "youtube.com/watch?v="
        )
    ) {

        const videoId =

            new URL(url)

                .searchParams

                .get("v");

        return `https://www.youtube.com/embed/${videoId}`;

    }

    /*
    |--------------------------------------------------------------------------
    | youtu.be
    |--------------------------------------------------------------------------
    */

    if (
        url.includes(
            "youtu.be/"
        )
    ) {

        const videoId =

            url.split(
                "youtu.be/"
            )[1];

        return `https://www.youtube.com/embed/${videoId}`;

    }

    return url;

}

/*
|--------------------------------------------------------------------------
| Video Editor State
|--------------------------------------------------------------------------
*/

export function collectVideoFormData() {

    const formData =
        new FormData();

    formData.append(
        "source",
        document.querySelector(
            "[data-video-source]:checked"
        ).value
    );

    formData.append(
        "autoplay",
        document.querySelector(
            "[data-video-autoplay]"
        ).checked
            ? 1
            : 0
    );

    formData.append(
        "show_controls",
        document.querySelector(
            "[data-video-controls]"
        ).checked
            ? 1
            : 0
    );

    if (

        document.querySelector(
            "[data-video-source]:checked"
        ).value === "upload"

    ) {

        const file =

            document.querySelector(
                "[data-video-file]"
            ).files[0];

        if (file) {

            formData.append(
                "video",
                file
            );

        }

    }

    else {

        formData.append(

            "resource_url",

            document.querySelector(
                "[data-video-url]"
            ).value.trim()

        );

    }

    return formData;

}
