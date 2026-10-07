# GitPHP — فهرس التوثيق والأدلة التقنية 📚

مرحباً بك في مركز التوثيق الفني لمنصة **GitPHP**. هنا تجد جميع الأدلة اللازمة لتثبيت وإدارة وتأمين واستخدام المنصة.

---

## 🗺️ خريطة الوثائق

```
docs/
├── README.md            ← الفهرس وخريطة الوثائق (هذا الملف)
├── installation.md      ← دليل التثبيت السريع عبر الويب أو الـ CLI
├── deploy.md            ← دليل النشر المتقدم على CyberPanel / OpenLiteSpeed
├── openlitespeed.conf   ← نموذج إعدادات VirtualHost لـ OpenLiteSpeed
├── ssh-setup.md         ← إعداد وصول SSH والمفاتيح ومستخدم git
├── token-push.md        ← دليل الدفع بالـ Personal Access Tokens و CI/CD
├── api.md               ← دليل واجهة REST API v1 ونقاط النهاية
└── security.md          ← دليل الأمان والصلاحيات والتشفير والتدقيق
```

---

## 📖 الأدلة المتاحة

### 1. [دليل التثبيت السريع (Installation Guide)](installation.md)
- متطلبات الخادم (PHP 8.1+, MariaDB 10.11+, Git).
- التثبيت عبر واجهة الويب (`public_html/install.php`) وسطر الأوامر (`php bin/install.php`).

### 2. [دليل الأمان والصلاحيات وحماية الخادم (Security Guide)](security.md)
- نموذج الصلاحيات والأدوار الهرمي (RBAC: Visitor, Read, Write, Admin).
- تشفير Argon2id، المصادقة الثنائية (2FA)، وتجزئة الرموز الشخصية بـ SHA-256.
- عزل SSH عبر الـ Wrapper، حماية الفروع، وسجل التدقيق الأمني (Audit Logs).

### 3. [دليل النشر على CyberPanel / OpenLiteSpeed (Deploy Guide)](deploy.md)
- إنشاء الموقع وضبط شهادة SSL المجانية.
- ضبط قواعد إعادة التوجيه (Rewrite Rules) وتمرير ترويسة `Authorization`.
- ضبط المعالج الخارجي LSPHP ومهلات الاتصال وأحجام الرفع الكبيرة (`maxReqBodySize`).

### 4. [إعدادات OpenLiteSpeed (vHost Conf)](openlitespeed.conf)
- كود الإعدادات الجاهز للنسخ في CyberPanel vHost Conf وقواعد الأمان.

### 5. [إعداد وصول SSH الموثق (SSH Setup)](ssh-setup.md)
- إنشاء مستخدم النظام المخصص `git` وضبط قيود الأمان (`no-pty`, `nologin`).
- ربط الـ Wrapper الآمن (`bin/git-shell-wrapper.php`) وتشغيل سكريبت الصيانة `bin/fix-push.php`.

### 6. [الدفع بالرموز الشخصية و CI/CD (Token Push Guide)](token-push.md)
- توليد الرموز الشخصية (`gtp_...`) وتحديد الصلاحيات (`read` / `write`).
- الدفع والسحب عبر HTTP باستخدام التوكن بدلاً من كلمة المرور، وتخزين الاعتماديات محلياً أو في CI/CD.

### 7. [دليل واجهة REST API v1 البرمجية (API Documentation)](api.md)
- نقاط النهاية البرمجية (المستخدم، المستودعات، الفروع، الوسوم، الالتزامات، شجرة الملفات، والمشاكل).
- المصادقة عبر الترويسات (`Authorization: Bearer gtp_...` و `X-GitPHP-Token`).
- أمثلة `curl` واستجابات JSON القياسية وأكواد الأخطاء.

---

## 🌐 دليل المستخدم داخل المنصة

يمكن للمستخدمين تصفح دليل الاستخدام التفاعلي ثنائي اللغة (عربي / إنجليزي) مباشرة من الموقع:
- **العربية:** `https://git.ysnapp.com/docs?lang=ar`
- **الإنجليزية:** `https://git.ysnapp.com/docs?lang=en`
