# خطة عمل تطوير GitPHP — سبتمبر 2026

> **نطاق الخطة:** مبنية على تحليل شامل للكود (Releases/Tags + كامل النظام) بتاريخ 2026-09-10.
> **المرجعية المقارنة:** GitHub / Gitea / Codeberg.
> **قواعد الإلزام:** AGENTS.md — مراجعة كل ملف بعد تعديله، عدم حذف وظائف قائمة بدون طلب صريح، فحص سينتكس فوري لكل تعديل، تقرير موجز بعد كل خطوة.

---

## الملخص التنفيذي

| المحور | الحالة الحالية | الهدف بعد الخطة |
|---|---|---|
| الأمان | 6 ثغرات (نقطتان عاجلتان بدون مصادقة) | صفر ثغرات معروفة |
| الأخطاء الوظيفية | 15+ خطأ مؤكد (منها 404-links وbroken-tabs) | صفر أخطاء معروفة |
| التحميل/الرفع | رفع POST واحد بلا تجزيء ولا استئناف | بنية Chunked/Resumable + Assets للإصدارات |
| Releases/Tags | 55% مكتمل | مكافئ Gitea (95%) |
| الأداء | لا فهرسة جزئية، لا ETag، N+1 | فهارس + 304 Not Modified + معالجة ذاكرة |
| النظافة | 7 ملفات .bak + سمة ميتة + 138MB backup | مستودع نظيف قابل للصيانة |

**المدة الإجمالية:** 12 أسبوعاً (قابلة للضغط إلى 9 أسابيع بتنفيذ متوازٍ للمراحل 4-5).

---

# المرحلة 0 — إصلاحات أمنية عاجلة (الأسبوع 1) — P0-Critical

> كل بند هنا قابل للاستغلال الآن من زائر غير مُصادق. لا يُنفَّذ أي شيء آخر قبل إغلاق هذه الثغرات.

## 0.1 إغلاق نقطة مسح الكاش بدون مصادقة
- **المشكلة:** `POST /api/v1/maintenance/clear-cache` → `SystemAdminController::purgeCache` (السطر 369-479) بدون أي فحص مصادقة، مربوطة بزر FAB ظاهر للزوار في `layout.twig:316-411`. تسبب Cache Stampede (DoS) + FLUSHDB على Redis ومسح بيانات تطبيقات أخرى إن كان مشتركاً.
- **التنفيذ:**
  1. إضافة `Auth::isOwner()` + CSRF في بداية `purgeCache`.
  2. إزالة زر FAB من الواجهة للزوار (يظهر للمالك فقط)، أو نقله لصفحة الأدمن.
  3. إضافة rate-limit خاص بالنقطة.
- **التحقق:** طلب POST بدون جلسة مالك يُرجع 403؛ لا تُنفَّذ أي عملية flush.

## 0.2 إزالة ملفات الترقيع القابلة للتنفيذ عن بعد
- **المشكلة:** في `public_html/`:
  - `update_tokens.php` — يكتب `../templates/github/account/tokens.twig` (كتابة ملف بلا مصادقة).
  - `update_md.php` — يكتب فوق `src/Service/MarkdownRenderer.php`.
  - `preflight.php` — يعلن بنفسه "DELETE THIS FILE" ولا يزال موجوداً.
- **التنفيذ:** نقل المحتوى الوظيفي لـ `bin/` (CLI فقط) ثم حذف الملفات الثلاثة من public_html، وإضافة `RewriteRule` في `.htaccess` يحجب أي `update_*.php` مستقبلاً.
- **التحقق:** `curl` على المسارات الثلاثة = 404.

## 0.3 سد ثغرة XSS في الصور الرمزية SVG
- **المشكلة:** `AccountController.php:1183` يسمح بـ `image/svg+xml` ويُخزَّن في `public_html/uploads/avatars/` ويُقدَّم من أصل التطبيق — SVG حامل لسكربت ينفَّذ في كل صفحة تعرضه.
- **التنفيذ (خيار واحد):**
  - **أ (الموصى به):** إزالة `image/svg+xml` من قائمة MIME المسموحة، وقبول png/jpeg/webp/gif فقط.
  - **ب:** تعقيم SVG بمكتبة قبل التخزين + تقديمه بـ `Content-Security-Policy` منعزل — أعلى تكلفة وأقل ضماناً.
- **التحقق:** رفع avatar.svg بوسم `<script>` يُرفض برسالة واضحة.

