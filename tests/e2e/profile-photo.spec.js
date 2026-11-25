// Profile photos end to end (docs/test-cases/p4-user-profile.md, TC-PRF-10 to TC-PRF-12).
// The three cases run in order on instructor1 and leave photos on and no photo, as the other specs expect.
import { deflateSync } from "node:zlib";
import { expect, test } from "@playwright/test";
import { signInAs } from "./support/course.js";
import { runSql } from "./support/database.js";
import { accounts, submitLoginForm } from "./support/sign-in.js";

const band = (page) => page.locator("[data-profile-band]");
const headerImg = (page) => page.locator("header [data-avatar] img");

/** A solid 300 x 200 RGB PNG built here, so no binary fixture is kept. */
function png() {
    const crc = (buf) => {
        let c = ~0;

        for (const b of buf) {
            c ^= b;
            for (let k = 0; k < 8; k++) {
                c = c & 1 ? (c >>> 1) ^ 0xedb88320 : c >>> 1;
            }
        }

        return ~c >>> 0;
    };
    const chunk = (type, data) => {
        const body = Buffer.concat([Buffer.from(type), data]);
        const out = Buffer.alloc(body.length + 8);

        out.writeUInt32BE(data.length, 0);
        body.copy(out, 4);
        out.writeUInt32BE(crc(body), body.length + 4);

        return out;
    };
    const [w, h] = [300, 200];
    const head = Buffer.alloc(13);
    const raw = Buffer.alloc((w * 3 + 1) * h);

    head.writeUInt32BE(w, 0);
    head.writeUInt32BE(h, 4);
    head.set([8, 2, 0, 0, 0], 8);
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            raw.set([192, 57, 43], y * (w * 3 + 1) + 1 + x * 3);
        }
    }

    const buffer = Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk("IHDR", head), chunk("IDAT", deflateSync(raw)), chunk("IEND", Buffer.alloc(0))]);

    return { name: "me.png", mimeType: "image/png", buffer };
}

/** Sets the institution's Profile photos switch from Institution settings. */
async function setSchoolPhotos(browser, on) {
    const context = await browser.newContext();
    const admin = await context.newPage();

    await submitLoginForm(admin, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await admin.goto("/institution/settings");

    const toggle = admin.locator("[data-photos-switch]");

    if ((await toggle.isChecked()) !== on) {
        await toggle.evaluate((el) => el.click()); // the visible switch is the styled sibling
        await admin.getByRole("button", { name: "Save profile" }).click();
        await expect(admin.getByText("The profile was saved.")).toBeVisible();
    }

    await context.close();
}

test.describe.serial("Profile photos", () => {
    let photoUrl;
    let instructorId;

    test.afterAll(async ({ browser }) => {
        await setSchoolPhotos(browser, true);

        // Leave instructor1 without a photo, whatever failed above.
        const context = await browser.newContext();
        const page = await context.newPage();

        await signInAs(page, accounts.instructor, "instructor");
        await page.goto("/profile");
        if (await band(page).locator("[data-avatar] img").count()) {
            await page.getByRole("button", { name: "Change photo" }).click();
            await page.getByRole("dialog").getByRole("button", { name: "Remove photo" }).click();
            await expect(band(page).locator("[data-avatar] img")).toHaveCount(0);
        }
        await context.close();

        // The administrator's removal notified instructor1 and queued an email; later specs start without them.
        const mine = `(SELECT user_id FROM auth_identities WHERE secret = '${accounts.instructor.email}')`;

        runSql(`DELETE FROM notifications WHERE type = 'profile.photo' AND user_id IN ${mine}`);
        runSql(`DELETE FROM mail_outbox WHERE kind = 'photo' AND user_id IN ${mine}`);
    });

    test("TC-PRF-10 An instructor uploads a photo and a student sees it on their card", async ({ page, browser }) => {
        await signInAs(page, accounts.instructor, "instructor");
        await page.goto("/profile");
        await expect(band(page).locator("[data-avatar] img")).toHaveCount(0);

        await page.getByRole("button", { name: "Add photo" }).click();
        const dialog = page.getByRole("dialog");

        // The page script loads after the page; choose again until it has attached and the crop is ready.
        await expect(async () => {
            await dialog.getByLabel("Photo", { exact: true }).setInputFiles(png());
            await expect(dialog.getByRole("button", { name: "Save" })).toBeEnabled({ timeout: 1_000 });
        }).toPass({ timeout: 20_000 });
        await dialog.getByRole("button", { name: "Save" }).click();

        const img = band(page).locator("[data-avatar] img");

        await expect(img).toBeVisible();
        await expect(headerImg(page).first()).toBeVisible();

        photoUrl = new URL((await img.getAttribute("src")) ?? "", page.url()).pathname;
        instructorId = Number(/\/people\/(\d+)\/photo/.exec(photoUrl)?.[1]);
        expect(instructorId).toBeGreaterThan(0);

        // A student opening that instructor's card sees the picture.
        const context = await browser.newContext();
        const student = await context.newPage();

        await signInAs(student, accounts.student1, "student");
        await student.goto(`/people/${instructorId}/card`);
        await expect(student.locator("img").first()).toBeVisible();
        expect((await student.request.get(photoUrl)).status()).toBe(200);
        await context.close();
    });

    test("TC-PRF-11 Turning profile photos off shows initials and the photo address refuses; turning them on brings it back", async ({ page, browser }) => {
        await setSchoolPhotos(browser, false);
        await signInAs(page, accounts.instructor, "instructor");
        await page.goto("/profile");

        await expect(band(page).locator("[data-avatar] img")).toHaveCount(0);
        await expect(headerImg(page)).toHaveCount(0);
        expect((await page.request.get(photoUrl)).status()).toBe(404);

        await setSchoolPhotos(browser, true);
        await page.reload();
        await expect(band(page).locator("[data-avatar] img")).toBeVisible();
        expect((await page.request.get(photoUrl)).status()).toBe(200);
    });

    test("TC-PRF-12 An administrator removes someone's photo and they are told", async ({ page, browser }) => {
        await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
        await page.goto(`/people/${instructorId}`);
        await expect(page.locator("[data-profile-admin]")).toBeVisible();

        await page.getByRole("button", { name: "Remove photo" }).click();
        await page.getByRole("dialog").getByRole("button", { name: "Remove photo" }).click();
        await expect(page.locator("[data-person-profile] [data-avatar] img")).toHaveCount(0);

        const context = await browser.newContext();
        const instructor = await context.newPage();

        await signInAs(instructor, accounts.instructor, "instructor");
        await instructor.goto("/profile");
        await expect(band(instructor).locator("[data-avatar] img")).toHaveCount(0);
        await instructor.getByRole("button", { name: "Notifications" }).click();
        await expect(instructor.locator("[data-notifications-list]")).toContainText("Your photo was removed by your school");
        await context.close();
    });
});
