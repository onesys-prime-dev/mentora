import js from "@eslint/js";
import globals from "globals";

// Lint for the browser code (resources/js), its unit tests (tests/js) and the
// config files. Problems that existed when linting was introduced are recorded
// in eslint-suppressions.json, so only new ones fail the build. See docs/ci-cd.md.
export default [
    {
        // Only our own browser code is linted: not dependencies, build output,
        // CodeIgniter's bundled error pages (app/Views) or the Python service.
        ignores: [
            "node_modules/",
            "vendor/",
            "public/",
            "build/",
            "writable/",
            "app/",
            "python-question-builder-service/",
        ],
    },

    js.configs.recommended,

    {
        files: ["resources/js/**/*.js"],
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "module",
            globals: { ...globals.browser },
        },
    },

    {
        files: ["tests/js/**/*.js"],
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "module",
            globals: { ...globals.browser, ...globals.node },
        },
    },

    {
        // Browser end-to-end tests and their start script run in Node.
        files: ["tests/e2e/**/*.js"],
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "module",
            globals: { ...globals.node },
        },
    },

    {
        files: ["*.config.js"],
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "module",
            globals: { ...globals.node },
        },
    },
];
