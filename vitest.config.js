import { defineConfig } from "vitest/config";

// Unit tests for the browser code in resources/js. They run in jsdom, so
// nothing here needs the PHP app, a database or a built bundle.
export default defineConfig({
    test: {
        environment: "jsdom",
        include: ["tests/js/**/*.test.js"],
        restoreMocks: true,
        unstubGlobals: true,
    },
});
