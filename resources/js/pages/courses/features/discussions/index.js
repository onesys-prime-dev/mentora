import { fetchDiscussions } from "./api.js";

export default {

    async render()
    {
        const response = await fetchDiscussions();

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