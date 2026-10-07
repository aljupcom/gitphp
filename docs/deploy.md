# GitPHP — دليل النشر على CyberPanel + OpenLiteSpeed 🚀

> 📚 **فهرس الأدلة:** [فهرس التوثيق](README.md) • [دليل التثبيت السريع](installation.md) • [إعداد SSH](ssh-setup.md) • [الدفع بالرموز الشخصية PAT](token-push.md) • [نموذج إعدادات OLS](openlitespeed.conf)

---

هذا الدليل يغطي النشر الكامل لمنصة GitPHP على خادم يعمل بـ **CyberPanel + OpenLiteSpeed + MariaDB 10.11 + PHP 8.x (LSAPI)**.

---

## 0. المتطلبات المسبقة

| المكوّن | الإصدار |
|---------|---------|
| CyberPanel | أحدث إصدار مستقر |
| OpenLiteSpeed | المُدار عبر CyberPanel |
| PHP | 8.1+ (LSAPI) مع الامتدادات: `pdo_mysql`, `mbstring`, `openssl`, `sodium` أو `argon2` |
| MariaDB | 10.11+ |
| Git | 2.x (يتضمن `git-http-backend`) — تحقق عبر `git --version` |
| Composer | 2.x |

تحقق من الامتدادات:

```bash
/usr/local/lsws/lsphp83/bin/php -m | grep -E 'pdo_mysql|mbstring|openssl'
```

> استبدل `lsphp83` بإصدار PHP المثبت لديك على الخادم.

---

## 1. إنشاء الموقع في CyberPanel

