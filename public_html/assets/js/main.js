/**
 * GitPHP client-side behaviors.
 *
 * The Content-Security-Policy (`script-src 'self' ...`) forbids inline event
 * handlers and inline <script> blocks, so every behavior is wired up here
 * against data attributes / well-known ids. Loaded with `defer` from
 * layout.twig — CSP-safe, cache-busted via the asset() version query.
 */
(function () {
    'use strict';

    function initAllBehaviors() {
        initNavToggle();
        initSelectOnClick();
        initCodeCopyButtons();
        initCopyButtons();
        initAlertBlockquotes();
        initConfirmForms();
        initRefSelector();
        initHighlight();
        initSlugAutogen();
        initImportLookup();
        initMarkdownToolbar();
        initWikiPreview();
        initRepoFilter();
        initPasswordToggles();
        initDocsTableOfContents();
        initHeaderSearch();
        initMarkdownAnchors();
        initFastNavigation();
        initCommandPalette();
        initBlobLineHighlight();
        initToastTimers();
    }

    document.addEventListener('DOMContentLoaded', initAllBehaviors);
    document.addEventListener('spa:navigated', initAllBehaviors);

    /* Header: instant typeahead search (GitHub-like) ------------------ */
    function initHeaderSearch() {
        var input = document.getElementById('gh-search-input');
        var dd    = document.getElementById('gh-search-dd');
        var form  = document.getElementById('gh-search-form');
        if (!input || !dd) return;

        var timer = null;

        function close() {
            dd.hidden = true;
            dd.innerHTML = '';
        }

        function item(type, icon, title, meta, href) {
            return '<a class="gh-search-dd-item" href="' + href + '">' +
                '<span class="gh-icon">' + icon + '</span>' +
                '<span class="gh-search-dd-title">' + title + '</span>' +
                (meta ? '<span class="gh-meta">' + meta + '</span>' : '') +
                '</a>';
        }

        var ICONS = {
            repo: '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M2 2.5A2.5 2.5 0 0 1 4.5 0h8.75a.75.75 0 0 1 .75.75v12.5a.75.75 0 0 1-.75.75h-2.5a.75.75 0 0 1 0-1.5h1.75v-2h-8a1 1 0 0 0-.714 1.7.75.75 0 1 1-1.072 1.05A2.495 2.495 0 0 1 2 11.5Zm10.5-1h-8a1 1 0 0 0-1 1v6.708A2.486 2.486 0 0 1 4.5 9h8Z"/></svg>',
            user: '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M6.75 0a4.25 4.25 0 0 0-3 7.19A7.507 7.507 0 0 0 0 13.25a.75.75 0 0 0 1.5 0c0-2.9 2.9-5.25 6.5-5.25s6.5 2.35 6.5 5.25a.75.75 0 0 0 1.5 0A7.508 7.508 0 0 0 12.25 7.19 4.25 4.25 0 0 0 6.75 0Z"/></svg>',
            issue: '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M8 9.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/><path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Z"/></svg>',
            pull: '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M1.5 3.25a2.25 2.25 0 1 1 3 2.122v5.256a2.251 2.251 0 1 1-1.5 0V5.372A2.25 2.25 0 0 1 1.5 3.25Zm5.677-.177L9.573.677A.25.25 0 0 1 10 .854V2.5h1A2.5 2.5 0 0 1 13.5 5v5.628a2.251 2.251 0 1 1-1.5 0V5a1 1 0 0 0-1-1h-1v1.646a.25.25 0 0 1-.427.177L7.177 3.427a.25.25 0 0 1 0-.354Z"/></svg>'
        };

        function render(data) {
            var html = '';
            var count = 0;

            function section(label) {
                if (count === 0) html += '<div class="gh-search-dd-sec">' + label + '</div>';
            }

            if (data.repositories && data.repositories.length) {
                section('Repositories');
                data.repositories.forEach(function (r) {
                    html += item('repo', ICONS.repo, r.owner + '/' + r.name, '', '/' + r.owner + '/' + r.slug);
                    count++;
                });
            }
            if (data.users && data.users.length) {
                section('Users');
                data.users.forEach(function (u) {
                    html += item('user', ICONS.user, u.username, '', '/' + u.username);
                    count++;
                });
            }
            if (data.issues && data.issues.length) {
                section('Issues');
                data.issues.forEach(function (i) {
                    html += item('issue', ICONS.issue, i.title, '#' + i.id, '/' + i.repo_owner + '/' + i.repo_slug + '/issues/' + i.id);
                    count++;
                });
            }
            if (data.pulls && data.pulls.length) {
                section('Pull requests');
                data.pulls.forEach(function (p) {
                    html += item('pull', ICONS.pull, p.title, '#' + p.number, '/' + p.repo_owner + '/' + p.repo_slug + '/pull/' + p.number);
                    count++;
                });
            }

            html += '<a class="gh-search-dd-all" href="/search?q=' + encodeURIComponent(data.query) + '">View all results</a>';
            dd.innerHTML = count > 0 ? html : '<div class="gh-search-dd-empty">No results for “' + data.query + '”.</div>';
            dd.hidden = false;
        }

        function fetchSuggest(q) {
            fetch('/api/search/suggest?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(render)
                .catch(function () {});
        }

        input.addEventListener('input', function () {
            var q = input.value.trim();
            if (q.length < 2) { close(); return; }
            clearTimeout(timer);
            timer = setTimeout(function () { fetchSuggest(q); }, 220);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === '/') { close(); return; }
            if (e.key === 'Escape') { close(); return; }
            if (e.key === 'Enter') {
                if (form) form.submit();
            }
        });

        document.addEventListener('click', function (e) {
            if (!form || !form.contains(e.target)) close();
        });

        // Focus the search field with "/" or Ctrl/Cmd+K.
        document.addEventListener('keydown', function (e) {
            var target = e.target;
            if ((e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName)) || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) {
                e.preventDefault();
                input.focus();
                input.select();
            }
        });
    }

    /* Docs: highlight the TOC entry of the section in view (scrollspy) */
    function initDocsTableOfContents() {
        var tocLinks = document.querySelectorAll('.gh-docs-toc nav a');
        if (!tocLinks.length) return;

        var sections = [];
        tocLinks.forEach(function (a) {
            var target = document.getElementById(a.getAttribute('href').replace('#', ''));
            if (target) sections.push({ a: a, el: target });
        });
        if (!sections.length) return;

        function onScroll() {
            var pos = window.scrollY + 140;
            var current = null;
            sections.forEach(function (item) {
                if (item.el.offsetTop <= pos) current = item;
            });
            sections.forEach(function (item) { item.a.classList.toggle('is-active', item === current); });
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* Auth: show / hide password fields -------------------------------- */
    function initPasswordToggles() {
        document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.querySelector(btn.getAttribute('data-toggle-password'));
                if (!input) return;

                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';

                var eye    = btn.querySelector('svg');
                if (!eye) return;

                // Swap between the eye and eye-slash glyphs.
                if (show) {
                    eye.innerHTML = '<path d="M8.156 12.187 5.5 9.53l-.014.014a3.25 3.25 0 1 1 4.558-4.558l.013-.013 2.657 2.656-.014.014a3.25 3.25 0 1 1-4.558 4.558Zm2.829-4.597a1.75 1.75 0 0 0-2.074-2.074l2.074 2.074ZM5.016 7.41a1.75 1.75 0 0 0 2.074 2.074L5.016 7.41Z"/>' +
                                    '<path d="M.338 1.662a.75.75 0 0 1 1.06 0l13.44 13.44a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215L.278 2.722a.75.75 0 0 1 .06-1.06Z"/>';
                } else {
                    eye.innerHTML = '<path d="M8 2c1.981 0 3.671.992 4.933 2.078 1.27 1.091 2.187 2.345 2.637 3.023a1.62 1.62 0 0 1 0 1.798c-.45.678-1.367 1.932-2.637 3.023C11.67 13.008 9.981 14 8 14c-1.981 0-3.671-.992-4.933-2.078C1.797 10.83.88 9.576.43 8.898a1.62 1.62 0 0 1 0-1.798c.45-.677 1.367-1.931 2.637-3.022C4.33 2.992 6.019 2 8 2ZM8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Z"/>';
                }
            });
        });
    }

    /* Home page: live repository filter -------------------------------- */
    function initRepoFilter() {
        var input  = document.getElementById('gh-repo-filter');
        var grid   = document.getElementById('gh-repo-grid');
        var empty  = document.getElementById('gh-no-results');
        if (!input || !grid) return;

        input.addEventListener('input', function () {
            var term = input.value.trim().toLowerCase();
            var visible = 0;

            grid.querySelectorAll('[data-search]').forEach(function (card) {
                var match = term === '' || card.getAttribute('data-search').indexOf(term) !== -1;
                card.style.display = match ? '' : 'none';
                if (match) visible++;
            });

            if (empty) empty.classList.toggle('gh-hidden', visible > 0);
        });
    }

    /* Mobile navigation dropdown -------------------------------------- */
    function initNavToggle() {
        var toggle = document.querySelector('.nav-toggle');
        var links  = document.querySelector('.nav-links');
        if (!toggle || !links) return;

        toggle.addEventListener('click', function () {
            var open = links.classList.toggle('open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        // Choosing a destination closes the panel again.
        links.addEventListener('click', function (e) {
            if (e.target.closest('a')) {
                links.classList.remove('open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    /* Click-to-select readonly inputs (clone URLs) --------------------- */
    function initSelectOnClick() {
        document.querySelectorAll('input[data-select-on-click]').forEach(function (input) {
            input.addEventListener('click', function () { input.select(); });
        });
    }

    /* Copy buttons on rendered code blocks (README) -------------------- */
    function initCodeCopyButtons() {
        var ICON = '<svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M0 6.75C0 5.784.784 5 1.75 5h1.5a.75.75 0 0 1 0 1.5h-1.5a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-1.5a.75.75 0 0 1 1.5 0v1.5A1.75 1.75 0 0 1 9.25 16h-7.5A1.75 1.75 0 0 1 0 14.25Z"/><path d="M5 1.75C5 .784 5.784 0 6.75 0h7.5C15.216 0 16 .784 16 1.75v7.5A1.75 1.75 0 0 1 14.25 11h-7.5A1.75 1.75 0 0 1 5 9.25Zm1.75-.25a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-7.5a.25.25 0 0 0-.25-.25Z"/></svg>';

        document.querySelectorAll('.markdown-body pre').forEach(function (pre) {
            if (pre.parentNode.classList.contains('gh-code-block')) {
                return;
            }

            var code = pre.querySelector('code');
            var text = (code ? code.textContent : pre.textContent).replace(/\n$/, '');

            var wrap = document.createElement('div');
            wrap.className = 'gh-code-block';
            pre.parentNode.insertBefore(wrap, pre);
            wrap.appendChild(pre);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'gh-code-copy';
            btn.setAttribute('data-copy', text);
            btn.setAttribute('aria-label', 'Copy code');
            btn.title = 'Copy code';
            btn.innerHTML = ICON;
            wrap.appendChild(btn);
        });
    }

    /* GitHub Alert Blockquotes ([!NOTE], [!TIP], [!IMPORTANT], [!WARNING], [!CAUTION]) */
    function initAlertBlockquotes() {
        document.querySelectorAll('.markdown-body blockquote').forEach(function (bq) {
            if (bq.dataset.alertParsed) return;
            bq.dataset.alertParsed = 'true';
            var firstP = bq.querySelector('p') || bq;
            var text = firstP.innerHTML;
            var match = text.match(/^\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*/i);
            if (match) {
                var type = match[1].toLowerCase();
                bq.classList.add('gh-alert-' + type);
                var titleText = match[1].toUpperCase();
                var icon = '';
                var color = '#8b949e';
                if (type === 'note') { color = '#2f81f7'; icon = '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:text-bottom"><path d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8Zm8-6.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13ZM6.5 7.75A.75.75 0 0 1 7.25 7h1.5a.75.75 0 0 1 .75.75v2.75h.25a.75.75 0 0 1 0 1.5h-1.75a.75.75 0 0 1 0-1.5h.25V8.5h-.5a.75.75 0 0 1-.75-.75ZM8 6a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z"/></svg>'; }
                else if (type === 'tip') { color = '#3fb950'; icon = '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:text-bottom"><path d="M8 1.5c-2.363 0-4 1.69-4 3.75 0 .984.424 1.625.984 2.304l.214.253c.223.264.47.556.673.848.284.411.537.967.629 1.645h3.001c.092-.678.345-1.234.629-1.645.203-.292.45-.584.673-.848l.214-.253c.56-.679.984-1.32.984-2.304 0-2.06-1.637-3.75-4-3.75Z"/></svg>'; }
                else if (type === 'important') { color = '#a371f7'; icon = '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:text-bottom"><path d="M0 1.75C0 .784.784 0 1.75 0h12.5C15.216 0 16 .784 16 1.75v9.5A1.75 1.75 0 0 1 14.25 13H11l-3.5 3.5L4 13H1.75A1.75 1.75 0 0 1 0 11.25Z"/></svg>'; }
                else if (type === 'warning') { color = '#d29922'; icon = '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:text-bottom"><path d="M6.457 1.047c.659-1.234 2.427-1.234 3.086 0l6.082 11.378A1.75 1.75 0 0 1 14.082 15H1.918a1.75 1.75 0 0 1-1.543-2.575Zm1.763.707a.25.25 0 0 0-.44 0L1.698 13.132a.25.25 0 0 0 .22.368h12.164a.25.25 0 0 0 .22-.368Zm.53 3.996v2.5a.75.75 0 0 1-1.5 0v-2.5a.75.75 0 0 1 1.5 0ZM9 11a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg>'; }
                else if (type === 'caution') { color = '#f85149'; icon = '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="vertical-align:text-bottom"><path d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8Zm8-6.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13ZM4.72 4.72a.75.75 0 0 1 1.06 0L8 6.94l2.22-2.22a.75.75 0 1 1 1.06 1.06L9.06 8l2.22 2.22a.75.75 0 1 1-1.06 1.06L8 9.06l-2.22 2.22a.75.75 0 0 1-1.06-1.06L6.94 8 4.72 5.78a.75.75 0 0 1 0-1.06Z"/></svg>'; }

                var headerHtml = '<div style="color:' + color + '; display:flex; align-items:center; gap:6px; font-weight:600; font-size:13px; margin-bottom:6px;">' + icon + '<span>' + titleText + '</span></div>';
                var cleanContent = text.replace(/^\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*<br\s*\/?>?/i, '').replace(/^\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*/i, '');
                firstP.innerHTML = headerHtml + (cleanContent ? '<div>' + cleanContent + '</div>' : '');
            }
        });
    }

    /* Clipboard buttons: <button data-copy="text"> ---------------------- */
    function initCopyButtons() {
        document.querySelectorAll('[data-copy]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text     = btn.getAttribute('data-copy') || '';
                var original = btn.innerHTML; // innerHTML so SVG-icon buttons restore intact

                var flash = function () {
                    btn.textContent = '✓';
                    setTimeout(function () { btn.innerHTML = original; }, 1200);
                };
                var fallback = function () {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); } catch (e) { /* ignore */ }
                    document.body.removeChild(ta);
                    flash();
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(flash, fallback);
                } else {
                    fallback();
                }
            });
        });
    }

    /* Destructive forms: <form data-confirm="..."> ---------------------- */
    function initConfirmForms() {
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!window.confirm(form.getAttribute('data-confirm') || 'Are you sure?')) {
                    e.preventDefault();
                }
            });
        });
    }

    /* Branch / tag selector: <select id="ref-select" data-base="..."> --- */
    function initRefSelector() {
        var select = document.getElementById('ref-select');
        if (!select) return;
        select.addEventListener('change', function () {
            var base = select.getAttribute('data-base') || '';
            if (select.value) window.location.href = base + '/' + select.value;
        });
    }

    /* highlight.js (loaded from cdnjs on repo pages) --------------------- */
    function initHighlight() {
        if (!window.hljs) return;
        document.querySelectorAll('.markdown-body pre code, .blob-view pre code, .gh-code-view pre code').forEach(function (el) {
            window.hljs.highlightElement(el);
        });

        // Deep link to a blob line (e.g. #L12)
        if (window.location.hash && document.querySelector('.blob-view, .gh-code-view')) {
            try {
                var line = document.querySelector(window.location.hash);
                if (line) line.style.background = 'var(--accent-dim)';
            } catch (e) { /* invalid selector in hash */ }
        }
    }

    /* Auto-generate slug from name (create form only) -------------------- */
    function initSlugAutogen() {
        var containers = document.querySelectorAll('form[action="/admin/repos"], form[action="/repos/new"]');
        containers.forEach(function (form) {
            var nameInput = form.querySelector('[name="name"]');
            var slugInput = form.querySelector('[name="slug"]');
            if (!nameInput || !slugInput) return;

            var userEdited = false;
            slugInput.addEventListener('input', function () {
                userEdited = slugInput.value.trim() !== '';
            });
            nameInput.addEventListener('input', function () {
                if (!userEdited || slugInput.value === '') {
                    slugInput.value = nameInput.value
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, '-')
                        .replace(/^-+|-+$/g, '')
                        .substring(0, 100);
                }
            });
        });
    }

    /* Import page: remote metadata lookup & instant autofill ------------- */
    function initImportLookup() {
        var form = document.getElementById('import-form');
        if (!form) return;

        var urlInput   = form.querySelector('#source_url');
        var tokenInput = form.querySelector('#access_token');
        var nameInput  = form.querySelector('#name');
        var slugInput  = form.querySelector('#slug');
        var descInput  = form.querySelector('#description');
        var statusEl   = document.getElementById('lookup-status');
        if (!urlInput) return;

        var userEditedSlug = false;
        var userEditedName = false;
        var userEditedDesc = false;
        var lastAutoName   = '';
        var lastAutoSlug   = '';
        var timer          = null;
        var lastLookupUrl  = '';
        var lastLookupTok  = '';

        if (slugInput) {
            slugInput.addEventListener('input', function () {
                userEditedSlug = slugInput.value.trim() !== '' && slugInput.value.trim() !== lastAutoSlug;
            });
        }
        if (nameInput) {
            nameInput.addEventListener('input', function () {
                userEditedName = nameInput.value.trim() !== '' && nameInput.value.trim() !== lastAutoName;
            });
        }
        if (descInput) {
            descInput.addEventListener('input', function () {
                userEditedDesc = descInput.value.trim() !== '';
            });
        }

        function setStatus(text, type) {
            if (!statusEl) return;
            if (!text) {
                statusEl.style.display = 'none';
                statusEl.hidden = true;
                statusEl.innerHTML = '';
                return;
            }
            statusEl.hidden = false;
            statusEl.style.display = 'flex';
            statusEl.style.alignItems = 'center';
            statusEl.style.gap = '6px';
            statusEl.style.marginTop = '6px';
            statusEl.style.fontSize = '12px';
            statusEl.style.lineHeight = '1.4';

            var color = 'var(--gh-accent-fg, #58a6ff)';
            if (type === 'error') {
                color = 'var(--gh-danger-fg, #f85149)';
            } else if (type === 'warn') {
                color = '#d29922';
            } else if (type === 'success') {
                color = '#3fb950';
            }

            statusEl.style.color = color;
            statusEl.textContent = text;
        }

        // Instant extraction and normalization of repository URL
        function parseAndNormalizeUrl(raw) {
            var url = (raw || '').trim();
            if (!url) return { url: '', name: '', slug: '', token: '' };

            var token = '';

            // Handle SSH format: git@github.com:owner/repo.git -> https://github.com/owner/repo.git
            var sshMatch = url.match(/^git@([^:]+):(.+?)(?:\.git)?$/i);
            if (sshMatch) {
                url = 'https://' + sshMatch[1] + '/' + sshMatch[2] + '.git';
            }

            // Handle git:// or http:// -> https://
            if (/^http:\/\//i.test(url)) {
                url = url.replace(/^http:\/\//i, 'https://');
            } else if (/^git:\/\//i.test(url)) {
                url = url.replace(/^git:\/\//i, 'https://');
            } else if (!/^https?:\/\//i.test(url) && /^[\w\.\-]+\/[\w\.\-\/]+/i.test(url)) {
                url = 'https://' + url;
            }

            // Detect embedded token: https://token@github.com/owner/repo or https://user:token@github.com/owner/repo
            var authMatch = url.match(/^https?:\/\/([^@\/]+)@([^\/]+)(.*)$/i);
            if (authMatch) {
                var cred = authMatch[1];
                var host = authMatch[2];
                var rest = authMatch[3];
                if (cred.indexOf(':') !== -1) {
                    token = cred.split(':')[1];
                } else {
                    token = cred;
                }
                url = 'https://' + host + rest;
            }

            // Clean path
            var clean = url.replace(/[?#].*$/, '').replace(/\/+$/, '');
            var m = clean.match(/(?:[:\/])([^/:\s]+?)(?:\.git)?$/i);
            var rawName = (m && m[1]) ? m[1].replace(/\.git$/i, '').trim() : '';
            var slug = rawName.toLowerCase().replace(/[^a-z0-9\-]+/g, '-').replace(/^-+|-+$/g, '');

            return { url: url, name: rawName, slug: slug, token: token };
        }

        function autoFillImmediate(normalizeInput) {
            var raw = urlInput.value;
            if (!raw || !raw.trim()) {
                setStatus('');
                return;
            }

            var parsed = parseAndNormalizeUrl(raw);

            if (normalizeInput && parsed.url && parsed.url !== raw) {
                urlInput.value = parsed.url;
            }

            if (parsed.token && tokenInput && !tokenInput.value.trim()) {
                tokenInput.value = parsed.token;
            }

            if (parsed.name && nameInput && (!userEditedName || !nameInput.value.trim())) {
                nameInput.value = parsed.name;
                lastAutoName = parsed.name;
            }

            if (parsed.slug && slugInput && (!userEditedSlug || !slugInput.value.trim())) {
                slugInput.value = parsed.slug;
                lastAutoSlug = parsed.slug;
            }
        }

        function doLookup() {
            autoFillImmediate(false);
            var url = urlInput.value.trim();
            var tok = tokenInput ? tokenInput.value.trim() : '';

            if (!/^https?:\/\/[^\/]+\/[^\/]+/i.test(url)) {
                setStatus('');
                return;
            }

            if (url === lastLookupUrl && tok === lastLookupTok) return;
            lastLookupUrl = url;
            lastLookupTok = tok;

            setStatus('Looking up repository metadata…', 'info');

            fetch('/repos/import/lookup?url=' + encodeURIComponent(url)
                  + '&token=' + encodeURIComponent(tok), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        setStatus('⚠️ ' + (data.error || 'Repository unreachable'), 'warn');
                        return;
                    }

                    var meta = data.meta || {};
                    if (meta.name && nameInput && (!userEditedName || !nameInput.value.trim())) {
                        nameInput.value = meta.name;
                        lastAutoName = meta.name;
                    }
                    if (meta.slug && slugInput && (!userEditedSlug || !slugInput.value.trim())) {
                        slugInput.value = meta.slug;
                        lastAutoSlug = meta.slug;
                    }
                    if (meta.description && descInput && (!userEditedDesc || !descInput.value.trim())) {
                        descInput.value = meta.description;
                    }

                    if (data.is_partial) {
                        setStatus('ℹ️ ' + (data.error || 'Private repository — please provide an access token if authentication is required.'), 'warn');
                    } else {
                        var branchInfo = meta.default_branch ? (' — default branch: ' + meta.default_branch) : '';
                        setStatus('✓ Remote repository verified' + branchInfo, 'success');
                    }
                })
                .catch(function () {
                    setStatus('');
                });
        }

        urlInput.addEventListener('input', function () {
            autoFillImmediate(false);
            clearTimeout(timer);
            timer = setTimeout(doLookup, 300);
        });

        urlInput.addEventListener('paste', function () {
            setTimeout(function () {
                autoFillImmediate(true);
                doLookup();
            }, 20);
        });

        urlInput.addEventListener('change', function () {
            autoFillImmediate(true);
            doLookup();
        });

        urlInput.addEventListener('blur', function () {
            autoFillImmediate(true);
        });

        if (tokenInput) {
            tokenInput.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    lastLookupTok = '';
                    doLookup();
                }, 400);
            });
            tokenInput.addEventListener('change', function () {
                lastLookupTok = '';
                doLookup();
            });
        }

        if (urlInput.value.trim() !== '') {
            autoFillImmediate(false);
            doLookup();
        }
    }

    /* Markdown toolbar: buttons inside [data-md-target] wrappers --------- */
    function initMarkdownToolbar() {
        var TOOLS = {
            bold:      { pre: '**',      suf: '**', ph: 'bold text' },
            italic:    { pre: '*',       suf: '*',  ph: 'italic text' },
            h2:        { pre: '## ',     suf: '',   ph: 'Heading', line: true },
            link:      { pre: '[',       suf: '](https://example.com)', ph: 'link text' },
            code:      { pre: '`',       suf: '`',  ph: 'code' },
            codeblock: { pre: '```\n',   suf: '\n```', ph: 'code block', line: true },
            ul:        { pre: '- ',      suf: '',   ph: 'list item', line: true },
            ol:        { pre: '1. ',     suf: '',   ph: 'list item', line: true },
            quote:     { pre: '> ',      suf: '',   ph: 'quote', line: true }
        };

        document.querySelectorAll('.gh-md-toolbar').forEach(function (toolbar) {
            var textarea = document.getElementById(toolbar.getAttribute('data-md-target') || '');
            if (!textarea) return;

            toolbar.querySelectorAll('button[data-md-action]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var tool = TOOLS[btn.getAttribute('data-md-action') || ''];
                    if (!tool) return;

                    if (tool.line) {
                        prefixLines(textarea, tool.pre);
                    } else {
                        wrapSelection(textarea, tool.pre, tool.suf, tool.ph);
                    }

                    textarea.focus();
                    textarea.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });
        });
    }

    /* Wrap the selected text (or the placeholder) with prefix/suffix. ----- */
    function wrapSelection(textarea, prefix, suffix, placeholder) {
        var start    = textarea.selectionStart;
        var end      = textarea.selectionEnd;
        var selected = textarea.value.substring(start, end);
        var text     = selected !== '' ? selected : placeholder;

        textarea.value = textarea.value.substring(0, start) + prefix + text + suffix + textarea.value.substring(end);
        textarea.setSelectionRange(start + prefix.length, start + prefix.length + text.length);
    }

    /* Prefix every line of the selection (or the current line). ---------- */
    function prefixLines(textarea, prefix) {
        var value = textarea.value;
        var start = textarea.selectionStart;
        var end   = textarea.selectionEnd;

        if (start === end) {
            // No selection: act on the whole current line.
            start = value.lastIndexOf('\n', start - 1) + 1;
            var lineEnd = value.indexOf('\n', end);
            end = lineEnd === -1 ? value.length : lineEnd;
        }

        var lines = value.substring(start, end).split('\n');
        var out   = lines.map(function (line) { return prefix + line; }).join('\n');

        textarea.value = value.substring(0, start) + out + value.substring(end);
        textarea.setSelectionRange(start, start + out.length);
    }

    /* Wiki editor: live Markdown preview via the same-origin endpoint. ---- */
    function initWikiPreview() {
        var editor = document.querySelector('textarea[data-md-preview-url]');
        if (!editor) return;

        var preview = document.getElementById('gh-wiki-preview');
        if (!preview) return;

        var body = preview.querySelector('.gh-wiki-preview-body');
        if (!body) return;

        var timer = null;
        var csrfInput = document.querySelector('input[name="csrf_token"]');

        function refresh() {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var url = editor.getAttribute('data-md-preview-url');

                var payload = 'content=' + encodeURIComponent(editor.value);
                if (csrfInput && csrfInput.value) {
                    payload += '&csrf_token=' + encodeURIComponent(csrfInput.value);
                }

                fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: payload,
                    credentials: 'same-origin'
                })
                    .then(function (r) {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then(function (data) {
                        if (!data.ok) return;

                        body.innerHTML = data.html || '';
                        preview.hidden = editor.value.trim() === '';

                        if (window.hljs) {
                            body.querySelectorAll('pre code').forEach(function (el) {
                                window.hljs.highlightElement(el);
                            });
                        }
                    })
                    .catch(function () { /* preview is best-effort */ });
            }, 350);
        }

        editor.addEventListener('input', refresh);

        if (editor.value.trim() !== '') refresh();
    }

    /* Markdown Anchor / Fast jump navigation ----------------------------- */
    function initMarkdownAnchors() {
        function getTargetElement(hash) {
            if (!hash) return null;
            var clean = hash.replace(/^#/, '');
            if (!clean) return null;
            var decoded = clean;
            try { decoded = decodeURIComponent(clean); } catch (e) {}

            var el = document.getElementById(decoded) || document.getElementById(clean);
            if (!el) {
                try {
                    el = document.querySelector('[name="' + CSS.escape(decoded) + '"]') || document.querySelector('[name="' + CSS.escape(clean) + '"]');
                } catch (e) {
                    el = document.querySelector('[name="' + decoded + '"]');
                }
            }
            return el;
        }

        function scrollToHash(hash, smooth) {
            var target = getTargetElement(hash);
            if (target) {
                target.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' });
            }
        }

        if (window.location.hash) {
            setTimeout(function () {
                scrollToHash(window.location.hash, false);
            }, 100);
        }

        document.addEventListener('click', function (e) {
            var a = e.target.closest('a');
            if (!a) return;
            var href = a.getAttribute('href');
            if (!href || href.charAt(0) !== '#') return;

            var target = getTargetElement(href);
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                if (history.pushState) {
                    history.pushState(null, '', href);
                } else {
                    window.location.hash = href;
                }
            }
        });
    }

    /* Fast navigation & top progress bar (GitHub Turbo style) ---------- */
    function initFastNavigation() {
        var loader = document.getElementById("gh-top-loader");
        if (!loader) {
            loader = document.createElement("div");
            loader.id = "gh-top-loader";
            document.body.appendChild(loader);
        }

        var progressTimer = null;
        var prefetchedUrls = new Set();

        function startProgress() {
            if (progressTimer) clearInterval(progressTimer);
            loader.style.opacity = "1";
            loader.style.width = "25%";
            var w = 25;
            progressTimer = setInterval(function () {
                if (w < 85) {
                    w += (85 - w) * 0.15;
                    loader.style.width = w + "%";
                }
            }, 100);
        }

        function finishProgress() {
            if (progressTimer) clearInterval(progressTimer);
            loader.style.width = "100%";
            setTimeout(function () {
                loader.style.opacity = "0";
                setTimeout(function () {
                    loader.style.width = "0%";
                }, 250);
            }, 150);
        }

        function prefetch(url) {
            if (!url || prefetchedUrls.has(url)) return;
            if (url.startsWith("#") || url.startsWith("javascript:") || url.startsWith("mailto:")) return;
            if (url.includes("/archive/") || url.includes("/raw/") || url.includes("/download")) return;

            try {
                var u = new URL(url, window.location.href);
                if (u.origin !== window.location.origin) return;
                prefetchedUrls.add(url);
                var link = document.createElement("link");
                link.rel = "prefetch";
                link.href = url;
                document.head.appendChild(link);
            } catch (e) {}
        }

        // Finish progress on page load / restore
        finishProgress();
        window.addEventListener("pageshow", finishProgress);

        // Pre-fetch links on hover / touch
        document.addEventListener("mouseover", function (e) {
            var a = e.target.closest("a");
            if (a && a.href) prefetch(a.href);
        }, { passive: true });

        document.addEventListener("touchstart", function (e) {
            var a = e.target.closest("a");
            if (a && a.href) prefetch(a.href);
        }, { passive: true });

        // Trigger top loading bar on internal navigation
        document.addEventListener("click", function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            var a = e.target.closest("a");
            if (!a || !a.href) return;
            if (a.target && a.target !== "_self") return;
            if (a.hasAttribute("download")) return;

            var url = a.getAttribute("href");
            if (!url || url.charAt(0) === "#" || url.startsWith("javascript:")) return;

            try {
                var targetUrl = new URL(a.href, window.location.href);
                if (targetUrl.origin === window.location.origin && targetUrl.pathname !== window.location.pathname) {
                    startProgress();
                }
            } catch (err) {}
        });
    }


    /* GitHub Command Palette (Ctrl+K / ⌘K) ----------------------------- */
    function initCommandPalette() {
        var palette = document.getElementById("gh-command-palette");
        var input   = document.getElementById("gh-cmd-input");
        var results = document.getElementById("gh-cmd-results");
        if (!palette || !input || !results) return;

        var selectedIndex = 0;
        var currentItems = [];

        var COMMANDS = [
            { group: "Navigation", title: "Home Dashboard", icon: "🏠", href: "/" },
            { group: "Navigation", title: "Documentation & Guides", icon: "📖", href: "/docs" },
            { group: "Navigation", title: "Account Settings", icon: "⚙️", href: "/settings/account" },
            { group: "Navigation", title: "Personal Access Tokens", icon: "🔑", href: "/settings/tokens" },
            { group: "Actions", title: "Create New Repository", icon: "➕", href: "/repos/new" },
            { group: "Actions", title: "Import External Repository", icon: "📥", href: "/repos/import" },
            { group: "Quick Action", title: "Toggle Dark / Light Theme", icon: "🌓", action: "toggleTheme" },
            { group: "Help & Support", title: "Security & Protections", icon: "🔒", href: "/security" },
            { group: "Help & Support", title: "Contact Support", icon: "💬", href: "/contact" }
        ];

        function open() {
            palette.hidden = false;
            input.value = "";
            render("");
            input.focus();
        }

        function close() {
            palette.hidden = true;
        }

        function toggleTheme() {
            var btn = document.getElementById("gh-theme-btn");
            if (btn) btn.click();
            else {
                var cur = document.documentElement.getAttribute("data-theme") || "light";
                var next = cur === "dark" ? "light" : "dark";
                document.documentElement.setAttribute("data-theme", next);
                localStorage.setItem("gh_theme", next);
            }
        }

        function render(query) {
            var q = query.toLowerCase().trim();
            var filtered = COMMANDS.filter(function (cmd) {
                return !q || cmd.title.toLowerCase().includes(q) || cmd.group.toLowerCase().includes(q);
            });

            currentItems = filtered;
            selectedIndex = 0;

            if (filtered.length === 0) {
                results.innerHTML = '<div style="padding: 24px; text-align: center; color: var(--gh-fg-muted); font-size: 13px;">No matching commands found. Type to search all repositories...</div>';
                return;
            }

            var html = '';
            var lastGroup = null;

            filtered.forEach(function (cmd, idx) {
                if (cmd.group !== lastGroup) {
                    html += '<div class="gh-cmd-group-title">' + cmd.group + '</div>';
                    lastGroup = cmd.group;
                }
                var selClass = (idx === selectedIndex) ? ' is-selected' : '';
                var hrefAttr = cmd.href ? ' href="' + cmd.href + '"' : '';
                var actAttr  = cmd.action ? ' data-action="' + cmd.action + '"' : '';

                html += '<a class="gh-cmd-item' + selClass + '" data-idx="' + idx + '"' + hrefAttr + actAttr + '>' +
                    '<div class="gh-cmd-item-left">' +
                        '<span class="gh-cmd-item-icon">' + cmd.icon + '</span>' +
                        '<span>' + cmd.title + '</span>' +
                    '</div>' +
                    '<span style="font-size: 11px; color: var(--gh-fg-muted);">Jump to</span>' +
                '</a>';
            });

            results.innerHTML = html;
        }

        function updateSelection() {
            var items = results.querySelectorAll(".gh-cmd-item");
            items.forEach(function (el, idx) {
                if (idx === selectedIndex) {
                    el.classList.add("is-selected");
                    el.scrollIntoView({ block: "nearest" });
                } else {
                    el.classList.remove("is-selected");
                }
            });
        }

        function executeCurrent() {
            if (!currentItems[selectedIndex]) {
                if (input.value.trim()) {
                    window.location.href = "/search?q=" + encodeURIComponent(input.value.trim());
                }
                return;
            }
            var item = currentItems[selectedIndex];
            if (item.action === "toggleTheme") {
                toggleTheme();
                close();
            } else if (item.href) {
                window.location.href = item.href;
            }
        }

        input.addEventListener("input", function () {
            render(input.value);
        });

        input.addEventListener("keydown", function (e) {
            if (e.key === "ArrowDown") {
                e.preventDefault();
                if (currentItems.length > 0) {
                    selectedIndex = (selectedIndex + 1) % currentItems.length;
                    updateSelection();
                }
            } else if (e.key === "ArrowUp") {
                e.preventDefault();
                if (currentItems.length > 0) {
                    selectedIndex = (selectedIndex - 1 + currentItems.length) % currentItems.length;
                    updateSelection();
                }
            } else if (e.key === "Enter") {
                e.preventDefault();
                executeCurrent();
            } else if (e.key === "Escape") {
                e.preventDefault();
                close();
            }
        });

        results.addEventListener("click", function (e) {
            var a = e.target.closest(".gh-cmd-item");
            if (!a) return;
            var act = a.getAttribute("data-action");
            if (act === "toggleTheme") {
                e.preventDefault();
                toggleTheme();
                close();
            }
        });

        palette.addEventListener("click", function (e) {
            if (e.target.hasAttribute("data-cmd-close") || e.target.classList.contains("gh-cmd-palette-backdrop")) {
                close();
            }
        });

        // Global hotkey: Ctrl+K / ⌘K
        document.addEventListener("keydown", function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === "k" || e.key === "K")) {
                e.preventDefault();
                if (palette.hidden) open();
                else close();
            }
        });
    }

    /* Blob Line Permalinks & Selection --------------------------------- */
    function initBlobLineHighlight() {
        function highlightLinesFromHash() {
            var hash = window.location.hash;
            document.querySelectorAll(".gh-line-highlight").forEach(function (el) {
                el.classList.remove("gh-line-highlight");
            });
            if (!hash || !hash.startsWith("#L")) return;

            var match = hash.match(/^#L(\d+)(?:-L(\d+))?$/);
            if (!match) return;

            var start = parseInt(match[1], 10);
            var end   = match[2] ? parseInt(match[2], 10) : start;

            for (var i = Math.min(start, end); i <= Math.max(start, end); i++) {
                var lineElem = document.getElementById("L" + i) || document.querySelector('[data-line="' + i + '"]');
                if (lineElem) {
                    var row = lineElem.closest("tr") || lineElem;
                    row.classList.add("gh-line-highlight");
                    if (i === Math.min(start, end)) {
                        row.scrollIntoView({ behavior: "smooth", block: "center" });
                    }
                }
            }
        }

        window.addEventListener("hashchange", highlightLinesFromHash);
        if (window.location.hash && window.location.hash.startsWith("#L")) {
            setTimeout(highlightLinesFromHash, 150);
        }
    }

    /* Auto-dismiss Toast Timer Indicator ------------------------------ */
    function initToastTimers() {
        document.querySelectorAll(".gh-alert-toast").forEach(function (toast) {
            if (toast.hasAttribute("data-timer-init")) return;
            toast.setAttribute("data-timer-init", "1");

            var timeout = setTimeout(function () {
                toast.style.transition = "opacity 0.3s ease, transform 0.3s ease";
                toast.style.opacity = "0";
                toast.style.transform = "translateY(-6px)";
                setTimeout(function () { toast.remove(); }, 300);
            }, 6500);

            toast.addEventListener("mouseenter", function () { clearTimeout(timeout); });
        });
    }

})();
