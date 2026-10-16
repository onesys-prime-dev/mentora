import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { beforeEach, describe, expect, it } from "vitest";

import { renderOverviewSkeleton } from "../../../resources/js/pages/courses/features/overview/skeleton.js";
import { renderAnnouncementsSkeleton } from "../../../resources/js/pages/courses/features/announcements/skeleton.js";
import { renderResourcesSkeleton } from "../../../resources/js/pages/courses/features/resources/skeleton.js";
import { renderDiscussionsSkeleton } from "../../../resources/js/pages/courses/features/discussions/skeleton.js";
import { renderAssessmentsSkeleton } from "../../../resources/js/pages/courses/features/assessments/skeleton.js";
import { renderGradesSkeleton } from "../../../resources/js/pages/courses/features/grades/skeleton.js";
import { renderModulesSkeleton } from "../../../resources/js/pages/courses/features/modules/skeleton.js";

/*
 * The server draws a tab's loading shape into the first HTML and the script
 * later replaces it with its own (or content). They must not disagree about
 * the frame: the outer wrapper, the page padding and the order and layout of
 * the blocks directly inside it. This reads the PHP partials as text (PHP
 * tags removed, loop bodies kept once) and compares that frame with what the
 * script skeletons produce.
 */

const DIR = resolve(__dirname, "../../../app/Views/pages/courses/skeletons");

const SCRIPTS = {
    overview: renderOverviewSkeleton,
    announcements: renderAnnouncementsSkeleton,
    resources: renderResourcesSkeleton,
    discussions: renderDiscussionsSkeleton,
    assessments: renderAssessmentsSkeleton,
    grades: renderGradesSkeleton,
    modules: renderModulesSkeleton,
};

const norm = (classes) => classes.split(/\s+/).filter(Boolean).sort().join(" ");

/** Wrapper classes and the class lists of its direct children, repeats collapsed. */
function frame(html) {

    const doc = new DOMParser().parseFromString(`<body>${html}</body>`, "text/html");
    const root = doc.body.firstElementChild;
    const children = [];

    Array.from(root.children).forEach((child) => {

        const classes = norm(child.className);

        if (children[children.length - 1] !== classes) {
            children.push(classes);
        }

    });

    return { wrapper: norm(root.className), children };

}

/**
 * Resolves what depends on the role: the partials only branch on the
 * instructor, so a condition is true for an instructor and false otherwise,
 * and a ternary prints its second branch for anyone else.
 */
function resolveRole(php, role) {

    const instructor = role === "instructor";

    let out = php.replace(
        /<\?php\s+if\s*\((.*?)\):\s*\?>([\s\S]*?)(?:<\?php\s+else:\s*\?>([\s\S]*?))?<\?php\s+endif;\s*\?>/g,
        (all, cond, yes, no = "") => {

            const negated = cond.trim().startsWith("!");
            const truth = negated ? !instructor : instructor;

            return truth ? yes : no;

        }
    );

    out = out.replace(/<\?=\s*[^?]*\?\s*'([^']*)'\s*:\s*'([^']*)'[^?]*\?>/g, (all, a, b) => (instructor ? a : b));

    return out;

}

function phpFrame(name, role) {

    const source = resolveRole(readFileSync(`${DIR}/${name}.php`, "utf8"), role)
        .replace(/<\?php[\s\S]*?\?>/g, "")
        .replace(/<\?=[\s\S]*?\?>/g, "");

    return frame(source);

}

beforeEach(() => {
    window.coursesConfig = { role: "student", offeringId: 1 };
    window.sessionStorage.clear();
});

describe("the server's skeleton for each tab has the frame of the script's", () => {

    ["student", "instructor"].forEach((role) => {

        Object.entries(SCRIPTS).forEach(([name, render]) => {

            it(`${name} (${role}): same wrapper and the same block layout`, () => {

                window.coursesConfig = { role, offeringId: 1 };

                const server = phpFrame(name, role);
                const script = frame(render());

                expect(server.wrapper, `${name} wrapper`).toBe(script.wrapper);
                expect(server.children, `${name} top-level blocks`).toEqual(script.children);

            });

        });

    });

});

describe("every tab the course page offers has a server skeleton", () => {

    it("has a partial for each feature", () => {

        Object.keys(SCRIPTS).forEach((name) => {
            expect(() => readFileSync(`${DIR}/${name}.php`, "utf8"), name).not.toThrow();
        });

    });

});
