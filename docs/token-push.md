# GitPHP — الدفع بالـ Personal Access Token (PAT) 🔑

> 📚 **فهرس الأدلة:** [فهرس التوثيق](README.md) • [دليل التثبيت السريع](installation.md) • [النشر على CyberPanel](deploy.md) • [إعداد SSH](ssh-setup.md) • [نموذج OLS](openlitespeed.conf)

---

الدفع الآمن عبر الرموز الشخصية (Personal Access Tokens) بدلاً من كلمة المرور:

```
https://admin:gtp_xxxxxxxxxxxx@git.ysnapp.com/admin/repo.git
```

---

## كيف يعمل؟

```
git push https://user:gtp_TOKEN@host/owner/repo.git
        │
        ▼
GitHttpController::requireBasicAuth()
        │
        ├─ يكشف البادئة gtp_ في حقل كلمة المرور
        │
        ▼
ApiTokenService::authenticate()
        │
        ├─ يحسب SHA-256 hash للتوكن
        ├─ يبحث عنه في جدول api_tokens
        ├─ يتحقق من عدم إلغائه (revoked_at IS NULL)
        │
        ▼
التحقق من الصلاحية (scopes)
        │
        ├─ scope = write + git-receive-pack  → ✅ مسموح بالدفع
        ├─ scope = read  + git-receive-pack  → ❌ مرفوض (401)
        └─ scope = read  + git-upload-pack   → ✅ مسموح بالاستنساخ
```

التوكن **لا يُخزَّن** في قاعدة البيانات كنص واضح — يُخزَّن hash فقط (SHA-256).
كلمة مرور حسابك الرئيسية تبقى محمية ومخفية.

---

## 1. إنشاء التوكن

1. سجّل الدخول إلى `https://git.ysnapp.com`
2. افتح **Settings → Access Tokens** (أو الرابط المباشر `/settings/tokens`)
3. اختر:
   - **Name**: اسم وصفي مثل `laptop-push` أو `ci-deploy`
   - **Scope**:
     - `read` — للاستنساخ وقراءة API فقط
     - `write` — للاستنساخ **والدفع** والكتابة
4. انقر **Generate Token**
5. **انسخ التوكن فوراً** — يظهر مرة واحدة فقط

صيغة التوكن:
```
gtp_a3f7c2e91b04d85f3c7a2b1e9d06f48c2a5b7e3
```

---

## 2. استخدام التوكن للدفع

### الطريقة الأولى — مباشرة في الـ URL

```bash
# ضبط الـ remote مع التوكن
git remote set-url origin https://admin:gtp_a3f7c2e91b04@git.ysnapp.com/admin/myrepo.git

# دفع عادي
git push origin main
```

---

### الطريقة الثانية — git credential store (الأنسب للاستخدام اليومي)

```bash
# فعّل التخزين المحلي للاعتماديات
git config --global credential.helper store

# أول push سيطلب البيانات ثم يحفظها بأمان
git push https://git.ysnapp.com/admin/myrepo.git
# Username: admin
# Password: gtp_a3f7c2e91b04...  ← ألصق التوكن هنا

# جميع عمليات الـ push التالية تعمل تلقائياً بدون طلب كلمة المرور!
git push origin main
```

---

### الطريقة الثالثة — متغيّرات البيئة (للسيرفرات)

```bash
# في سطر الأوامر أو ملف .bashrc
export GIT_TOKEN=gtp_a3f7c2e91b04...

git push https://admin:${GIT_TOKEN}@git.ysnapp.com/admin/myrepo.git main
```

---

### الطريقة الرابعة — .netrc (أنظمة Linux / macOS)

```bash
# في ~/.netrc
echo "machine git.ysnapp.com login admin password gtp_a3f7c2e91b04..." >> ~/.netrc
chmod 600 ~/.netrc

# يتعرف عليه Git تلقائياً
git push origin main
```

---

## 3. التكامل مع بيئات CI/CD

### GitHub Actions

```yaml
- name: Push to GitPHP
  env:
    GIT_TOKEN: ${{ secrets.GITPHP_TOKEN }}
  run: |
    git remote set-url origin https://admin:${GIT_TOKEN}@git.ysnapp.com/admin/myrepo.git
    git push origin main
```

### GitLab CI

```yaml
deploy:
  script:
    - git remote set-url origin https://admin:$GITPHP_TOKEN@git.ysnapp.com/admin/myrepo.git
    - git push origin main
```

---

## 4. إلغاء التوكن

1. افتح **Settings → Access Tokens**
2. انقر **Revoke** بجانب التوكن المراد إيقافه
3. يتم إيقافه فوراً — أي محاولة push بالتوكن المُلغى ستُرفض بخطأ `401 Unauthorized`.

---

## 5. مقارنة طرق الدفع

| الميزة | كلمة المرور | SSH Key | Personal Token (PAT) |
|--------|:-----------:|:-------:|:--------------------:|
| لا يحتاج إعداد سيرفر | ✅ | ❌ | ✅ |
| لا يكشف كلمة المرور الرئيسية | ❌ | ✅ | ✅ |
| قابل للإلغاء بدون تغيير الحساب | ❌ | ✅ | ✅ |
| تحديد نطاق الصلاحيات (`read`/`write`) | ❌ | ❌ | ✅ |
| يعمل في CI/CD بأمان | ❌ | ✅ | ✅ |
| يعمل عبر HTTPS (المنفذ 443) | ✅ | ❌ | ✅ |

---

## استكشاف الأخطاء

| المشكلة | الحل |
|---------|------|
| `401 Authentication required` | التوكن ملغى أو خاطئ — تحقق من لوحة الـ Tokens |
| `401` مع توكن `read` عند Push | أنشئ توكناً جديداً بصلاحية `write` |
| `Repository not found` | تأكد أن اسم المستودع والمالك صحيحان في الـ URL |
| التوكن مرفوض رغم صحته | تأكد من تضمين البادئة كاملة `gtp_` |
| `403 Forbidden` لمستخدم عادي | يجب أن يملك المستخدم دور `write` كمتعاون في المستودع |
