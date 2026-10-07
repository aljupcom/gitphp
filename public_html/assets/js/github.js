/**
 * GitPHP — GitHub Exact Clone UI & Interactive Behaviors
 * Handles all header dropdowns, drawers, search, theme toggle, and code copy buttons.
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    /* ── 1. Theme Toggle ─────────────────────────────────────────────────── */
    function initThemeToggle() {
        document.querySelectorAll('.gh-theme-toggle, #gh-theme-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var current = document.documentElement.getAttribute('data-theme') || 'dark';
                var next = current === 'light' ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', next);
                try {
                    localStorage.setItem('gh-theme', next);
                } catch (err) {}
            });
        });
    }

    /* ── 2. Mobile Drawer Navigation ─────────────────────────────────────── */
    function initDrawer() {
        var drawer = document.getElementById('gh-drawer');
        var toggle = document.querySelector('.gh-header-toggle');
        if (!drawer) return;

        function openDrawer() {
            drawer.classList.add('open');
            document.body.classList.add('gh-drawer-open');
            if (toggle) toggle.setAttribute('aria-expanded', 'true');
        }

        function closeDrawer() {
            drawer.classList.remove('open');
            document.body.classList.remove('gh-drawer-open');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.gh-header-toggle');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                if (drawer.classList.contains('open')) {
                    closeDrawer();
                } else {
                    openDrawer();
                }
                return;
            }

            if (e.target.closest('[data-drawer-close]') || e.target.classList.contains('gh-drawer-backdrop')) {
                e.preventDefault();
                closeDrawer();
                return;
            }

            if (drawer.classList.contains('open') && e.target.closest('.gh-drawer a')) {
                closeDrawer();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && drawer.classList.contains('open')) {
                closeDrawer();
            }
        });
    }

    /* ── 3. Quick Create Dropdown (+) ────────────────────────────────────── */
    function initCreateMenu() {
        var createDd = document.querySelector('.gh-create-dd');
        var createMenu = document.getElementById('gh-create-menu') || document.querySelector('.gh-create-menu');
        var createBtn = document.getElementById('gh-create-btn') || document.querySelector('.gh-create-dd button');

        function closeCreate() {
            if (createDd) createDd.classList.remove('open');
            if (createMenu) createMenu.classList.remove('open');
            if (createBtn) createBtn.setAttribute('aria-expanded', 'false');
        }

        function openCreate() {
            // Close other popovers first
            document.querySelectorAll('.gh-user-dd, .gh-user-panel, .gh-clone-dd, .gh-clone-panel').forEach(function (el) {
                el.classList.remove('open');
            });
            if (createDd) createDd.classList.add('open');
            if (createMenu) createMenu.classList.add('open');
            if (createBtn) createBtn.setAttribute('aria-expanded', 'true');
        }

        // Language dropdown handler
        var langDd = document.querySelector('.gh-lang-dd');
        var langMenu = document.getElementById('gh-lang-menu');
        var langBtn = document.getElementById('gh-lang-btn');

        function closeLang() {
            if (langDd) langDd.classList.remove('open');
            if (langMenu) langMenu.classList.remove('open');
        }

        function openLang() {
            document.querySelectorAll('.gh-user-dd, .gh-user-panel, .gh-create-dd, .gh-create-menu').forEach(function (el) {
                el.classList.remove('open');
            });
            if (langDd) langDd.classList.add('open');
            if (langMenu) langMenu.classList.add('open');
        }

        document.addEventListener('click', function (e) {
            var lBtn = e.target.closest('#gh-lang-btn') || e.target.closest('.gh-lang-dd > button');
            if (lBtn) {
                e.preventDefault();
                e.stopPropagation();
                var isLOpen = (langMenu && langMenu.classList.contains('open')) || (langDd && langDd.classList.contains('open'));
                if (isLOpen) closeLang(); else openLang();
                return;
                closeLang();
            }

            var btn = e.target.closest('#gh-create-btn') || e.target.closest('.gh-create-dd > button');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                var isOpen = (createMenu && createMenu.classList.contains('open')) || (createDd && createDd.classList.contains('open'));
                if (isOpen) {
                    closeCreate();
                } else {
                    openCreate();
                }
                return;
            }

            if (createMenu && (createMenu.classList.contains('open') || (createDd && createDd.classList.contains('open')))) {
                if (!e.target.closest('.gh-create-dd')) {
                    closeCreate();
                }
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeCreate();
            }
        });
    }

    /* ── 4. User Account Menu (Avatar Dropdown) ──────────────────────────── */
    function initUserMenu() {
        var userDd = document.querySelector('.gh-user-dd');
        var userPanel = document.getElementById('gh-user-menu') || document.querySelector('.gh-user-panel');
        var userBtn = document.getElementById('gh-user-btn') || document.querySelector('.gh-avatar');

        function closeUser() {
            if (userDd) userDd.classList.remove('open');
            if (userPanel) userPanel.classList.remove('open');
            if (userBtn) userBtn.setAttribute('aria-expanded', 'false');
        }

        function openUser() {
            // Close other popovers first
            document.querySelectorAll('.gh-create-dd, .gh-create-menu, .gh-clone-dd, .gh-clone-panel').forEach(function (el) {
                el.classList.remove('open');
            });
            if (userDd) userDd.classList.add('open');
            if (userPanel) userPanel.classList.add('open');
            if (userBtn) userBtn.setAttribute('aria-expanded', 'true');
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('#gh-user-btn') || e.target.closest('.gh-user-dd > button') || e.target.closest('.gh-avatar');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                var isOpen = (userPanel && userPanel.classList.contains('open')) || (userDd && userDd.classList.contains('open'));
                if (isOpen) {
                    closeUser();
                } else {
                    openUser();
                }
                return;
            }

            if (userPanel && (userPanel.classList.contains('open') || (userDd && userDd.classList.contains('open')))) {
                if (!e.target.closest('.gh-user-dd')) {
                    closeUser();
                }
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeUser();
            }
        });
    }

    /* ── 5. Repository "Code" Clone Dropdown ─────────────────────────────── */
    function initCloneDropdown() {
        document.addEventListener('click', function (e) {
            var toggle = e.target.closest('.gh-clone-toggle');
            if (toggle) {
                e.preventDefault();
                e.stopPropagation();
                var dd = toggle.closest('.gh-clone-dd');
                var panel = dd ? dd.querySelector('.gh-clone-panel') : null;
                if (!dd || !panel) return;

                var isOpen = dd.classList.contains('open') || panel.classList.contains('open');

                // Close other menus first
                document.querySelectorAll('.gh-create-dd, .gh-create-menu, .gh-user-dd, .gh-user-panel').forEach(function (el) {
                    el.classList.remove('open');
                });

                if (isOpen) {
                    dd.classList.remove('open');
                    panel.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                } else {
                    dd.classList.add('open');
                    panel.classList.add('open');
                    toggle.setAttribute('aria-expanded', 'true');
                }
                return;
            }

            if (!e.target.closest('.gh-clone-dd')) {
                document.querySelectorAll('.gh-clone-dd, .gh-clone-panel').forEach(function (el) {
                    el.classList.remove('open');
                });
                document.querySelectorAll('.gh-clone-toggle').forEach(function (t) {
                    t.setAttribute('aria-expanded', 'false');
                });
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.gh-clone-dd, .gh-clone-panel').forEach(function (el) {
                    el.classList.remove('open');
                });
                document.querySelectorAll('.gh-clone-toggle').forEach(function (t) {
                    t.setAttribute('aria-expanded', 'false');
                });
            }
        });
    }

    /* ── 6. Universal Code Block Copy Button (Clean & De-duplicated) ──────── */
    function initCodeCopyButtons() {
        var copySvg = '<svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M0 6.75C0 5.784.784 5 1.75 5h1.5a.75.75 0 0 1 0 1.5h-1.5a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-1.5a.75.75 0 0 1 1.5 0v1.5A1.75 1.75 0 0 1 9.25 16h-7.5A1.75 1.75 0 0 1 0 14.25Z"/><path d="M5 1.75C5 .784 5.784 0 6.75 0h7.5C15.216 0 16 .784 16 1.75v7.5A1.75 1.75 0 0 1 14.25 11h-7.5A1.75 1.75 0 0 1 5 9.25Zm1.75-.25a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-7.5a.25.25 0 0 0-.25-.25Z"/></svg>';
        var checkSvg = '<svg width="12" height="12" viewBox="0 0 16 16" fill="#3fb950"><path d="M13.78 4.22a.75.75 0 0 1 0 1.06l-7.25 7.25a.75.75 0 0 1-1.06 0L2.22 9.28a.751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018L6 10.94l6.72-6.72a.75.75 0 0 1 1.06 0Z"/></svg>';

        // Markdown code blocks (README/wiki) are handled by main.js with the
        // outer .gh-code-copy button only; this injects the docs copy button.
        document.querySelectorAll('pre.gh-docs-code, .gh-docs-section pre').forEach(function (pre) {
            // Avoid adding multiple buttons to the same block (only this block
            // or its own .gh-code-block wrapper, not siblings sharing a parent).
            if (pre.querySelector('.gh-code-copy-btn') ||
                (pre.parentElement && pre.parentElement.classList.contains('gh-code-block') && pre.parentElement.querySelector('.gh-code-copy'))) {
                return;
            }
            if (pre.closest('.diff-view') || pre.closest('.blob-wrapper')) return;

            // Build the fixed outer-button pattern (same as main.js):
            // .gh-code-block (position:relative, never scrolls) > <pre> (the only
            // scrollable area) + .gh-code-copy (absolute sibling -> stays fixed).
            if (pre.parentElement && pre.parentElement.classList.contains('gh-code-block')) return;

            var wrap = document.createElement('div');
            wrap.className = 'gh-code-block';
            pre.parentNode.insertBefore(wrap, pre);
            wrap.appendChild(pre);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gh-code-copy';
            btn.setAttribute('aria-label', 'Copy code to clipboard');
            btn.setAttribute('title', 'Copy code');
            btn.innerHTML = copySvg;

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var codeEl = pre.querySelector('code');
                var text = (codeEl ? codeEl.innerText : pre.innerText).trim();
                text = text.replace(/^(Copy|Copied!|نسخ|تم النسخ!)\s*/i, '').trim();

                navigator.clipboard.writeText(text).then(function () {
                    btn.classList.add('copied');
                    btn.innerHTML = checkSvg;
                    setTimeout(function () {
                        btn.classList.remove('copied');
                        btn.innerHTML = copySvg;
                    }, 2000);
                }).catch(function () {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    btn.classList.add('copied');
                    btn.innerHTML = checkSvg;
                    setTimeout(function () {
                        btn.classList.remove('copied');
                        btn.innerHTML = copySvg;
                    }, 2000);
                });
            });

            wrap.appendChild(btn);
        });
    }

    /* ── 7. Home Filter Search (with "/" shortcut) ───────────────────────── */
    function initHomeSearch() {
        var input = document.getElementById('gh-search-input');
        if (!input) return;

        document.addEventListener('keydown', function (e) {
            var tag = (e.target && e.target.tagName) || '';
            var typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
            if (e.key === '/' && !typing) {
                e.preventDefault();
                input.focus();
                input.select();
            }
        });
    }

    /* ── 8. Add File Dropdown ────────────────────────────────────────────── */
    function initAddFileDropdown() {
        document.querySelectorAll('.gh-add-file-toggle').forEach(function (toggle) {
            var dd = toggle.closest('.gh-add-file-dd');
            if (!dd) return;

            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var isOpen = dd.classList.contains('open');

                // Close other popovers
                document.querySelectorAll('.gh-create-dd, .gh-create-menu, .gh-user-dd, .gh-user-panel, .gh-clone-dd, .gh-clone-panel').forEach(function (el) {
                    el.classList.remove('open');
                });

                if (isOpen) {
                    dd.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                } else {
                    dd.classList.add('open');
                    toggle.setAttribute('aria-expanded', 'true');
                }
            });
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.gh-add-file-dd')) {
                document.querySelectorAll('.gh-add-file-dd').forEach(function (el) {
                    el.classList.remove('open');
                });
                document.querySelectorAll('.gh-add-file-toggle').forEach(function (t) {
                    t.setAttribute('aria-expanded', 'false');
                });
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.gh-add-file-dd').forEach(function (el) {
                    el.classList.remove('open');
                });
                document.querySelectorAll('.gh-add-file-toggle').forEach(function (t) {
                    t.setAttribute('aria-expanded', 'false');
                });
            }
        });
    }

    /* ── 9. Quick Go-to-file Search & "t" Shortcut ────────────────────────── */
    function initFileFinder() {
        var trigger = document.getElementById('gh-find-file-trigger');

        function triggerFinder() {
            var table = document.querySelector('.gh-file-table');
            if (table) {
                table.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        if (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                triggerFinder();
            });
        }

        document.addEventListener('keydown', function (e) {
            var tag = (e.target && e.target.tagName) || '';
            var typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
            if ((e.key === 't' || e.key === 'T') && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) {
                var btn = document.getElementById('gh-find-file-trigger');
                if (btn) {
                    e.preventDefault();
                    triggerFinder();
                }
            }
        });
    }

    function initNotificationPoll() {
        var INTERVAL = 30000;
        var lastCount = -1;

        function setBadge(count) {
            var link = document.querySelector('.gh-notification-btn');
            if (!link) return;
            if (!count || count <= 0) {
                var b = link.querySelector('.gh-notification-badge');
                if (b) b.style.display = 'none';
                link.setAttribute('aria-label', 'Notifications');
                return;
            }
            var badge = link.querySelector('.gh-notification-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'gh-notification-badge';
                badge.setAttribute('aria-hidden', 'true');
                link.appendChild(badge);
            }
            badge.style.display = 'flex';
            badge.textContent = count > 99 ? '99+' : String(count);
            link.setAttribute('aria-label', 'Notifications — ' + count + ' unread');
        }

        function poll() {
            if (document.hidden) return;
            fetch('/api/v1/notifications/unread', {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            }).then(function (r) { return r.ok ? r.json() : { count: 0 }; })
              .then(function (data) {
                  var count = data && typeof data.count === 'number' ? data.count : 0;
                  if (count !== lastCount) { lastCount = count; setBadge(count); }
              })
              .catch(function () { /* keep last known badge */ });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) poll();
        });
        poll();
        setInterval(poll, INTERVAL);
    }

    function initAllGitHubBehaviors() {
        initThemeToggle();
        initDrawer();
        initCreateMenu();
        initUserMenu();
        initCloneDropdown();
        initAddFileDropdown();
        initFileFinder();
        initCodeCopyButtons();
        initHomeSearch();
        initNotificationPoll();
    }

    // Initialize all modules on load and on SPA page transitions
    initAllGitHubBehaviors();
    document.addEventListener('spa:navigated', initAllGitHubBehaviors);

    // Global Click Listener: Close all popover dropdowns when clicking outside
    document.addEventListener('click', function (e) {
        // If click is outside add file dropdown, close it
        if (!e.target.closest('.gh-add-file-dd')) {
            document.querySelectorAll('.gh-add-file-dd.open').forEach(function (el) {
                el.classList.remove('open');
            });
        }
        // If click is outside clone dropdown, close it
        if (!e.target.closest('.gh-clone-dd')) {
            document.querySelectorAll('.gh-clone-dd.open, .gh-clone-panel.open').forEach(function (el) {
                el.classList.remove('open');
            });
        }
    });
});
