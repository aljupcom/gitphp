# 🦅 License Management, HWID Binding & Analytics API Documentation

The **License Engine RESTful API Suite (`/api/v1/licenses/...`)** provides administrative endpoints for full License Lifecycle Management, Hardware/Device Fingerprint Binding, IP/HWID Resets, Quotas, and System-Wide Analytics.

---

## 🔐 1. Authentication & Security Headers

All administrative API endpoints require Bearer token authorization:

- **Header Option 1**: `Authorization: Bearer <ADMIN_API_TOKEN>`
- **Header Option 2**: `X-GitPHP-Token: <ADMIN_API_TOKEN>`
- **Content Type**: `Content-Type: application/json`

---

## 📋 2. Endpoints Reference Table

| Method | Endpoint Route | Description |
| :--- | :--- | :--- |
| `POST` | `/api/v1/licenses` | Generate a new server license key |
| `GET` | `/api/v1/licenses` | List and search licenses (Paginated) |
| `GET` | `/api/v1/licenses/stats` | System-wide license & device analytics |
| `GET` | `/api/v1/licenses/{id_or_key}` | Retrieve single license & bound HWID devices |
| `PUT` | `/api/v1/licenses/{id_or_key}` | Update customer info, plan, extend expiration |
| `POST` | `/api/v1/licenses/{id_or_key}/status` | Change status (`active`, `suspended`, `revoked`) |
| `DELETE` | `/api/v1/licenses/{id_or_key}` | Delete license record & device bindings |
| `GET` | `/api/v1/licenses/{id_or_key}/devices` | List bound hardware fingerprints / devices |
| `DELETE` | `/api/v1/licenses/{id_or_key}/devices/{machine_id}` | Unbind / revoke specific device HWID slot |
| `POST` | `/api/v1/licenses/{id_or_key}/reset-bindings` | Reset all HWID bindings & IP for server migration |

---

## 💻 3. Ready-to-Use cURL Examples

### 1. Generate New License Key (`POST /api/v1/licenses`)
```bash
curl -s -X POST https://git.ysnapp.com/api/v1/licenses \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_name": "Test Client",
    "plan_type": "monthly",
    "duration_days": 30,
    "max_devices": 2,
    "bound_ip": "169.58.135.161",
    "notes": "VIP Client"
  }'
```

### 2. List & Search Licenses (`GET /api/v1/licenses`)
```bash
curl -s -H "Authorization: Bearer <TOKEN>" \
  "https://git.ysnapp.com/api/v1/licenses?status=active&search=Test&page=1&per_page=20"
```

### 3. System Analytics (`GET /api/v1/licenses/stats`)
```bash
curl -s -H "Authorization: Bearer <TOKEN>" \
  https://git.ysnapp.com/api/v1/licenses/stats
```

### 4. Single License Details (`GET /api/v1/licenses/{id_or_key}`)
```bash
curl -s -H "Authorization: Bearer <TOKEN>" \
  https://git.ysnapp.com/api/v1/licenses/FF-ED54-0CED-F732
```

### 5. Update / Extend License (`PUT /api/v1/licenses/{id_or_key}`)
```bash
curl -s -X PUT https://git.ysnapp.com/api/v1/licenses/FF-ED54-0CED-F732 \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "customer_name": "Test Client Extended",
    "add_days": 30,
    "max_devices": 3
  }'
```

### 6. Change License Status / Ban (`POST /api/v1/licenses/{id_or_key}/status`)
```bash
curl -s -X POST https://git.ysnapp.com/api/v1/licenses/FF-ED54-0CED-F732/status \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"status": "revoked"}'
```

### 7. Unbind Device HWID (`DELETE /api/v1/licenses/{id_or_key}/devices/{machine_id}`)
```bash
curl -s -X DELETE https://git.ysnapp.com/api/v1/licenses/FF-ED54-0CED-F732/devices/a1b2c3d4e5f67890 \
  -H "Authorization: Bearer <TOKEN>"
```

### 8. Reset Bindings (`POST /api/v1/licenses/{id_or_key}/reset-bindings`)
```bash
curl -s -X POST https://git.ysnapp.com/api/v1/licenses/FF-ED54-0CED-F732/reset-bindings \
  -H "Authorization: Bearer <TOKEN>"
```

---

## 📖 4. Response Models & Errors

### Success Response Format (HTTP 200 / 201)
```json
{
  "success": true,
  "data": {
    "id": 1,
    "license_key": "FF-ED54-0CED-F732",
    "customer_name": "Test Client",
    "plan_type": "monthly",
    "duration_days": 30,
    "max_devices": 2,
    "active_activations": 1,
    "bound_ip": "169.58.135.161",
    "bound_domain": "",
    "status": "active",
    "days_remaining": 30,
    "expires_at": "2026-09-25 02:40:00"
  },
  "message": "Operation executed successfully."
}
```

### Error Response Format
```json
{
  "success": false,
  "error": "ERR_LIC_NOT_FOUND",
  "message": "License not found.",
  "status": 404
}
```

### Error Code Dictionary
- `ERR_UNAUTHORIZED`: Invalid or missing Bearer token authorization header.
- `ERR_LIC_NOT_FOUND`: Target license key or ID does not exist in central database.
- `ERR_LIC_EXPIRED`: License duration has expired (`expires_at < NOW()`).
- `ERR_LIC_REVOKED`: License has been suspended or banned by administrator.
- `ERR_LIC_DEVICE_LIMIT`: Active activations equal `max_devices` quota limit.
- `ERR_LIC_HWID_CLONED`: Machine ID collision or duplicate HWID binding detected.

---

## 📲 Mobile App OTP Pairing & Authentication API

### 1. Generate Temporary Pairing OTP Code (`POST /api/v1/auth/mobile-otp/generate`)
Generates a 6-digit numeric pairing code valid for 10 minutes (600 seconds).

- **Request**: `POST /api/v1/auth/mobile-otp/generate`
- **Response**:
```json
{
  "success": true,
  "otp_code": "491212",
  "expires_in_seconds": 600,
  "expires_at": "2026-08-26 03:00:28",
  "admin_user": "admin",
  "message": "Temporary pairing code generated successfully."
}
```

### 2. Verify Pairing Code & Receive 90-Day Mobile Token (`POST /api/v1/auth/mobile-otp/verify`)
Called by the Android/iOS application with the OTP code to acquire a 90-day API session token (`token`).

- **Request Payload**:
```json
{
  "otp_code": "491212",
  "device_name": "Samsung Galaxy S24 Ultra"
}
```
- **Response (Success - HTTP 200)**:
```json
{
  "success": true,
  "token": "gtp_d4ec78ca2c53189992748a60f19786232a2469ab",
  "expires_in": 7776000,
  "admin_user": "admin",
  "device": "Samsung Galaxy S24 Ultra",
  "message": "Device paired successfully."
}
```
- **Response (Invalid / Expired / Reused Code - HTTP 401)**:
```json
{
  "success": false,
  "message": "Invalid or expired pairing code."
}
```
