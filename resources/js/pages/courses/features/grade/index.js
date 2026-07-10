import { fetchGrade } from "./api.js";

export default {

    async render()
    {
        const response = await fetchGrade();

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