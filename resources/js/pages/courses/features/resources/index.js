import { fetchResources } from "./api.js";

export default {

    async render()
    {
        const response = await fetchResources();

        document.querySelector("#course-content").innerHTML =
            response.html;
    },

    initialize()
    {

    },

    destroy()
    {

    }

};