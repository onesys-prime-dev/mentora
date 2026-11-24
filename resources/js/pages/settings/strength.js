/**
 * A rough strength for the meter under "New password": a hint only, the server's rules decide.
 * One point for reaching the minimum, one for four more characters, one for mixing letters and
 * digits in both cases, one for a symbol. Below the minimum it is always 0, "Too short".
 */
export function strength(value, min) {
    if (value === "") {
        return { score: 0, word: "" };
    }
    if (value.length < min) {
        return { score: 0, word: "Too short." };
    }

    let score = 1;
    score += value.length >= min + 4 ? 1 : 0;
    score += /[a-z]/.test(value) && /[A-Z]/.test(value) && /\d/.test(value) ? 1 : 0;
    score += /[^A-Za-z0-9]/.test(value) ? 1 : 0;

    return { score, word: ["", "Weak.", "Fair.", "Good.", "Strong."][score] };
}
