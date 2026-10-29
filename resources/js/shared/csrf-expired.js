/**
 * Whether a server answer is the expired-token one, which the fetch wrapper
 * (csrf.js) has already shown as a toast. A caller that would show the answer's
 * message itself (an alert, say) skips it then, so the person is told once.
 *
 * Kept apart from csrf.js so importing it never wraps fetch.
 */
export function csrfExpired(result) {
    return result?.error === "csrf_expired";
}
