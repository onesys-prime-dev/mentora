import { startPageProgress } from "./spa/page-progress.js";

const switcher = document.querySelector("[data-role-switch]");

if (switcher) {
    const buttons = [...switcher.querySelectorAll("[data-role-switch-button]")];
    const switchUrl = switcher.dataset.roleSwitchUrl;
    const errorLabel = switcher.parentElement?.querySelector("[data-role-switch-error]") ?? null;

    // The button marked disabled by the server is the currently active role —
    // that's the one state a failed attempt should never change.
    const activeRole = buttons.find((item) => item.disabled)?.dataset.role;

    const showError = (message) => {
        if (!errorLabel) {
            return;
        }

        errorLabel.textContent = message;
        errorLabel.classList.toggle("hidden", !message);
    };

    const clearClientState = async () => {
        window.localStorage?.clear();
        window.sessionStorage?.clear();

        if ("caches" in window) {
            const cacheNames = await window.caches.keys();
            await Promise.all(cacheNames.map((name) => window.caches.delete(name)));
        }
    };

    buttons.forEach((button) => {
        button.addEventListener("click", async () => {
            const role = button.dataset.role;

            if (!role || !switchUrl || button.disabled) {
                return;
            }

            showError("");

            buttons.forEach((item) => {
                item.disabled = true;
            });

            try {
                const response = await fetch(switchUrl, {
                    method: "POST",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: new URLSearchParams({ role }),
                });

                const result = await response.json();

                if (!response.ok || typeof result.redirect !== "string") {
                    throw new Error(result.error || "Role switch failed.");
                }

                await clearClientState();

                if (typeof window.__roleSwitchNavigate === "function") {
                    window.__roleSwitchNavigate(result.redirect);
                } else {
                    startPageProgress();
                    window.location.assign(result.redirect);
                }
            } catch (error) {
                // Back to the state before this attempt: only the real active
                // role stays disabled, everything else — including the one
                // that just failed — is clickable again.
                buttons.forEach((item) => {
                    item.disabled = item.dataset.role === activeRole;
                });

                showError(error instanceof Error ? error.message : "Role switch failed. Please try again.");
            }
        });
    });
}
