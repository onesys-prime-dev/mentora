import { defineConfig } from "vite";
import tailwindcss from "@tailwindcss/vite";
import { resolve } from "path";

export default defineConfig(({ command }) => ({
    base: command === "build" ? "/mentora/public/build/" : "/",

    plugins: [
        tailwindcss(),
    ],

    publicDir: false,

    build: {
        outDir: resolve(__dirname, "public/build"),
        emptyOutDir: true,
        manifest: true,

        rollupOptions: {
            input: {
                app: resolve(__dirname, "resources/js/app.js"),
            },
        },
    },

    server: {
        host: "localhost",
        port: 5173,
        strictPort: true,
        origin: "http://localhost:5173",
    },
}));