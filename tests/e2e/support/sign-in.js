// Demo accounts from `php spark db:seed DatabaseSeeder` (see docs/test-cases/README.md).
export const accounts = {
    student: { email: "student5@mentora.test", password: "Student123!" },
    instructor: { email: "instructor1@mentora.test", password: "Instructor123!" },
};

/** Fills in and submits the login form. Does not check where it leads. */
export async function submitLoginForm(page, email, password) {
    await page.goto("/login");
    await page.getByPlaceholder("Email").fill(email);
    await page.getByPlaceholder("Password").fill(password);
    await page.getByRole("button", { name: "Login", exact: true }).click();
}
