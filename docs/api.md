# GitPHP — REST API v1 Documentation ⚡️

> 📚 **Navigation:** [Docs Index](README.md) • [Personal Access Tokens (PAT)](token-push.md) • [Deploy Guide](deploy.md) • [SSH Setup](ssh-setup.md)

---

GitPHP provides a read & automation REST API v1. All endpoints return standard JSON responses and require authentication via a Personal Access Token (`gtp_...`).

Base URL:
```
https://git.ysnapp.com/api/v1
```

---

## 🔑 Authentication

Pass your personal access token in either of two ways:

### 1. Bearer Header (Standard)
```http
Authorization: Bearer gtp_a3f7c2e91b04d85f3c7a2b1e9d06f48c2a5b7e3
```

### 2. Custom Token Header
```http
X-GitPHP-Token: gtp_a3f7c2e91b04d85f3c7a2b1e9d06f48c2a5b7e3
```

> **Scopes:**
> - `read`: Can query all API endpoints and clone repositories.
> - `write`: Can query API, clone, and **push** commits over Git HTTP.

---

## 📡 API Endpoints

### 1. Authenticated User (Whoami)
Returns the identity and permissions of the token owner.

- **Method:** `GET`
- **Endpoint:** `/api/v1/user`
- **cURL Example:**
```bash
curl -H "Authorization: Bearer gtp_your_token" https://git.ysnapp.com/api/v1/user
```
- **Response (200 OK):**
```json
{
  "kind": "owner",
  "username": "admin",
  "scopes": "write",
  "token": "laptop-cli"
}
```

---

### 2. Repository Metadata
Returns details about a single repository.

- **Method:** `GET`
- **Endpoint:** `/api/v1/repos/{slug}`
- **cURL Example:**
```bash
curl -H "Authorization: Bearer gtp_your_token" https://git.ysnapp.com/api/v1/repos/my-project
```
- **Response (200 OK):**
```json
{
  "name": "My Project",
  "slug": "my-project",
  "full_name": "admin/my-project",
  "description": "Production core repository",
  "visibility": "public",
  "default_branch": "main",
  "homepage": "https://ysnapp.com",
  "fork": false,
  "stars": 12,
  "created_at": "2026-08-20 12:00:00",
  "updated_at": "2026-08-25 10:00:00",
  "html_url": "https://git.ysnapp.com/admin/my-project"
}
```

---

### 3. Branches List
Returns all branches in the repository with their latest commit SHA.

- **Method:** `GET`
- **Endpoint:** `/api/v1/repos/{slug}/branches`
- **Response (200 OK):**
```json
{
  "branches": [
    {
      "name": "main",
      "commit": "8f3b207567e411b439c27943d0df6270e5d1645e",
      "is_default": true
    },
    {
      "name": "feature/v2",
      "commit": "c45f8e65839211a7e2b1094389df91823a4b9c1d",
      "is_default": false
    }
  ]
}
```

---

### 4. Tags & Releases List
Returns all Git tags and annotated release tags.

- **Method:** `GET`
- **Endpoint:** `/api/v1/repos/{slug}/tags`
- **Response (200 OK):**
```json
{
  "tags": [
    {
      "name": "v1.0.0",
      "sha": "8f3b207567e411b439c27943d0df6270e5d1645e",
      "message": "Initial production release"
    }
  ]
}
```

---

### 5. Commits History (Paginated)
Lists commit history for a given branch or ref.

- **Method:** `GET`
- **Endpoint:** `/api/v1/repos/{slug}/commits`
- **Query Parameters:**
  - `ref` *(optional)*: Branch name or commit hash (default: repository default branch).
  - `page` *(optional)*: Page number (default: `1`).
  - `per_page` *(optional)*: Commits per page (1–50, default: `20`).
- **cURL Example:**
```bash
curl -H "Authorization: Bearer gtp_your_token" \
  "https://git.ysnapp.com/api/v1/repos/my-project/commits?ref=main&per_page=5"
```
- **Response (200 OK):**
```json
{
  "commits": [
    {
      "hash": "8f3b207567e411b439c27943d0df6270e5d1645e",
      "author_name": "Admin",
      "author_email": "admin@ysnapp.com",
      "date": "2026-08-25 10:20:00",
      "message": "Update security hooks"
    }
  ],
  "page": 1,
  "per_page": 5,
  "has_more": true
}
```

---

### 6. Directory Tree
Browse files and folders at any commit or branch ref.

- **Method:** `GET`
- **Endpoint:** `/api/v1/repos/{slug}/tree`
- **Query Parameters:**
  - `ref` *(optional)*: Branch or commit ref (default: default branch).
  - `path` *(optional)*: Subfolder path (e.g. `src/Service`).
- **Response (200 OK):**
```json
{
  "ref": "main",
  "path": "src",
  "entries": [
    { "name": "App.php", "type": "blob", "size": 3410 },
    { "name": "Service", "type": "tree" }
  ]
}
```

---

### 7. File Content (Blob)
Fetches file contents encoded in Base64 (max 512KB).

- **Method:** `GET`
- **Endpoint:** `/api/v1/repos/{slug}/blob`
- **Query Parameters:**
  - `ref` *(optional)*: Branch or commit ref.
  - `path` *(required)*: File path (e.g. `README.md` or `composer.json`).
- **Response (200 OK):**
```json
{
  "ref": "main",
  "path": "README.md",
  "size_bytes": 1024,
  "truncated": false,
  "encoding": "base64",
  "content_b64": "IyBNeSBQcm9qZWN0CgpBIHNhbXBsZSByZXBvc2l0b3J5Lg=="
}
```

---

### 8. Issues List
List issues and bug reports for a repository.

- **Method:** `GET`
- **Endpoint:** `/api/v1/repos/{slug}/issues`
- **Query Parameters:**
  - `state` *(optional)*: `open`, `closed`, or `all` (default: `open`).

---

### 9. Pull Requests List
List pull requests and their review states.

- **Method:** `GET`
- **Endpoint:** `/api/v1/repos/{slug}/pulls`
- **Query Parameters:**
  - `state` *(optional)*: `open`, `closed`, or `all` (default: `open`).

---

## 🛑 Error Codes

| Status Code | Meaning | Cause |
|---|---|---|
| `401 Unauthorized` | Invalid or missing token | Missing `Authorization` header or token revoked |
| `403 Forbidden` | Insufficient permissions | Private repository without collaborator access |
| `404 Not Found` | Resource not found | Repository or file path does not exist |
| `400 Bad Request` | Invalid query parameter | Path traversal attempt or invalid ref |
