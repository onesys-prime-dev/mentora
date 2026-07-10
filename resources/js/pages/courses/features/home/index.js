import { fetchHome } from "./api.js";

export default {

    async render()
    {
        const response = await fetchHome();

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