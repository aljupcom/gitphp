/**
 * GitPHP — GitHub-inspired theme bootstrap.
 *
 * Loaded in <head> (NOT deferred) so the color scheme is applied before the
 * first paint, avoiding a flash of the wrong theme. Reads the saved choice
 * from localStorage, falling back to the OS preference, and sets the
 * data-theme attribute on <html>. The stylesheet reacts to that attribute
 * (with a prefers-color-scheme fallback when this script never runs).
 */
(function () {
    'use strict';

    try {
        var stored = localStorage.getItem('gh-theme');
        var theme = (stored === 'light' || stored === 'dark')
            ? stored
            : (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches
                ? 'light'
                : 'dark');

        document.documentElement.setAttribute('data-theme', theme);
    } catch (e) {
        // localStorage unavailable (privacy mode): keep the no-JS CSS fallback.
    }
})();
