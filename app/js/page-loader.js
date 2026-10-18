// ============================================================
//  PAGE-LOADER.JS
//  Shows a full-screen loading overlay on every page load.
//  The overlay disappears once the page is fully ready.
//  Include this script in every page's <head> as the FIRST script.
// ============================================================

(function () {
    // ── Detect the logo path relative to the current page ───
    // We store the logo path in a <meta> tag per page so this
    // script doesn't need to know the folder depth.
    // Falls back to a relative path if the meta isn't present.
    const meta     = document.querySelector('meta[name="loader-logo"]');
    const logoSrc  = meta ? meta.content : '../images/BCP_LOGO.png';

    // ── Build the overlay ────────────────────────────────────
    const loader = document.createElement('div');
    loader.id    = 'page-loader';
    loader.innerHTML = `
        <img class="pl-logo" src="${logoSrc}" alt="BCP Logo"/>
        <p  class="pl-text">Magandang Buhay BCPian!</p>
        <p  class="pl-sub">
            Please wait
            <span class="pl-dots">
                <span></span><span></span><span></span>
            </span>
        </p>`;

    function dismiss() {
        if (!loader || loader.dataset.dismissed === 'true') return;
        loader.dataset.dismissed = 'true';
        loader.classList.add('hide');
        setTimeout(() => {
            if (loader.parentNode) loader.remove();
        }, 160);
    }

    // Attach loader as early as possible
    if (document.body) {
        document.body.prepend(loader);
    } else {
        document.addEventListener('DOMContentLoaded', () => {
            if (document.body) document.body.prepend(loader);
        });
    }

    // Dismiss as soon as DOM is ready / interactive
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        setTimeout(dismiss, 40);
    } else {
        document.addEventListener('DOMContentLoaded', () => setTimeout(dismiss, 40));
    }

    // Safety fallback: ensure loader never blocks screen longer than 350ms
    window.addEventListener('load', dismiss);
    setTimeout(dismiss, 350);
})();
