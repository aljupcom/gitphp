# GitPHP — Installation & Deployment Guide

> 📚 **Navigation:** [Docs Index](README.md) • [CyberPanel & OpenLiteSpeed Deployment](deploy.md) • [SSH Setup](ssh-setup.md) • [Personal Access Tokens & Push](token-push.md) • [OLS Config](openlitespeed.conf)

---

GitPHP can be installed two ways:

| Method | Best for | Tool |
|---|---|---|
| **Web installer** | Shared hosting (cPanel, CyberPanel, Plesk…) or any server without SSH | `public_html/install.php` |
| **CLI installer** | VPS / dedicated servers with SSH access | `php bin/install.php` |

Both use the same engine (`src/Setup/Installer.php`) and perform: requirements check → database creation → schema import → migrations → owner account → `.env` generation → installer lock.

---

## 1. Requirements

- PHP **8.1+** with `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `curl`
- MySQL 5.7+ / MariaDB 10.3+
- **Git** binary available to the PHP user
- Composer dependencies present (`vendor/`)

Check quickly:

```bash
php -m | grep -E 'pdo_mysql|mbstring|fileinfo|openssl'
git --version
```

---

## 2. Web installation (shared hosting)

### Step 1 — Upload files

Upload the project so that only `public_html/` contents are inside the web root:

```
/home/USER/
├── src/  config/  templates/  vendor/  database/  bin/  hooks/
└── public_html/          ← document root
    ├── index.php
    ├── install.php       ← delete after install if you wish (it self-locks)
    └── assets/
```

> On CyberPanel/cPanel create the site first, then place project files one level above its `public_html` and copy this repo's `public_html/*` inside it.

### Step 2 — Create database & user

In your hosting control panel create an empty database and a user with full rights on it. Keep the credentials for the next step.

### Step 3 — Run the wizard

Open `https://your-domain.com/install.php`. The wizard checks requirements, then asks for:

1. **Database** — host, port, name, user, password (tables are created automatically)
2. **Site** — name, URL, owner username, timezone
3. **Owner password** — min 8 chars, stored as Argon2id hash

When finished, the installer writes `.env` and creates `storage/installed.lock`, then **locks itself** — visiting `/install.php` again returns *403 Already installed*.

### Step 4 — Permissions

```bash
chmod 640 .env            # contains DB password
chmod -R 755 storage      # writable by PHP user
chmod -R 755 repos        # bare repositories live here
```

---

## 3. CLI installation (VPS)

### Non-interactive (automation / provisioning):

```bash
php bin/install.php \
    --db-host=127.0.0.1 \
    --db-port=3306 \
    --db-name=gitphp \
    --db-user=gitphp \
    --db-pass='strong-db-pass' \
    --app-name=GitPHP \
    --app-url=https://git.example.com \
    --app-owner=admin \
    --timezone=Asia/Riyadh \
    --owner-pass='S3cure-Owner-Pass!' \
    --repos-path=/srv/gitphp/repos
```

### Interactive (plain run):

```bash
php bin/install.php     # prompts for every value
```

Exit code is `0` on success, `1` on failure — safe for scripts.

---

## 4. Web server configuration

### Apache (VPS / shared)

Document root must point at `public_html/`. The bundled `.htaccess` handles routing and passes the `Authorization` header (needed for git push/pull over HTTP).

```apache
<VirtualHost *:443>
    ServerName git.example.com
    DocumentRoot /srv/gitphp/public_html

    <Directory /srv/gitphp/public_html>
        AllowOverride All
        Require all granted
    </Directory>

    SetEnv APP_ENV production
</VirtualHost>
```

Enable modules: `rewrite`, `headers`.

### Nginx + PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    server_name git.example.com;
    root /srv/gitphp/public_html;
    index index.php;

    # Git smart-HTTP clients send Basic auth
    fastcgi_pass_header Authorization;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

### OpenLiteSpeed / CyberPanel

See [`docs/deploy.md`](deploy.md) and [`docs/openlitespeed.conf`](openlitespeed.conf) for the full CyberPanel + OLS walkthrough.

---

## 5. Authentication & Git Pushing

GitPHP supports three secure ways to push code:
1. **Personal Access Tokens (Recommended for CI/CD & IDEs):** See [`docs/token-push.md`](token-push.md).
2. **SSH Public Keys:** See [`docs/ssh-setup.md`](ssh-setup.md).
3. **HTTP Basic Auth with Account Password.**

---

## 6. Post-install checklist

- [ ] `/install.php` returns **403 Already installed** (lock works)
- [ ] `APP_DEBUG=0` in `.env`
- [ ] HTTPS active (session cookies become `Secure` automatically)
- [ ] `storage/`, `repos/` writable by the PHP user only
- [ ] `git` runnable by the PHP user (`sudo -u www-data git --version`)
- [ ] For SSH push support: [`docs/ssh-setup.md`](ssh-setup.md)
- [ ] For Token push support: [`docs/token-push.md`](token-push.md)
- [ ] Schedule `php bin/migrate.php` after pulling updates (idempotent)
- [ ] Back up: MySQL dump + `repos/` + `.env`

---

## 7. Troubleshooting

| Symptom | Fix |
|---|---|
| Redirected to `/install.php` although installed | `.env` missing from project root — restore it |
| Installer says "Project root not writable" | Upload `.env.example` as `.env` manually and chmod 640; refresh |
| 500 after login attempts | Check `storage/logs`; verify Argon2id supported (`php -i | grep argon`) |
| Push over HTTP fails (401) | Ensure `.htaccess` passes `Authorization` header; check [`docs/token-push.md`](token-push.md) |
| Repos not created on save | Check `REPOS_PATH` exists and is writable by PHP user |
| Push via SSH fails | Check `authorized_keys` permissions (`0660`); follow [`docs/ssh-setup.md`](ssh-setup.md) |
