/**
 * GitPHP Ultra-Fast SPA Navigation & Client-Side Tab Engine
 * 
 * Provides smooth, instantaneous page transitions and 0ms tab switching
 * without white flashes or full DOM teardowns.
 */
(function (window, document) {
    'use strict';

    var cache = new Map();
    var parser = new DOMParser();
    var isNavigating = false;
    var progressBar = null;

    /* ------------------------------------------------------------------ */
    /* Progress Bar Controller                                            */
    /* ------------------------------------------------------------------ */
    function ensureProgressBar() {
        if (!progressBar) {
            progressBar = document.createElement('div');
            progressBar.id = 'spa-progress-bar';
            document.body.appendChild(progressBar);
        }
        return progressBar;
    }

    function startProgress() {
        var bar = ensureProgressBar();
        bar.style.width = '0%';
        bar.style.opacity = '1';
        bar.style.display = 'block';

        // Animate progression smoothly
        requestAnimationFrame(function () {
            bar.style.width = '25%';
            setTimeout(function () {
                if (isNavigating) bar.style.width = '65%';
            }, 100);
            setTimeout(function () {
                if (isNavigating) bar.style.width = '85%';
            }, 250);
        });
    }

    function completeProgress() {
        var bar = ensureProgressBar();
        bar.style.width = '100%';
        setTimeout(function () {
            bar.style.opacity = '0';
            setTimeout(function () {
                bar.style.width = '0%';
                bar.style.display = 'none';
            }, 200);
        }, 120);
    }

    /* ------------------------------------------------------------------ */
    /* Instant Tab Switcher (0ms DOM Switching)                           */
    /* ------------------------------------------------------------------ */
    function initInstantTabs() {
        var tabGroups = document.querySelectorAll('[data-tab-group]');
        tabGroups.forEach(function (group) {
            var groupName = group.getAttribute('data-tab-group');
            var buttons = group.querySelectorAll('[data-tab-target]');
            var panes = document.querySelectorAll('[data-tab-pane-group="' + groupName + '"], [data-tab-pane]');

            // Filter panes for this group
            var groupPanes = [];
            panes.forEach(function (pane) {
                var pGroup = pane.getAttribute('data-tab-pane-group');
                if (!pGroup || pGroup === groupName) {
                    groupPanes.push(pane);
                }
            });

            buttons.forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    var targetTab = btn.getAttribute('data-tab-target');
                    if (!targetTab) return;

                    // Prevent full page reload
                    e.preventDefault();
                    switchTab(groupName, targetTab, buttons, groupPanes);

                    // Update URL with ?tab=targetTab without reloading
                    var url = new URL(window.location.href);
                    url.searchParams.set('tab', targetTab);
                    window.history.replaceState({ spa: true, tab: targetTab }, '', url.toString());
                });
            });

            // On load, activate tab from URL if present
            var currentParam = new URLSearchParams(window.location.search).get('tab');
            if (currentParam) {
                switchTab(groupName, currentParam, buttons, groupPanes);
            }
        });
    }

    function switchTab(groupName, targetTab, buttons, panes) {
        // Update Buttons
        buttons.forEach(function (b) {
            var isMatch = b.getAttribute('data-tab-target') === targetTab;
            if (isMatch) {
                b.classList.add('is-active', 'active');
                b.setAttribute('aria-selected', 'true');
                if (b.hasAttribute('data-active-style')) {
                    b.style.cssText += ';' + b.getAttribute('data-active-style');
                }
            } else {
                b.classList.remove('is-active', 'active');
                b.setAttribute('aria-selected', 'false');
                if (b.hasAttribute('data-inactive-style')) {
                    b.style.cssText += ';' + b.getAttribute('data-inactive-style');
                }
            }
        });

        // Update Panes
        panes.forEach(function (p) {
            var pName = p.getAttribute('data-tab-pane');
            if (pName === targetTab) {
                p.classList.add('is-active');
                p.style.display = 'block';
            } else {
                p.classList.remove('is-active');
                p.style.display = 'none';
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* SPA Page Navigation                                                */
    /* ------------------------------------------------------------------ */
    function shouldIntercept(anchor) {
        if (!anchor || anchor.tagName !== 'A') return false;
        if (anchor.target && anchor.target !== '_self') return false;
        if (anchor.hasAttribute('download') || anchor.getAttribute('data-no-spa') !== null) return false;

        var href = anchor.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:')) return false;

        // Never intercept authentication lifecycle or destructive endpoints
        if (href === '/logout' || href.startsWith('/logout') ||
            href === '/login' || href.startsWith('/login') ||
            href === '/register' || href.startsWith('/register') ||
            href.includes('/delete') || href.includes('/sync') || href.includes('/update') ||
            href.includes('/revoke') || href.includes('/clear-cache') || href.includes('/optimize-db')) {
            return false;
        }

        // Skip binary download endpoints and git raw downloads
        if (href.includes('/archive/') || href.includes('/raw/') || (href.startsWith('/d/') || href.includes('/download/') || href.endsWith('/download')) || href.endsWith('.zip') || href.endsWith('.tar.gz')) {
            return false;
        }

        // Must be same origin
        try {
            var url = new URL(anchor.href, window.location.origin);
            return url.origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    async function fetchPage(url) {
        if (cache.has(url)) {
            var cached = cache.get(url);
            // Cache valid for 30 seconds
            if (Date.now() - cached.timestamp < 30000) {
                return cached.html;
            }
        }

        var response = await fetch(url, {
            headers: {
                'X-Requested-With': 'SPA-Router',
                'X-SPA-Request': '1'
            }
        });

        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        var html = await response.text();
        cache.set(url, { html: html, timestamp: Date.now() });
        return html;
    }

    function prefetch(url) {
        try {
            var parsed = new URL(url, window.location.origin);
            if (parsed.origin === window.location.origin && !cache.has(parsed.href)) {
                fetchPage(parsed.href).catch(function () {});
            }
        } catch (e) {}
    }

    async function navigate(url, pushState) {
        if (isNavigating) return;
        isNavigating = true;
        startProgress();

        // Close any open dropdowns, user popovers, or drawers
        document.querySelectorAll('.gh-user-panel.open, .gh-user-backdrop.open, .gh-drawer.open').forEach(function (el) {
            el.classList.remove('open');
        });

        var main = document.querySelector('main.gh-main') || document.querySelector('main');
        if (main) {
            main.classList.add('spa-fading');
        }

        try {
            var html = await fetchPage(url);
            var doc = parser.parseFromString(html, 'text/html');

            // 1. Update Title
            var newTitle = doc.querySelector('title');
            if (newTitle) {
                document.title = newTitle.textContent;
            }

            // 2. Update Main Content Container
            var newMain = doc.querySelector('main.gh-main') || doc.querySelector('main');
            if (main && newMain) {
                main.innerHTML = newMain.innerHTML;
                main.className = newMain.className;

                // Re-evaluate inline scripts in the new content
                Array.from(main.querySelectorAll('script')).forEach(function (oldScript) {
                    var newScript = document.createElement('script');
                    Array.from(oldScript.attributes).forEach(function (attr) {
                        newScript.setAttribute(attr.name, attr.value);
                    });
                    newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                    if (oldScript.parentNode) {
                        oldScript.parentNode.replaceChild(newScript, oldScript);
                    }
                });
            }

            // 3. Update Nav active items (Sidebar & Header)
            var newNav = doc.querySelector('.gh-admin-nav');
            var curNav = document.querySelector('.gh-admin-nav');
            if (curNav && newNav) {
                curNav.innerHTML = newNav.innerHTML;
            }

            // 4. Update History
            if (pushState) {
                window.history.pushState({ spa: true, url: url }, '', url);
            }

            // 5. Scroll to top or anchor
            var targetHash = new URL(url, window.location.origin).hash;
            if (targetHash) {
                var el = document.querySelector(targetHash);
                if (el) el.scrollIntoView();
            } else {
                window.scrollTo({ top: 0, behavior: 'instant' });
            }

            // 6. Re-run Initializers
            reinitPage();
        } catch (err) {
            console.warn('[SPA Navigation fallback]', err);
            window.location.href = url;
            return;
        } finally {
            if (main) {
                main.classList.remove('spa-fading');
            }
            isNavigating = false;
            completeProgress();
        }
    }

    function reinitPage() {
        initInstantTabs();

        // Synchronize admin & user mobile select navigation
        var currentPath = window.location.pathname;
        var currentFull = window.location.pathname + window.location.search;
        document.querySelectorAll('.gh-nav-select').forEach(function (sel) {
            Array.from(sel.options).forEach(function (opt) {
                if (opt.value === currentFull || opt.value === currentPath) {
                    sel.value = opt.value;
                }
            });
        });

        // Synchronize desktop nav active classes
        document.querySelectorAll('.gh-admin-nav a, .gh-user-nav a').forEach(function (link) {
            var lhref = link.getAttribute('href');
            if (lhref === currentFull || lhref === currentPath) {
                link.classList.add('is-active');
            } else if (lhref && currentPath.startsWith(lhref) && lhref !== '/admin' && !lhref.endsWith('/cp_')) {
                link.classList.add('is-active');
            } else {
                link.classList.remove('is-active');
            }
        });

        // Dispatch Custom Event so other modules can rebind listeners
        document.dispatchEvent(new CustomEvent('spa:navigated', {
            detail: { url: window.location.href }
        }));

        // Trigger DOMContentLoaded callbacks if registered
        if (window.GitPHP && typeof window.GitPHP.reinit === 'function') {
            window.GitPHP.reinit();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Event Listeners Setup                                              */
    /* ------------------------------------------------------------------ */
    function initSpa() {
        // Link click interception
        document.addEventListener('click', function (e) {
            var anchor = e.target.closest('a');
            if (!anchor || !shouldIntercept(anchor)) return;

            // If anchor is handled by instant tab switcher, skip SPA navigation
            if (anchor.hasAttribute('data-tab-target')) return;

            e.preventDefault();
            navigate(anchor.href, true);
        });

        // Prefetch on hover/touchstart
        document.addEventListener('mouseover', function (e) {
            var anchor = e.target.closest('a');
            if (anchor && shouldIntercept(anchor) && !anchor.hasAttribute('data-tab-target')) {
                prefetch(anchor.href);
            }
        }, { passive: true });

        document.addEventListener('touchstart', function (e) {
            var anchor = e.target.closest('a');
            if (anchor && shouldIntercept(anchor) && !anchor.hasAttribute('data-tab-target')) {
                prefetch(anchor.href);
            }
        }, { passive: true });

        // History Back / Forward navigation
        window.addEventListener('popstate', function (e) {
            var currentParam = new URLSearchParams(window.location.search).get('tab');
            var tabGroups = document.querySelectorAll('[data-tab-group]');

            if (tabGroups.length > 0 && currentParam) {
                initInstantTabs();
            } else {
                navigate(window.location.href, false);
            }
        });

        // Initial setup
        initInstantTabs();
    }

    // Export API
    window.GitPHPSPA = {
        navigate: navigate,
        prefetch: prefetch,
        switchTab: switchTab,
        reinit: reinitPage,
        clearCache: function () { cache.clear(); }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSpa);
    } else {
        initSpa();
    }
})(window, document);
