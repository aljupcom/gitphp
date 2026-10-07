/**
 * Release asset uploader — chunked (5 MiB parts), resumable, drag & drop.
 *
 * Wire-up: <div class="release-asset-dropzone" data-repo="..." data-release-id="...">
 * Optional: data-uploaded-ids="1,2,3" — comma-separated asset ids already
 * attached (rendered server-side); the widget keeps them visible.
 */
(function () {
    'use strict';

    var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content
        || (document.querySelector('input[name="csrf_token"]') || {}).value
        || window.GITPHP_CSRF || '';

    function humanBytes(n) {
        if (n >= 1073741824) return (n / 1073741824).toFixed(1) + ' GB';
        if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
        if (n >= 1024) return Math.round(n / 1024) + ' KB';
        return n + ' B';
    }

    function UploadTask(zone, repoBase, releaseId, file, row) {
        this.zone = zone;
        this.repoBase = repoBase;
        this.releaseId = releaseId;
        this.file = file;
        this.row = row;
        this.session = null;
        this.chunkBytes = 5 * 1024 * 1024;
        this.cancelled = false;
    }

    UploadTask.prototype.run = function () {
        var self = this;
        return self.initSession()
            .then(function () { return self.sendAllChunks(); })
            .then(function () { return self.complete(); })
            .then(function (asset) {
                self.row.status.textContent = 'Done — sha256: ' + asset.sha256.slice(0, 16) + '…';
                self.row.status.style.color = 'var(--gh-success-fg, #3fb950)';
                self.row.progress.style.display = 'none';
                self.row.cancel.textContent = 'Remove';
                self.row.cancel.onclick = function () { self.removeAsset(asset.id); };
                return asset;
            });
    };

    UploadTask.prototype.initSession = function () {
        var self = this;
        return fetch(self.repoBase + '/releases/assets/init', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                release_id: self.releaseId,
                filename: self.file.name,
                size: self.file.size
            })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (!data.success) throw new Error(data.error || 'init failed');
            self.session = data.session_id;
            self.chunkBytes = data.chunk_bytes || self.chunkBytes;
        });
    };

    UploadTask.prototype.sendChunk = function (index, blob) {
        var self = this;
        return fetch(self.repoBase + '/releases/assets/chunk?session=' + encodeURIComponent(self.session) +
            '&index=' + index + '&release_id=' + self.releaseId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/octet-stream',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: blob
        }).then(function (r) {
            if (r.status === 401 || r.status === 403) {
                // Session/context mismatch — no point retrying.
                return r.json().then(function (d) { throw new Error(d.error || 'rejected'); });
            }
            return r.json();
        }).then(function (data) {
            if (!data.success) throw new Error(data.error || 'chunk rejected');
            return data;
        });
    };

    UploadTask.prototype.sendAllChunks = function () {
        var self = this;
        var totalChunks = Math.max(1, Math.ceil(self.file.size / self.chunkBytes));

        var sendNext = function (index) {
            if (self.cancelled) throw new Error('cancelled');
            if (index >= totalChunks) return Promise.resolve();

            var start = index * self.chunkBytes;
            var blob = self.file.slice(start, Math.min(start + self.chunkBytes, self.file.size));

            var attempt = function (tries) {
                return self.sendChunk(index, blob).catch(function (err) {
                    if (self.cancelled || tries >= 3) throw err;
                    return new Promise(function (res) { setTimeout(res, 500 * (tries + 1)); })
                        .then(function () { return attempt(tries + 1); });
                });
            };

            return attempt(0).then(function () {
                var pct = Math.round(((index + 1) / totalChunks) * 100);
                self.row.bar.style.width = pct + '%';
                self.row.status.textContent = pct + '% · ' + humanBytes((index + 1) * self.chunkBytes > self.file.size ? self.file.size : (index + 1) * self.chunkBytes);
                return sendNext(index + 1);
            });
        };

        return sendNext(0);
    };

    UploadTask.prototype.complete = function () {
        var self = this;
        return fetch(self.repoBase + '/releases/assets/complete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ session: self.session, release_id: self.releaseId })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (!data.success) throw new Error(data.error || 'complete failed');
            return data;
        });
    };

    UploadTask.prototype.removeAsset = function (assetId) {
        var self = this;
        var body = new URLSearchParams();
        body.append('csrf_token', CSRF);

        fetch(self.repoBase + '/releases/assets/' + assetId + '/delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        }).then(function (r) { return r.json(); })
          .then(function (data) {
              if (data.success) {
                  self.row.root.remove();
              } else {
                  alert(data.error || 'Could not remove asset.');
              }
          })
          .catch(function () { alert('Network error while removing asset.'); });
    };

    function createRow(zone, file) {
        var row = document.createElement('div');
        row.className = 'release-asset-row';
        row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid var(--gh-border-default,#30363d);border-radius:6px;margin-top:6px;background:var(--gh-bg-canvas,#0d1117);';

        var icon = document.createElement('span');
        icon.textContent = '📦';
        row.appendChild(icon);

        var nameWrap = document.createElement('div');
        nameWrap.style.cssText = 'flex:1;min-width:0;';
        var name = document.createElement('div');
        name.textContent = file.name + ' (' + humanBytes(file.size) + ')';
        name.style.cssText = 'font-size:12.5px;font-weight:600;color:var(--gh-fg-default,#e6edf3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
        nameWrap.appendChild(name);

        var track = document.createElement('div');
        track.style.cssText = 'height:4px;border-radius:2px;background:var(--gh-border-default,#30363d);overflow:hidden;margin-top:5px;';
        var bar = document.createElement('div');
        bar.style.cssText = 'height:100%;width:0%;background:#3fb950;transition:width .15s;';
        track.appendChild(bar);
        nameWrap.appendChild(track);
        row.appendChild(nameWrap);

        var status = document.createElement('span');
        status.style.cssText = 'font-size:11.5px;color:var(--gh-fg-muted,#8b949e);white-space:nowrap;';
        status.textContent = '0%';
        row.appendChild(status);

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = 'Cancel';
        cancel.className = 'gh-btn gh-btn-sm';
        row.appendChild(cancel);

        zone.appendChild(row);
        return { root: row, bar: bar, status: status, cancel: cancel };
    }

    function initZone(zone) {
        var repoBase = '/' + zone.dataset.owner + '/' + zone.dataset.repo;
        var releaseId = parseInt(zone.dataset.releaseId || '0', 10);

        if (!releaseId) {
            // No release yet (draft being created) — zone stays hidden until publish.
            zone.style.display = 'none';
            return;
        }

        var hint = zone.querySelector('.release-asset-hint');
        var input = zone.querySelector('input[type="file"]');

        function handleFiles(files) {
            Array.prototype.forEach.call(files, function (file) {
                var row = createRow(zone, file);
                var task = new UploadTask(zone, repoBase, releaseId, file, row);

                row.cancel.onclick = function () {
                    task.cancelled = true;
                    row.root.remove();
                };

                task.run().catch(function (err) {
                    if (task.cancelled) return;
                    row.status.textContent = 'Failed: ' + err.message;
                    row.status.style.color = 'var(--gh-danger-fg,#f85149)';
                    row.bar.style.background = '#f85149';
                });
            });
        }

        if (input) {
            input.addEventListener('change', function () {
                handleFiles(input.files);
                input.value = '';
            });
        }

        ['dragenter', 'dragover'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                zone.style.borderColor = '#58a6ff';
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                zone.style.borderColor = '';
            });
        });
        zone.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files) handleFiles(e.dataTransfer.files);
        });

        if (hint) {
            hint.textContent = 'Drag & drop binaries here, or use the file picker. Uploads are chunked (5 MB) and resumable.';
        }
    }

    function boot() {
        document.querySelectorAll('.release-asset-dropzone').forEach(initZone);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
