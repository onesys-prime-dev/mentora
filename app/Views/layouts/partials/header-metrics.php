<script>
// Pages that pin something under the header read its height from --app-header-h
// (it changes when an announcement or banner appears above the page).
(function () {
    var bar = document.querySelector('[data-app-header]');
    if (!bar) return;
    var set = function () { document.documentElement.style.setProperty('--app-header-h', bar.offsetHeight + 'px'); };
    set();
    if (window.ResizeObserver) new ResizeObserver(set).observe(bar);

    // The header gains a shadow once the page (or, in a locked shell, <main>) has scrolled.
    var main = document.querySelector('main');
    var scrolled = function () {
        var y = Math.max(window.scrollY, main ? main.scrollTop : 0);
        bar.toggleAttribute('data-scrolled', y > 4);
    };
    window.addEventListener('scroll', scrolled, { passive: true });
    if (main) main.addEventListener('scroll', scrolled, { passive: true });
    scrolled();
})();
</script>