## 0.4 إصلاح تسريب كاش البحث بين المستخدمين
- **المشكلة:** مفتاح كاش بحث الكود/الـ Commits = `md5(query:owner:isLoggedIn)` بلا `user_id` (`SearchController.php:211,223`) — نتائج محسوبة برؤية مستخدم تُقدَّم لآخر لمدة 120 ثانية → تسريب كود مستودعات خاصة.
- **التنفيذ:** إضافة `Auth::userId()` للمفتاح (أو تعطيل المُلخصة عند تسجيل دخول أي مستخدم).
- **التحقق:** بحثان متتاليان بحسابين مختلفين لا يتشاركان النتائج.

## 0.5 سد فجوة صلاحيات API الوسوم
- **المشكلة:** `ApiController::createTag/deleteTag` (1003-1043) يفحصان scope التوكن فقط دون فحص المشارَكين — أي توكن write يحذف وسوم مستودعات خاصة لغير حامله.
- **التنفيذ:** تطبيق نفس منطق `GitHttpController::requireBasicAuth` (فحص المالك/المشارك write) عبر دالة موحدة `assertRepoWriteAccess($repo)` قبل أي كتابة API (يشمل `createBranch/deleteBranch` 940-976).
- **التحقق:** توكن write لمستخدم عادي على مستودع خاص لغيره = 403.

## 0.6 إغلاق وضع التصحيح الإنتاجي
- `.env`: `APP_DEBUG=1` → `APP_DEBUG=0` (يكشف تتبعات الأخطاء للزوار).
- **التحقق:** خطأ مُفتعل يعرض صفحة الإنتاج النظيفة لا تتبع كامل.

---

# المرحلة 1 — إصلاح الأخطاء الوظيفية الحالية (الأسبوعان 1-2) — P0

## 1.1 استعلام جدول `issues` غير الموجود (11 موضعاً)
- **المشكلة:** `getNavCounts()` في 11 Controller يستعلم `SELECT COUNT(*) FROM issues` والجدول غير موجود (الموجود `bug_reports`) — عدّادات التنقل الإدارية فارغة دائماً والاستثناء مُبتلَع.
- **التنفيذ:** إنشاء **Helper واحد** (مثلاً `src/Service/NavCounts.php`) يستعلم `bug_reports`، واستبدال النسخ الـ11 المنسوخة به (يلغي التكرار ويعالج الأصل). الملفات: `IssuesController:434` + 10 نسخ (AdminSettings, DownloadCenter, SystemAdmin, UserAdmin, RepoManage, WebhookAdmin, DeviceManage, LanguageAdmin, RepoImport…).
- **التحقق:** عدادات قائمة الأدمن تعرض أرقاماً صحيحة.

## 1.2 تبويبات البحث فارغة (users/issues/pulls)
- `SearchController.php:155-207` — كتل الجلب تنفَّذ فقط عند `type=repositories|all`. إصلاح الشروط لكل نوع. **التحقق:** تبويب Users يعرض نتائج.

## 1.3 روابط نتائج البحث 404
- `search.twig:50,70,73` — روابط `/{{slug}}/blob/...` تنقصها مقطع `{user}`. إصلاح القوالب لتوليد `/{owner}/{repo}/blob|commit/...`. **التحقق:** نتيجة كود تفتح الملف.

## 1.4 روابط tar.gz مكسورة (404)
- `releases.twig:147` + `tags.twig:80` يربطان `/archive/{ref}.tar.gz` والمسار مسجّل لـ zip فقط (`routes.php:376`). إضافة مسار `archive/{ref}.tar.gz` → `RepoController@archive` مع باراميتر صيغة، وتمريرها لـ `GitService::archiveStream`.
- **التحقق:** زر tar.gz في صفحتي Releases وTags يحمّل ملفاً صالحاً.

## 1.5 توجيه فشل دمج PR خاطئ
- `PullRequestController.php:480` → `/pulls/{n}` والمسار `/pull/{n}`. إصلاح + إضافة flash رسالة خطأ واضحة.

## 1.6 تسريب المسودات + شارة Latest
- `RepoController.php:163-166` (الشريط الجانبي): استبعاد `is_draft=1` من استعلام "أحدث إصدار".
- `ReleaseController.php:66-75`: استثناء `is_prerelease` من حساب Latest (منطق GitHub: أول إصدار كامل الاستقرار).
- **التحقق:** مستودع بمسودة/لإصدار مسبق لا يعرضها كـ Latest للزوار.

