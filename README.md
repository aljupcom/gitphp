# GitPHP

<p align="center">
  <strong>The Ultimate Self-Hosted Git Platform Tailored for Shared Hosting & PHP</strong><br>
  Authentic GitHub-Style Interface · Zero Daemon Dependencies · Low Resource Footprint · Built on Native PHP 8.1+
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1%20--%208.4-777bb4?style=flat-square&logo=php&logoColor=white" alt="PHP Version">
  <img src="https://img.shields.io/badge/Database-MySQL%20%7C%20MariaDB%20%7C%20SQLite-4479a1?style=flat-square&logo=mysql&logoColor=white" alt="Databases">
  <img src="https://img.shields.io/badge/Hosting-cPanel%20%7C%20CyberPanel%20%7C%20VPS%20%7C%20Shared-00b4d8?style=flat-square" alt="Hosting Ready">
  <img src="https://img.shields.io/badge/UI-Authentic%20GitHub-24292e?style=flat-square&logo=github&logoColor=white" alt="GitHub UI">
  <img src="https://img.shields.io/badge/License-MIT-green?style=flat-square" alt="License">
</p>

---

## 🌟 Why GitPHP?

Self-hosting Git repositories has traditionally meant choosing between complex, resource-heavy monolithic solutions:
- **GitLab** demands 4GB–8GB+ RAM, background Ruby/Puma processes, PostgreSQL, and dedicated VPS/Root infrastructure.
- **Gitea / Forgejo / Gogs** require standalone compiled binary daemons, SSH socket management, and root or systemd access that standard shared hosting accounts simply do not permit.

### **Born for Shared Hosting & Pure PHP Environments**
**GitPHP** was born to bridge this exact gap. It provides a complete, modern, GitHub-grade Git collaboration platform that runs natively on standard **PHP 8.1+ shared hosting** (cPanel, CyberPanel, DirectAdmin, Plesk, Apache, LiteSpeed, OpenLiteSpeed, or Nginx).

- ⚡ **Zero Background Daemons:** Runs through standard HTTP/HTTPS web requests and standard PHP execution.
- 🪶 **Ultra-Low Memory Footprint:** Consumes only ~15–30 MB RAM per request instead of gigabytes of persistent background memory.
- 📁 **One-Click Web Setup:** Includes an interactive web installer wizard (`public_html/install.php`) that configures the database, repositories, and owner account in seconds.
- 🔒 **Enterprise-Grade Security:** Hardened with Argon2id password hashing, CSRF verification, rate limiting, and server-side branch protections.

---

## ✨ Features Overview

### 🐙 Git Hosting & Protocol Core
- **Smart HTTP Protocol:** Full `git clone`, `git fetch`, `git push` support over HTTP/HTTPS with HTTP Basic authentication (`https://host/user/repo.git`).
- **SSH Protocol Support:** Optional single-account forced-command wrapper (`bin/git-shell-wrapper.php`) for fast key-authenticated push/pull.
- **Repository Browser:** Interactive file tree with last-commit messages, blob viewer with syntax highlighting, blame view, and zip/tar.gz archives.
- **In-Browser Web Editor:** Create, update, and commit files directly from the web browser.
- **Branches & Tags:** Branch switching, branch deletion, tag creation, release asset management.

### 🔀 Pull Requests & Code Review
- **Pull Request Engine:** Sequential numbering (`#1`, `#2`, …), automated mergeability check, and interactive diff visualizer.
- **Code Review:** Unified & Split diffs, commit histories, discussion comments, approve/reject/request changes workflow.
- **Protected Branches:** Enforce required reviews, prevent force-pushes, and restrict branch deletion directly on the server.

### 📋 Issues & Collaboration
- **Issue Tracker:** Assignees, milestone grouping, labels, status tracking (Open/Closed), Markdown preview.
- **Organization & User Management:** User profiles, avatar uploads, access permissions, owner administrative panel.
- **Webhooks & Integrations:** HMAC-SHA256 signed payloads sent on push, PR, and release events.
- **REST API:** Automated token-authenticated JSON endpoints for CI/CD and external tooling.

### 🧹 Automated Maintenance & Optimization
- **Repository Storage Optimizer:** CLI tool (`bin/repo-optimize.php`) for running `git repack`, `git prune`, and disk optimization without downtime.
- **Smart Cache Cleaner:** Automated pruning CLI (`bin/clean-cache.php`) that maintains storage thresholds.

---

## 🖥️ System Requirements

| Requirement | Minimum | Recommended |
|---|---|---|
| **PHP** | `8.1.0+` | `8.2` or `8.3` |
| **PHP Extensions** | `pdo`, `pdo_mysql` (or `pdo_sqlite`), `mbstring`, `curl`, `fileinfo` | `sodium` / `opcache` |
| **Database** | MySQL `5.7+` / MariaDB `10.3+` or SQLite `3.35+` | MariaDB `10.6+` |
| **Git Binary** | `git 2.20+` available in PATH | `git 2.39+` |
| **Web Server** | Apache (`mod_rewrite`), LiteSpeed, OpenLiteSpeed, or Nginx | Apache / LiteSpeed |

