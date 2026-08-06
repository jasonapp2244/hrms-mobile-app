/*
 * Theme bootstrap.
 *
 * This file shipped with the SmartHR template as ~840 lines, most of which
 * injected a "Theme Customizer" panel — a floating gear that offered twelve
 * alternative layouts. Two things were wrong with it here:
 *
 *   1. Choosing any layout other than 'default' removed the navigation and put
 *      nothing in its place. This app's Blade layout only implements the
 *      default sidebar; 'horizontal' and friends are template layouts that were
 *      never built. The choice persisted in localStorage, so an admin who
 *      clicked one lost the whole menu on every subsequent page load with no
 *      obvious way back.
 *   2. Its nineteen layout preview images were never copied into public/assets,
 *      so every page load fired seventeen 404s.
 *
 * What the application actually uses is the dark/light toggle in the header, so
 * that is all that is left. The layout attributes are still written because
 * style.css selects on them, but they are now constants rather than settings.
 */

(function () {
    const root = document.documentElement;

    // Read before first paint. The old file took the theme from a 'theme' key
    // that only the customizer ever wrote, while the header toggle wrote
    // 'darkMode' — so a dark-mode user got a light page until DOMContentLoaded
    // fired and it flipped. That was a white flash on every navigation.
    const dark = localStorage.getItem('darkMode') === 'enabled';

    root.setAttribute('data-theme', dark ? 'dark' : 'light');
    root.setAttribute('data-sidebar', 'light');
    root.setAttribute('data-color', 'primary');
    root.setAttribute('data-topbar', 'white');
    root.setAttribute('data-layout', 'default');
    root.setAttribute('data-topbarcolor', 'white');
    root.setAttribute('data-card', 'bordered');
    root.setAttribute('data-size', 'default');
    root.setAttribute('data-width', 'fluid');
    root.setAttribute('data-loader', 'enable');

    // Rescue anyone already stranded on a broken layout. Without this, a browser
    // that stored layout='horizontal' before this change keeps a stale key
    // forever; clearing them makes the fix retroactive rather than only applying
    // to people who never clicked the gear.
    [
        'theme', 'sidebarTheme', 'color', 'topbar', 'layout', 'topbarcolor',
        'card', 'size', 'width', 'loader', 'sidebarBg', 'topbarbg',
    ].forEach((key) => localStorage.removeItem(key));
})();

document.addEventListener('DOMContentLoaded', function () {
    const darkModeToggle = document.getElementById('dark-mode-toggle');
    const lightModeToggle = document.getElementById('light-mode-toggle');

    // Both live in layouts/partials/header.blade.php. A page rendered without
    // the header (an error page, say) has neither, and must not throw.
    if (!darkModeToggle || !lightModeToggle) {
        return;
    }

    function enableDarkMode() {
        document.documentElement.setAttribute('data-theme', 'dark');
        darkModeToggle.classList.remove('activate');
        lightModeToggle.classList.add('activate');
        localStorage.setItem('darkMode', 'enabled');
    }

    function disableDarkMode() {
        document.documentElement.setAttribute('data-theme', 'light');
        lightModeToggle.classList.remove('activate');
        darkModeToggle.classList.add('activate');
        localStorage.removeItem('darkMode');
    }

    // Sets the button states to match what the head script already painted.
    if (localStorage.getItem('darkMode') === 'enabled') {
        enableDarkMode();
    } else {
        disableDarkMode();
    }

    darkModeToggle.addEventListener('click', enableDarkMode);
    lightModeToggle.addEventListener('click', disableDarkMode);
});
