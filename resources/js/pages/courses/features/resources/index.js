import { fetchResources} from "./api.js";
import { state } from "./state.js";

export async function initialize()
{
    state.loading = true;

    const container =
        document.querySelector("#course-content");

    container.innerHTML = "Loading...";

    try {

        state.data = await fetchResources();

        container.innerHTML =
            state.data.html;

    }

    catch (error) {

        console.error(error);

        container.innerHTML =
            "<p>Unable to load Resources.</p>";

    }

    finally {

        state.loading = false;

    }
}