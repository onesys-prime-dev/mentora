// Demo accounts from `php spark db:seed DatabaseSeeder` (see docs/test-cases/README.md).
export const accounts = {
    // The clean-slate student and the main instructor most cases use.
    student: { email: "student5@mentora.test", password: "Student123!" },
    instructor: { email: "instructor1@mentora.test", password: "Instructor123!" },

    // Named after their seed accounts, for cases that need a particular one.
    student1: { email: "student1@mentora.test", password: "Student123!" },
    student2: { email: "student2@mentora.test", password: "Student123!" },
    student3: { email: "student3@mentora.test", password: "Student123!" },
    student4: { email: "student4@mentora.test", password: "Student123!" },
    instructor2: { email: "instructor2@mentora.test", password: "Instructor123!" },

    // The demo parents: the first follows student1 and student5, the second follows nobody.
    parent: { email: "parent1@mentora.test", password: "Parent123!" },
    parentUnlinked: { email: "parent2@mentora.test", password: "Parent123!" },

    // The demo institutional administrator of institution 1.
    institutionAdmin: { email: "institution.admin@mentora.test", password: "Institution123!" },
};

// The system administrator tests/e2e/support/serve.js creates (the demo seeder refuses to run in
// production, so this one is made with `php spark admin:create`). It signs in with an emailed
// code; see support/admin.js.
export const adminAccount = { email: "admin@mentora.test", password: "Administrator123!" };

/** Fills in and submits the login form. Does not check where it leads. */
export async function submitLoginForm(page, email, password) {
    await page.goto("/login");
    await page.getByPlaceholder("Email").fill(email);
    await page.getByPlaceholder("Password").fill(password);
    await page.getByRole("button", { name: "Login", exact: true }).click();
}