## 1.7 ويدجت الرفع السريع لا تعرض النتيجة
- `quick-upload-widget.twig:66` يعمل `reload()` دائماً فلا تُعبَّأ بطاقة "Link Ready". ربط الاستجابة بملء الحقول بدل إعادة التحميل.

## 1.8 إشعارات user_id=0 (تقارير المالك)
- `IssuesController.php:317,407` — `!empty($issue['user_id'])` يعامل 0 كفارغ. استبدال بـ `isset`/فحص صريح `!== null && $issue['user_id'] > 0` حسب الدلالة المطلوبة.

## 1.9 عدم تناسق Tag↔Release
- API `deleteTag`: حذف صفوف `repo_releases` المرتبطة (أو منع حذف وسم له إصدار مع رسالة واضحة).
- حذف الإصدار من الويب: عرض خيار "حذف الوسم أيضاً" (checkbox) بدل تركه دائماً.
- **التحقق:** لا تبقى صفوف معلقة بعد أي مسار حذف.

## 1.10 أخطاء التشغيل من السجلات (storage/logs)
- **697× `posix_spawn() failed`** (سبتمبر 5 تحت الحمل على تصفح `/admin/vscode/*`): التحقيق في حدود pids/tmp — إضافة retry بسيط في `GitReader::runGit` عند فشل التوليد + مراقبة. تحديد `ulimit`/`RLIMIT_NPROC` إن كان الاستضافة تسمح.
- **نفاد الذاكرة 128MB** على صفحات commit لمستودعات كبيرة: رفع `memory_limit` (php.ini/bin/php.ini) إلى 256M لصفحات diff/commit الكبيرة + تقليل حجم diff المعروض (قطع بعد 3MB مع إشعار "diff too large" كما في GitHub).
- **`http_response_code() after header()`** في `ErrorHandler:156`: تتبع مواضع الإخراج المبكر وإزالتها.
- **"Path contains disallowed characters" يُسجَّل كـ ERROR:** تخفيض تصنيفه إلى Warning + إرجاع 400/404 فوراً بدلاً من استثناء كامل.
- **لا سجلات بعد 2026-09-07 23:22:** التحقق من أن `ErrorHandler::register()` يعمل وأن السجلات تُكتب فعلاً (فحص كتابة سطر اختباري).

## 1.11 صفحة Tags ناقصة السياق
- `RepoController.php:1254-1258`: تمرير `can_write` + `releases_count` ليعمل زر "Draft release" وعدّاد التبويب.

## 1.12 حماية تعليقات PR والمشكلات
- إضافة guard الـ read-only/bot إلى `comment()` في `IssuesController:269` و `PullRequestController` + سقف طول نص تعليق PR (20k كالمشكلات).

---

# المرحلة 2 — استراتيجية الرفع/التحميل الشاملة (الأسابيع 3-5) — P1

> الهدف: بنية تحميل حديثة بمستوى Gitea: تجزيء، استئناف، عدّادات صحيحة، بصمات، وربطها بنظام الإصدارات.

## 2.1 نظام مرفقات الإصدارات (Release Assets) — الأساس