---

## 🚀 Quick Start & Installation

### Option 1: Web Installer Wizard (Recommended)

1. **Upload or Clone** the GitPHP files to your hosting server:
   ```bash
   git clone https://github.com/aljupcom/gitphp.git /path/to/project
   ```
2. **Set your Web Root** to the `public_html` directory in your hosting control panel (cPanel, CyberPanel, Plesk, etc.).
3. **Set permissions** on storage and repository directories:
   ```bash
   chmod -R 775 storage repos public_html/uploads
   ```
4. **Run Composer dependencies** (if deploying clean source):
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
5. **Open your browser** and navigate to:
   ```
   https://your-domain.com/install.php
   ```
6. Follow the 5-step installer wizard:
   - Environment check (PHP version & extensions)
   - Database credentials & live connection test
   - Application URL, site name, and timezone
   - Admin/Owner credentials creation
   - Automated schema setup and lock

---

### Option 2: CLI Installation (Headless / Automated)

Run the CLI installer directly from your terminal or deployment script:

```bash
php bin/install.php \
  --db-host=127.0.0.1 \
  --db-port=3306 \
  --db-name=gitphp \
  --db-user=gitphp_user \
  --db-pass='YourSecurePassword' \
  --app-name="GitPHP" \
  --app-url=https://git.example.com \
  --app-owner=admin \
  --owner-pass='AdminSecurePass123!'
```

---

## 📁 Directory Structure

```
gitphp/
├── config/              # Application, database, and route configurations
│   ├── app.php
│   ├── database.php
│   └── routes.php
├── database/            # SQL schemas and idempotent migrations
│   ├── schema.sql
│   └── migrations/
├── docs/                # Comprehensive documentation guides
│   ├── installation.md
│   ├── deploy.md
│   └── ssh-setup.md
├── hooks/               # Server-side git hooks templates
├── public_html/         # Document Root (Web exposed files only)
│   ├── assets/          # CSS, JS, fonts, and icons
│   ├── uploads/         # User uploaded media
│   ├── .htaccess        # Apache/LiteSpeed rewrite rules & cache headers
│   ├── index.php        # Application Front Controller
│   └── install.php      # Interactive Web Installer Wizard
├── repos/               # Bare Git repository storage (outside web root)
├── src/                 # Application Core Source Code
│   ├── Controller/      # HTTP & API Controllers
│   ├── Middleware/      # Security, CSRF, rate limiters
│   ├── Service/         # Git plumbing, process runners, mailer
│   └── Setup/           # Setup & installer engine
├── storage/             # Runtime sessions, logs, caches, downloads
│   ├── cache/
│   ├── logs/
│   └── sessions/
├── templates/           # Twig templates (Authentic GitHub Theme)
└── bin/                 # Administrative & maintenance CLI tools
    ├── install.php
    ├── migrate.php
    ├── repo-optimize.php
    └── clean-cache.php
```

---

## 🔒 Security Posture

- **No Public Access to Core Code:** Only `public_html/` is exposed to the web; sensitive application classes, database configurations, and bare repository files reside securely outside the web root.
- **SQL Injection Prevention:** 100% prepared PDO statements with parameterized queries.
- **XSS & Content Security:** Strict Twig auto-escaping and sanitized Markdown rendering; raw files and SVG previews served with security headers.
- **CSRF Tokens:** Required on all state-changing POST/PUT/DELETE endpoints.
- **Automatic Installer Lock:** The web installer locks itself permanently upon setup (`storage/installed.lock` and `.env` `APP_INSTALLED=1`), refusing subsequent execution.

---

## 🤝 Contributing

Contributions, bug reports, and pull requests are warmly welcomed!
Feel free to open an issue or submit a pull request to help improve GitPHP.

---

## 📄 License

This project is open-sourced under the [MIT License](LICENSE).

---

## 📖 Live Documentation & Guides

For complete production deployment tutorials, administration guides, API documentation, and step-by-step setup guides:
- 🌐 **Official Documentation & Guides:** [https://git.ysnapp.com/docs](https://git.ysnapp.com/docs)
- 🚀 **Live Instance:** [https://git.ysnapp.com](https://git.ysnapp.com)

---

## 👨‍💻 Author & Maintainer

- **Developer:** Mohammed Gilani ([@aljupcom](https://github.com/aljupcom))
- **GitHub:** [https://github.com/aljupcom](https://github.com/aljupcom)
- **Project Repository:** [https://github.com/aljupcom/gitphp](https://github.com/aljupcom/gitphp)
