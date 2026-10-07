/**
 * GitPHP — live import progress (admin repo import).
 *
 * Polls /admin/repos/import/progress?id=… and drives the progress card:
 * big percentage, animated bar, phase checklist, live log tail and the
 * final outcome panels. Loaded with asset() cache-busting.
 */
(function () {
    "use strict";

    var card = document.getElementById("imp-card");
    if (!card) return;

    var jobId = card.getAttribute("data-job-id") || "";
    if (!jobId) return;

    var progressUrl = card.getAttribute("data-progress-url") || "/repos/import/progress";
    var runUrl = card.getAttribute("data-run-url") || "/repos/import/run";

    var pct = document.getElementById("imp-pct");
    var bar = document.getElementById("imp-bar");
    var msg = document.getElementById("imp-msg");
    var det = document.getElementById("imp-detail");
    var log = document.getElementById("imp-log");
    var errBox = document.getElementById("imp-error");
    var doneBox = document.getElementById("imp-done");
    var phases = document.querySelectorAll("#imp-phases [data-ph]");

    var ORDER = ["verify", "metadata", "clone", "audit", "extras", "done"];
    var startedAt = Date.now();
    var stopped = false;
    var pollCount = 0;
    var runTriggered = false;

    // Web self-runner: on hosts where the CLI worker cannot spawn, the job
    // stays "queued". Firing this long-lived request executes the pipeline
    // inside the server request (ignore_user_abort keeps it alive even if
    // the browser gives up on the connection). Safe to race with the CLI
    // worker — a flock in the endpoint makes the loser exit immediately.
    function triggerRunner() {
        if (runTriggered) return;
        runTriggered = true;
        fetch(runUrl + "?id=" + encodeURIComponent(jobId), {
            headers: { "X-Requested-With": "fetch" },
            cache: "no-store",
            credentials: "same-origin"
        }).catch(function () { /* the pipeline outlives this connection */ });
    }

    function paintPhases(current) {
        var idx = ORDER.indexOf(current);
        for (var i = 0; i < phases.length; i++) {
            var el = phases[i];
            var pi = ORDER.indexOf(el.getAttribute("data-ph"));
            el.style.color = pi < idx
                ? "var(--gh-success-fg,#3fb950)"
                : (pi === idx ? "var(--gh-accent-fg,#58a6ff)" : "var(--gh-fg-muted)");
        }
    }

    function showError(text) {
        if (!errBox) return;
        errBox.hidden = false;
        errBox.textContent = text;
    }

    function finish(s) {
        stopped = true;

        if (s && s.error) {
            if (pct) {
                pct.textContent = "✗";
                pct.style.background = "none";
                pct.style.webkitTextFillColor = "";
                pct.style.color = "var(--gh-danger-fg,#f85149)";
            }
            if (msg) msg.textContent = "Import failed";
            if (det) det.textContent = "";
            showError(s.error);
            if (doneBox) {
                doneBox.hidden = false;
                var rb = document.getElementById("imp-repo-btn");
                if (rb) rb.style.display = "none";
            }
            return;
        }

        if (pct) pct.textContent = "100%";
        if (bar) bar.style.width = "100%";
        if (msg) msg.textContent = (s && s.message) || "Import complete";
        if (det) det.textContent = (s && s.summary) || "";
        paintPhases("done");
        if (doneBox) {
            doneBox.hidden = false;
            var btn = document.getElementById("imp-repo-btn");
            if (btn && s && s.repo_url) btn.href = s.repo_url;
        }
    }

    function render(s) {
        if (!s || typeof s !== "object") return;

        // Job still sitting in the queue? Kick the web self-runner (once).
        // If the CLI worker already picked it up, the phase has moved on
        // and this becomes a no-op.
        if (!runTriggered && (s.phase === "queued" || s.phase === "starting")) {
            triggerRunner();
        }

        // Worker never picked the job up at all? Surface it instead of spinning.
        pollCount++;
        if ((s.phase === "queued" || s.phase === "starting") && pollCount >= 15) {
            showError(
                "The import has been queued for a while without starting. " +
                "The self-runner request may have been cut off by the host " +
                "(request time limit). Check storage/tmp/import-*.json or retry the import."
            );
        }

        if (s.percent != null && pct) {
            pct.textContent = s.percent + "%";
            pct.style.color = "";
        }
        if (bar) bar.style.width = Math.max(s.percent > 0 ? 2 : 0, s.percent || 0) + "%";
        if (msg) msg.textContent = s.message || "";
        if (det) det.textContent = s.detail || "";
        paintPhases(s.phase || "");

        if (Array.isArray(s.log) && s.log.length && log) {
            var atBottom = log.scrollTop + log.clientHeight >= log.scrollHeight - 30;
            log.textContent = s.log.join("\n");
            if (atBottom) log.scrollTop = log.scrollHeight;
        }
    }

    function poll() {
        if (stopped) return;

        fetch(progressUrl + "?id=" + encodeURIComponent(jobId), {
            headers: { "X-Requested-With": "fetch", "Accept": "application/json" },
            cache: "no-store",
            credentials: "same-origin"
        })
            .then(function (r) {
                // A login redirect or an HTML error page arrives as text —
                // detect it instead of dying inside r.json().
                var ctype = r.headers.get("content-type") || "";
                if (!ctype.includes("application/json")) {
                    throw new Error("non-json response (" + r.status + ")");
                }
                return r.json();
            })
            .then(function (s) {
                if (!s) throw new Error("empty response");
                if (s.ok === false) { finish(s); return; }
                render(s);
                if (s.done) { finish(s); return; }
                setTimeout(poll, 1200);
            })
            .catch(function () {
                if (stopped) return;
                // Visible, not silent: the detail line doubles as a heartbeat.
                if (det && pollCount > 2) det.textContent = "reconnecting…";
                setTimeout(poll, 2500);
            });
    }

    function boot() {
        var initialPhase = card.getAttribute("data-initial-phase") || "queued";
        paintPhases(initialPhase);
        if (initialPhase === "queued" || initialPhase === "starting") {
            triggerRunner();
        }
        poll();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }
})();
