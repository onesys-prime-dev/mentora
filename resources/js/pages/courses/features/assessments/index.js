import { fetchAssessments } from "./api.js";

export default {

    async render()
    {
        const response = await fetchAssessments();

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