1. افتح CyberPanel → **Websites** → **Create Website**.
2. اختر الباقة المناسبة وأدخل النطاق، مثلاً: `git.example.com`.
3. فعّل **SSL** (Let's Encrypt يُصدر تلقائياً).
4. فعّل **vHost Conf** لاحقاً عند ضبط OpenLiteSpeed (القسم 5).

بعد الإنشاء ستحصل على الهيكل الأساسي:

```
/home/git.example.com/
└── public_html/          ← Document root
```

---

## 2. إنشاء قاعدة البيانات

1. CyberPanel → **Databases** → **Create Database**.
2. اختر موقع `git.example.com`.
3. اسم القاعدة: `gitphp`، المستخدم: `gitphp`، واختر كلمة مرور قوية.

ستحتاج هذه القيم لاحقاً في ملف `.env`.

---

## 3. رفع ملفات المشروع

ارفع ملفات المشروع بحيث تكون **خارج** مجلد `public_html` ما عدا محتوياته:

```
/home/git.example.com/
├── public_html/              ← محتويات مجلد public_html من المشروع
│   ├── index.php
│   ├── .htaccess
│   └── assets/css/style.css
├── bin/
├── config/
├── database/
├── hooks/
├── src/
├── templates/
├── vendor/                   ← يُنشأ عبر composer install
├── repos/                    ← المستودعات (خارج نطاق الويب تماماً)
├── storage/
├── composer.json
└── .env
```

ثم ثبّت الحزم:

```bash
cd /home/git.example.com
composer install --no-dev --optimize-autoloader
```

---

## 4. الإعدادات والتثبيت

### 4.1 إنشاء ملف `.env`

انسخ `.env.example` إلى `.env` وعدّله:

```ini
APP_NAME=GitPHP
APP_URL=https://git.example.com
APP_OWNER=admin

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=gitphp
DB_USER=gitphp
DB_PASS=كلمة_مرور_قاعدة_البيانات

REPOS_PATH=/home/git.example.com/repos
AUTHORIZED_KEYS_PATH=/home/git/.ssh/authorized_keys
```

> **مهم:** تأكد أن `REPOS_PATH` خارج `public_html` وأن خادم الويب لا يستطيع عرضه مباشرة.

### 4.2 تشغيل معالج التثبيت

```bash
cd /home/git.example.com
/usr/local/lsws/lsphp83/bin/php bin/install.php
```

سيعالج السكربت:
1. التحقق من اتصال MariaDB وإصدارها.
2. إنشاء الجداول وتطبيق `database/schema.sql`.
3. إعداد حساب المالك وحفظ كلمة المرور مشفرة بـ Argon2id.
4. إنشاء مجلد المستودعات والـ hooks.

### 4.3 الصلاحيات

مستخدم PHP في CyberPanel هو المستخدم المرتبط بالموقع (مثلاً `gitys2614`):

```bash
cd /home/git.example.com
chown -R gitys2614:gitys2614 repos/ storage/
chmod 775 repos/ storage/
chmod 640 .env
```

---

## 5. ضبط OpenLiteSpeed

### 5.1 Rewrite Rules وتمرير ترويسة Authorization

ملف `public_html/.htaccess` يمرر ترويسة المصادقة ويحوّل الطلبات إلى `index.php`.

تحقق: CyberPanel → Websites → `git.example.com` → **vHost Conf** وتأكد من:

```
rewrite  {
  enable                  1
  RewriteFile             .htaccess
}
```

> لضمان وصول ترويسة `Authorization` إلى PHP للدفع والسحب، تأكد أن `.htaccess` يحتوي على:
> ```apache
> RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
> ```

### 5.2 External App (PHP LSAPI) — المهلات وحجم الدفع

عمليات `git push` الكبيرة تتطلب مهلة كافية وحجم طلب مناسب. عدّل في vHost Conf قسم External App:

```
extprocessor lsphp {
  connTimeout             300
  reqTimeout              300
  maxReqBodySize          524288000   # 500MB
}
```

راجع ملف [`openlitespeed.conf`](openlitespeed.conf) المرفق لكود الإعداد الكامل.

بعد التعديل: **Restart Litespeed** من CyberPanel.

### 5.3 open_basedir

إذا كان `open_basedir` مفعلاً لموقعك، أضف مسار المشروع وأمر `git`:

```ini
open_basedir = /home/git.example.com:/tmp:/usr/bin
```

---

## 6. طرق الدفع المعتمدة على الخادم

| الطريقة | الوثيقة المرتبطة | المزايا |
|---------|------------------|---------|
| **الرموز الشخصية (Personal Tokens)** | [`token-push.md`](token-push.md) | موصى بها للغاية — آمنة لـ CI/CD وقابلة للإلغاء الفوري |
| **مفاتيح SSH العامة** | [`ssh-setup.md`](ssh-setup.md) | وصول سريع عبر المنفذ 22 مع wrapper أمني |
| **HTTP Basic Auth** | هذا الدليل | كلمة مرور الحساب العادية |

---

## 7. سكريبت الصيانة التلقائية `bin/fix-push.php`

في حال واجهت أي مشكلة صلاحيات على المستودعات أو ملف `authorized_keys`، وفّرنا سكريبت فحص وصيانة شامل:

```bash
php /home/git.example.com/bin/fix-push.php
```

يقوم السكريبت تلقائياً بـ:
1. ضبط أذونات `authorized_keys` إلى `0660`.
2. إعادة توليد المفاتيح من قاعدة البيانات.
3. تفعيل `http.receivepack=true` على كافة المستودعات.
4. إعادة تثبيت الـ Hooks واستبدال مسار `BASE_PATH`.
5. تصحيح أذونات مجلدات `repos/`.

---

## 8. استكشاف الأخطاء الشائعة

| المشكلة | السبب المحتمل | الحل |
|---------|---------------|------|
| 404 لكل الصفحات | Rewrite غير مفعّل | فعّل `Enable Rewrite` وأعد تشغيل OLS |
| 500 عند فتح الموقع | `.env` مفقود أو خطأ DB | راجع السجل وتأكد من القيم |
| الاستنساخ يعمل لكن الدفع يرفض 401 | ترويسة Auth محجوبة | تأكد من تمرير `Authorization` في `.htaccess` أو استخدم [Token Push](token-push.md) |
| `git http-backend` لا يوجد | Git قديم أو مسار خاطئ | `which git` وحدّث Git إن لزم |
| timeout أثناء push كبير | `reqTimeout` قصير | ارفعه إلى 300 كما في القسم 5.2 |
| رفض الكتابة على `repos/` | صلاحيات | شغّل `php bin/fix-push.php` |
