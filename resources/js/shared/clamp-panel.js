// clampToViewport(panel, margin) keeps an open dropdown panel inside the viewport.
// Call it each time the panel opens, after it is displayed: it caps the panel's width to the
// viewport and shifts it sideways (CSS `translate`) until it clears both edges by `margin` px.
// It resets its own earlier adjustments first, so repeated calls never stack.
export function clampToViewport(panel, margin = 12) {
    panel.style.translate = "";
    panel.style.maxWidth = "";

    const viewport = document.documentElement.clientWidth;
    const room = viewport - 2 * margin;

    if (panel.getBoundingClientRect().width > room) panel.style.maxWidth = `${room}px`;

    const { left, right } = panel.getBoundingClientRect();
    let shift = 0;
    if (left < margin) shift = margin - left;
    else if (right > viewport - margin) shift = viewport - margin - right;

    if (shift) panel.style.translate = `${shift}px 0`;
}