### قاعدة البيانات (migration جديدة)
```sql
CREATE TABLE release_assets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  release_id INT UNSIGNED NOT NULL,
  repo_id INT UNSIGNED NOT NULL,
  uploader_id INT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  storage_path VARCHAR(512) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  mime VARCHAR(120) NOT NULL,
  download_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (release_id) REFERENCES repo_releases(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_release_name (release_id, name),
  KEY idx_repo (repo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### واجهة الرفع (الويب)
1. نموذج الإصدار (`release-new.twig`) + صفحة الإصدار: **منطقة Drag-and-Drop** (JS جديد `assets/js/release-upload.js`) مع:
   - تجزيء 5MB لكل قطعة (chunked upload) إلى نقطة `POST /{user}/{repo}/releases/assets/upload?upload_id=...&index=N`.
   - شريط تقدم لكل ملف، إعادة محاولة تلقائية للقطعة الفاشلة، إلغاء.
   - فحص sha256 وحجم الملف على العميل قبل البدء.
   - ربط كل الرفع بـ `upload session` مؤقتة في `storage/tmp/uploads/{uuid}/` تُدمج عند الإتمام إلى `storage/downloads/releases/{repo_id}/{release_id}/`.
2. **تسجيل فوري في `release_assets`** بعد الدمج مع `sha256` محسوب على الخادم.
3. تفعيل الإعداد الموجود `max_release_asset_mb` (اليوم معطّل بلا مستهلك) كحد أقصى فعلي.
4. تحديث قالب `releases.twig`: عدد Assets ديناميكي (إزالة التصلب "2")، جدول مرفقات بكل ملف: حجم، sha256 (نص قابل للنسخ)، عدّاد تحميلات، زر حذف (للكتّاب).

### نقاط التحميل
- `GET /{user}/{repo}/releases/download/{asset_id}` — بث مباشر بـ:
  - `ETag` (sha256) + دعم `Range` (استئناف التنزيل) + 304.
  - **العدّ بعد اكتمال الإرسال** وليس قبله (إصلاح نمط `publicServeFile` الحالي الذي يحرق خانة عند الإلغاء).
  - `X-Content-Type-Options: nosniff` + `Content-Disposition: attachment`.
- واجهة API مطابقة (المرحلة 3.6).

### الأمان
- التحقق من صلاحية الكاتب على الرفع والحذف؛ CSRF على عمليات الويب.
- تحقق `PathValidator` على اسم الملف (رفض `..` وNUL وامتدادات التنفيذ `.php*`).
- فحص MIME بـ `finfo` + قائمة سماح (لا SVG إلا عبر تعقيم المرحلة 0.3).
- Rate-limit لكل رفع + سقف ملفات متزامنة للجلسة.

## 2.2 ترقية مركز التحميل (Download Center)
1. **رفع مجزّأ قابل للاستئناف** لنقطة `/admin/downloads/upload` (نفس محرك القطع في 2.1) — إلغاء POST الواحد الحالي المحدود بـ `max_upload_mb=100`.
2. **دعم Range على `/d/{code}/get`**: بث بـ `fseek` + `Content-Range` بدلاً من `readfile` الكامل.
3. **عدّاد تحميلات صادق**: احتساب التحميل عند اكتمال البث أو عبر عدّاد Heartbeat JS.
4. **إدارة**: رفع متعدد الملفات دفعة واحدة، إعادة تسمية، نقل مجلد، حصص تخزين لكل مجلد.
5. **استبدال أذونات 0777/0666** (`DownloadCenterController:201,211` و`AccountController:1199,1203`) بأذونات 0755/0644 + رسالة خطأ قابلة للإجراء عند رفض الكتابة (التوثيق في docs/deploy.md).

## 2.3 تنزيلات المستودعات (الأرشيفات)
1. أرشفة **tar.gz** عبر مسار الويب (إصلاح 1.4) + `zip`.
2. `Cache-Control: private, max-age=300` على الأرشيفات + توليد عند الطلب مع كاش ملفات في `storage/tmp` (LRU بحجم محدد) — يمنع إعادة بناء الأرشيف لكل زائر.
3. إضافة بصمة sha256 للأرشيف المولَّد في ترويسة `X-Checksum-Sha256`.

## 2.4 التوثيق
- تحديث `docs/api.md` + `templates/github/docs/content-en|ar.twig` لكل النقاط الجديدة (Assets API، Range، الصدّات).

---

# المرحلة 3 — إكمال ميزة الإصدارات والوسوم (الأسبوعان 5-6) — P1

| # | المهمة | التفاصيل |
|---|---|---|
| 3.1 | **تعديل الإصدار** | مسارا `GET/POST /{user}/{repo}/releases/{id}/edit` + قالب `release-edit.twig` (نسخة من النموذج) + استخدام مفتاح `edit_release` المترجم (اليوم غير مستخدم). تحديث `name/body/is_draft/is_prerelease` + إعادة رندرة Markdown |
| 3.2 | **نشر المسودة** | زر "Publish" في صفحة الإصدارات للمسودات: `POST /releases/{id}/publish` (is_draft=0, published_at=NOW()) |
| 3.3 | **صفحة إصدار منفردة** | `GET /releases/tag/{tag}` قالب `release-show.twig`: الملاحظات كاملة، المرفقات (2.1)، روابط المصدر، زر تعديل. الروابط الحالية من العناوين تتحول إليها بدل `/tree/{tag}` |
| 3.4 | **واجهة إدارة الوسوم** | صفحة Tags: أزرار "Create release from tag" (تربط `?tag=` — الخلفية موجودة ولا مستهلك) + "Delete tag" (للكتّاب) مع تأكيد وحذف متتالٍ للإصدار المرتبط |
| 3.5 | **حماية الوسوم** | `hooks/pre-receive`: توسيع الفحص ليشمل `refs/tags/*` — منع حذف/إعادة كتابة الوسوم المحمية؛ جدول `tag_protections` أو نمط wildcard في `branch_protections` (عمود `ref_pattern`) + واجهة إعدادات |
| 3.6 | **API للإصدارات** | `GET/POST/DELETE /api/v1/repos/{owner}/{repo}/releases` + `releases/{id}/assets` (رفع/تنزيل/حذف) + `PATCH` للتعديل — مع فحص الصلاحيات الموحد (0.5) |
| 3.7 | **إطلاق Webhook حدث release** | `WebhookService` يعلن `release` ولا يطلقه أحد: إضافة `dispatch(..., 'release', ...)` عند النشر (وليس المسودة) + الحذف، مع تصحيح الوثائق |
| 3.8 | **تغذية إصدارات RSS/Atom** | `GET /{user}/{repo}/releases.rss` (نفس نمط `RepoController@rss` مع فحص خصوصية المستودع) |
| 3.9 | **إبطال الكاش** | مسح `repo:{slug}:tags` و `:tags_detailed` بعد أي إنشاء/حذف وسم (نفس نمط `BranchController:302,344`) |
| 3.10 | **إصلاح فتح Tag من سلسلة Form** | `store()`: إنشاء الوسم داخل try + حذف تراجعي (rollback) للوسم عند فشل إدخال DB (إلغاء اللا-معاملاتية الحالية) |
| 3.11 | **قرار السمة القديمة** | قوالب releases غير موجودة في `templates/repo/` (تتكسر عند APP_THEME=legacy): **إما حذف السمة القديمة كلياً (موصى به — المرحلة 6) أو إضافة قوالب مطابقة**. الخطة تحذفها |
| 3.12 | **اختيار SHA/وسم كهدف** | نموذج الإصدار: قائمة الهدف تشمل الفروع + وسوماً + إدخال SHA حر (تحقق `assertSafeRef`) |

---

# المرحلة 4 — ميزات تجربة المستخدم الجديدة (الأسابيع 7-9) — P2

## 4.1 المشكلات (Issues) — تقريب Gitea
1. **Labels**: جدول `issue_labels` + `issue_label_map` + UI تصفية وقوائم ملونة.
2. **Milestones**: جدول `issue_milestones` (title, due_date, closed) + شريط تقدم + تصفية.
3. **Assignees**: عمود `assigned_to` + محدد مستخدمين في النموذج.
4. **بحث وفرز وتصفية**: `?q=&label=&assignee=&milestone=&sort=` + ترقيم صفحات (LIMIT/OFFSET بسيط كافٍ للحجم الحالي).
5. **قوالب المشكلات**: دعم `.gitphp/ISSUE_TEMPLATE.md` أو جلب أول ملف من مجلد معروف في المستودع لملء النموذج مسبقاً.
6. إصلاح guard التناسق (read-only/bot) وإشعارات user_id=0 (منتهية في 1.8/1.12).

## 4.2 طلبات الدمج (Pull Requests)
1. **استراتيجيات دمج**: `squash` (merge --squash يدوي عبر soft-reset) و`rebase` و`fast-forward` بجانب merge-commit الحالي — اختيار لكل مستودع في الإعدادات + اختيار عند الدمج.
2. **مراجعون متعددون**: جدول `pr_reviews` (pr_id, user_id, decision, submitted_at) بدل الأعمدة الأحادية الحالية + إلغاء الموافقات القديمة عند دفع جديد (dismiss stale).
3. **PR مسودة** (Draft PR): عمود `is_draft` + نشر لاحق.
4. **ربط المشكلات**: صيغ `Fixes #12 / Closes #12` في نص PR تُغلق المشكلة بعد الدمج + إشعار.
5. **مراجعات مطلوبة**: عمود `requested_reviewers` + إشعار طلب مراجعة.
6. ترقيم صفحات قائمة PRs + بحث/فرز.
7. إصلاح anchor تصادم التعليقات القديمة/الجديدة (مفتاح md5 بمعرف side).

## 4.3 الويكي
1. **سجل المراجعات**: جدول `wiki_revisions` (page_id, content, edited_by, created_at) — كل تحفظ نسخة، UI تاريخ + diff + استرجاع (نفس محرك DiffParser الحالي).
2. **صلاحيات**: السماح للمشاركين write بالتحرير (الآن requireOwner يرفضهم وواجهة تُظهر لهم أزراراً ميتة) — قرار تصميمي: متابعة سلوك Gitea (write collabs يكتبون الويكي).
3. **إعادة تسمية/نقل صفحة** (تغيير slug مع تحديث الروابط الداخلية) + تعبئة `updated_by` (اليوم دائماً NULL).
4. **مرفقات الويكي**: رفع صور عبر نفس محرك 2.1 إلى مسار `storage/downloads/wiki/{repo}/` وإدراجها بصيغة `![](wiki-asset:{id})`.

## 4.4 البحث
1. **نطاق داخل المستودع**: صفحة بحث على مستوى repo (`/{user}/{repo}/search?q=`) — كود + مشكلات + PRs + ويكي.
2. ترقيم نتائج + تمييز المطابقات (highlight).
3. إصلاح suggest: تطبيق فلتر الرؤية في SQL قبل LIMIT (اليوم مستودعات خاصة قد تلتهم الخانات قبل فلترة PHP).

## 4.5 الإشعارات
1. **تفضيلات لكل نوع** في واجهة المستخدم (الجدول `user_notification_prefs` موجود) + مفاتيح تفعيل البريد لـ push/release/issue/pr.
2. **معالج البريد الصادر**: الجدول `email_outbox` موجود بلا مستهلك — تنفيذ worker عبر cron (`bin/mail-worker.php` يعالج N رسالة كل دقيقة مع retry/backoff).
3. **قفل/تمييز قراءة لكل إشعار** + تصفية بالنوع.

## 4.6 واجهات عامة
1. **ترجمة النصوص المُصلَّبة**: `notifications.twig` كامل، نماذج المشكلات (`issue-new/show`)، `search.twig`، ويدجت الرفع — تحويلها لمفاتيح `t()` en/ar.
2. **إزالة العربية المُصلَّبة** من FAB الصيانة في `layout.twig:316-411` (مخالفة لقاعدة "نصوص إنجليزية مختصرة للعناوين") واستبدالها بمفاتيح i18n.
3. **تنقل المستودع على الجوال**: تحويل `repo-nav` الشريطي الأفقي (wap.css:1330-1334) إلى قائمة "..." منسدلة على الشاشات الصغيرة (مطابقة GitHub — قاعدة AGENTS.md 9).
4. زر **تخطَّ إلى المحتوى** (skip-to-content) لتحسين الوصولية.
5. **اختصارات لوحة مفاتيح جديدة**: `g r` للإصدارات، `g t` للوسوم، `c` لإنشاء مشكلة (نمط GitHub).

---

# المرحلة 5 — كفاءة الأداء (الأسابيع 10-11) — P2

## 5.1 فهارس قاعدة البيانات (migration واحدة)
```sql
ALTER TABLE repositories   ADD INDEX idx_updated (updated_at);
ALTER TABLE bug_reports    ADD INDEX idx_repo_status_created (repo_id, status, created_at),
                           ADD INDEX idx_updated (updated_at);
ALTER TABLE pull_requests  ADD INDEX idx_repo_status_updated (repo_id, status, updated_at);
ALTER TABLE mobile_otps    ADD INDEX idx_otp (otp_code);
```
+ ترحيل تعريفات الجداول غير المتتبعة (`server_licenses`, `license_devices`, `mobile_otps`, `user_follows`) إلى `database/migrations/` (اليوم تُنشأ خارج نظام الترحيل — يخالف تكراراً idempotent).
- التحقق: `EXPLAIN` لاستعلامات الصفحة الرئيسية وقوائم المشكلات/PRs بلا filesort.

## 5.2 تخزين مؤقت HTTP شرطي
1. `raw`: إضافة `ETag` (md5 للمحتوى) + `Last-Modified` (تاريخ آخر Commit للملف) + معالجة `If-None-Match/If-Modified-Since` → 304.
2. `archive`: `Cache-Control` + كاش LRU على ملفات الأرشيف المولدة (2.3).
3. توحيد سياسة `API raw` مع الويب (الفرض text/plain على html/svg — اليوم API يقدمها كـ text/html).

## 5.3 محرك الكاش
1. إصلاح `Cache::forgetPrefix()` ليعمل على redis/memcached (اليوم يمسح ملفات فقط — الإبطالات تختفي بصمت على المحركات الأخرى).
2. منع السلوك الهجين (get يقرأ redis ثم file، set يكتب redis فقط) — توحيد مصدر واحد لكل مفتاح.

## 5.4 استعلامات
1. `SearchController::runCodeSearch:255-258` — إلغاء إعادة الجلب لكل مستودع داخل الحلقة (N+1) باستخدام بيانات الاستعلام الأولي.
2. تخزين نتائج الاستعلامات الفرعية الثلاث في `resolveRepo` (1619-1628) بكاش 60s لكل مستودع.
3. رفع `memory_limit` + قص diff ضخم (1.10).

## 5.5 السجلات والدوران
1. تدوير `storage/logs`: حذف يومي بعد 30 يوماً + سقف 50MB (توسيع `CacheCleaner` الموجود — نفس نمط LRU/watermark).
2. تخفيض ضوضاء "path disallowed" إلى warning (منتهٍ في 1.10).
3. التحقق من استمرار الكتابة (لا سجلات بعد 07-09).

---

# المرحلة 6 — النظافة والصيانة (الأسبوع 12) — P3

| # | المهمة |
|---|---|
| 6.1 | حذف 5 ملفات `*.php.bak` في `src/Controller/` + `repo-header.twig.bak` + `github.css.bak_*` |
| 6.2 | حذف السمة القديمة: `templates/repo/`, `templates/admin/`, `templates/auth/`, `templates/docs/`, `templates/partials/` (ميتة وتتكسر عند التفعيل) — **بعد موافقة صريحة** (قاعدة AGENTS.md 2) |
| 6.3 | حذف `gitphp-main_3.zip` (410KB) من الجذر |
| 6.4 | سياسة backup/: نقل tar.gz (138MB) خارج الحساب/تحميله لموقع تخزين + جدولة أسبوعية بأسبوع احتفاظ |
| 6.5 | توحيد نسخ AGENTS.md/GEMINI.md الثلاث إلى مرجع واحد + روابط |
| 6.6 | تصحيح `docs/security.md`: ادعاء proc_open-only (يوجد `@exec()` في `RepoController:599` — تحويلهما إلى GitService) + ادعاء Argon2id (التسجيلات تستخدم BCRYPT — توحيدها `PASSWORD_ARGON2ID` إن توفر الملحق) |
| 6.7 | إزالة `@exec()` من دوال اللغات في `RepoController` (اتساق أمني) |
| 6.8 | تحديث README/AGENTS بأوامر التحقق (lint/typecheck): `php -l` لكل ملف معدَّل + `bin/migrate.php --dry-run` |

---

# الجدول الزمني الإجمالي

| الأسبوع | المرحلة | المخرجات الرئيسية | معيار القبول |
|---|---|---|---|
| 1 | 0 + بداية 1 | إغلاق 6 ثغرات، بدء إصلاحات P0 | كل بند 0.x مقفول باختبار يدوي موثّق |
| 2 | 1 | كل أخطاء P0 الأربعة عشر مصححة | بحث يعمل بكل تبويباته، روابط 0×404، عدادات الأدمن صحيحة |
| 3-4 | 2.1-2.2 | جدول release_assets + رفع مجزّأ JS + عدّادات وبصمات | رفع 500MB مع قطع شبكة يستأنف؛ sha256 متطابق طرفياً |
| 5 | 2.3-3.2 | مركز تحميل Range + تعديل الإصدار + نشر مسودة | ETag/304 يعملان على raw + أرشيف |
| 6 | 3.3-3.12 | صفحة إصدار، حماية وسوم، API releases، webhook release، RSS | Webhook يصل مع payload صحيح؛ pre-receive يرفض حذف وسم محمي |
| 7-8 | 4.1-4.3 | Labels/Milestones/Assignees + استراتيجيات دمج + مراجعات متعددة + ويكي بنسخ | سيناريو GitHub كامل: label → milestone → assign → merge squash → auto-close issue |
| 9 | 4.4-4.6 | بحث داخل repo + إشعارات مفضلة + worker بريد + ترجمة النصوص المصلَّبة | صفر نصوص إنجليزية/عربية مُصلَّبة في القوالب المذكورة |
| 10-11 | 5 | فهارس + 304 + إصلاح الكاش + N+1 + دوران سجلات | EXPLAIN نظيفة؛ 304 على raw؛ 50KB صفحة رئيسية باردة أقل استدعاء git |
| 12 | 6 | مستودع نظيف + وثائق دقيقة | `find . -name "*.bak"` صفر؛ سمات ميتة محذوفة بموافقة |

**نقاط فحص الالتزام (نهاية كل أسبوع):**
1. `php -l` لكل ملف معدَّل (قاعدة AGENTS.md 1).
2. لا وظيفة قائمة حُذفت (قاعدة 2) — فحص git diff.
3. مراجعة نهائية منفصلة لكل ملف (قاعدة 7).
4. تقرير موجز موثَّق بعد كل خطوة (قاعدة 5).

---

# المخاطر والقرارات المطلوبة من المالك

| # | القرار | البدائل | التوصية |
|---|---|---|---|
| 1 | حذف السمة القديمة (6.2) | حذف / دعم قوالب releases جديدة لها | حذف — لا قيمة صيانة |
| 2 | صلاحيات الويكي (4.3.2) | owner فقط / write-collabs | write-collabs (سلوك Gitea) |
| 3 | SVG للصور الرمزية (0.3) | حظر / تعقيم | حظر — أمان بلا تكلفة |
| 4 | حذف الوسم مع الإصدار (1.9) | حذف متتالٍ / منع + رسالة | خيار checkbox صريح للمستخدم |
| 5 | Worker البريد (4.5.2) | cron كل دقيقة / مزامن مع إبطال | cron — `email_outbox` جاهز |

---

# ملاحظة تنفيذية
- **ترتيب التنفيذ صارم:** لا تبدأ المرحلة 1 قبل إغلاق المرحلة 0 كلياً؛ لا تبدأ 3 قبل 2.1 (المرفقات أساس الإصدارات الكاملة).
- كل مرحلة تُسلَّم بـ migration قابلة للتراجع + تحديث وثائق API بالعربية والإنجليزية.
- التنفيذ المتوازي الممكن: 4.6 (الترجمة) مع أي مرحلة — لا تبعيات تقنية.

---

# سجل التنفيذ — اكتملت جميع المراحل ✅ (2026-09-11)

| المرحلة | الحالة | أبرز المنجزات |
|---|---|---|
| 0 — أمن عاجل | ✅ | 6 ثغرات مغلقة (purge 403، حذف سكربتات الترقيع + حجب htaccess، حظر SVG، نطاق كاش البحث، فحص صلاحيات API الوسوم/الفروع، APP_DEBUG=0) |
| 1 — أخطاء P0 | ✅ | 12 بنداً (NavCounts موحد، تبويبات/روابط البحث، tar.gz، تسريب المسودات، منطق Latest، ويدجت الرفع، user_id=0، تناسق Tag↔Release، posix_spawn retry + ذاكرة 256M + تخفيض ضوضاء 400، سياق Tags، حماية التعليقات) |
| 2 — تحميل/رفع | ✅ | جدول release_assets + محرك رفع مجزّأ 5MB قابل للاستئناف + SHA256 + ETag/Range/304 + عدادات صادقة + Range لمركز التحميل + بصمات الأرشيفات |
| 3 — إكمال الإصدارات | ✅ | تعديل/نشر/صفحة منفردة/واجهة وسوم/حماية وسوم wildcard + pre-receive/API كامل/webhook release حي/RSS/أهداف SHA |
| 4 — تجربة المستخدم | ✅ | Labels/Milestones/Assignees + فلاتر وترقيم + استراتيجيات دمج أربع (مختبرة) + مراجعون متعددون + ويكي بسجل واسترجاع وصلاحيات write + بحث داخل المستودع + i18n |
| 5 — الأداء | ✅ | 5 فهارس (EXPLAIN نظيف) + ETag/Last-Modified/304 على raw + إصلاح forgetPrefix على redis (SCAN صحيح) + مصدر واحد للكاش + كاش صف المستودع داخل عائلة الإبطال + دوران سجلات 30ي/50MB |
| 6 — النظافة | ✅ | حذف .bak (7) + السمة القديمة كاملة + zip + توحيد Argon2id + إزالة @exec من دوال اللغات (4 مواضع) + تصحيح security.md + توحيد ملفات الوكيل |

**الجداول المضافة:** release_assets, issue_labels, issue_label_map, issue_milestones, pr_reviews, wiki_revisions (+ توثيق 4 جداول runtime في نظام الترحيل)
**الأعمدة المضافة:** branch_protections.ref_pattern, bug_reports.milestone_id/assigned_to, pull_requests.merge_strategy/is_draft
**الملفات:** ~40 ملفاً معدلاً، 12 جديداً، 4 migrations، 15+ قالباً/قالباً جزئياً جديداً — كلها php -l نظيفة ومختبرة حياً.
