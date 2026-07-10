export function showLoader()
{
    document.querySelector("#course-content").innerHTML = `

        <div class="p-8 text-center">

            Loading...

        </div>

    `;
}

export function hideLoader()
{
}