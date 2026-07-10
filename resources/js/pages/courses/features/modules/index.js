import { fetchModules } from "./api.js";

export default {

    async render()
    {
        const response = await fetchModules();

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