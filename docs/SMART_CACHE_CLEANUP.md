# نظام التنظيف الذكي التلقائي للكاش والتخزين المؤقت
## GitPHP Smart Cache & Storage Auto-Cleanup System

---

## 1. نظرة عامة (Overview)

تم تصميم **نظام التنظيف الذكي للكاش والتخزين المؤقت** لمنع تضخم مجلدات النظام (`storage/cache` و `storage/sessions` و `storage/tmp`) وضمان بقاء المساحة المستهلكة دائماً ضمن حدود آمنة ومحددة بدقة، دون التأثير على أداء الخادم أو قطع جلسات المستخدمين النشطة.

يعتمد النظام على **استراتيجية الإخلاء التدريجي متعدد المراحل (Multi-Stage Eviction)** مقترنة بـ **نقطة استقرار سفلية (Hysteresis Watermark)** لتفادي استهلاك موارد القرص والتكرار المستمر لعمليات الفحص.

---

## 2. آلية العمل الذكية (How It Works)

### 2.1 حد الحجم الأقصى ونقطة الاستقرار (Threshold & Watermark Hysteresis)
- **الحد الأقصى (High Watermark - `CACHE_MAX_SIZE_MB`):** الحجم الأقصى المسموح به لمجلد الكاش (الافتراضي: `50 MB`).
- **نقطة الاستقرار (Low Watermark - `CACHE_PRUNE_WATERMARK`):** النسبة المئوية المستهدفة عند حدوث التنظيف (الافتراضي: `80%` أي ما يعادل `40 MB`).
- **فائدة نقطة الاستقرار:** إذا تم تنظيف الكاش فقط إلى 50MB، فإن أي كتابة جديدة بمقدار 1KB ستعيد إطلاق التنظيف في كل طلب! التنظيف إلى 80% يضمن بقاء النظام في منطقة آمنة ومستقرة لآلاف الطلبات القادمة.

### 2.2 خطوات الإخلاء متعدد المراحل (Multi-Stage Eviction)
عند بلوغ الحد الأقصى أو أثناء التنظيف الدوري:
1. **المرحلة الأولى:** مسح ملفات الكاش منتهية الصلاحية (`*.cache`) اعتماداً على ترويسة وقت الانتهاء (TTL) المدمجة بكل ملف.
2. **المرحلة الثانية:** فحص مجلد قوالب Twig المترجمة (`storage/cache/twig`) وحذف القوالب القديمة التي لم يتم استخدامها لأكثر من 12 إلى 48 ساعة وتنظيف المجلدات الفارغة.
3. **المرحلة الثالثة:** في حال استمرار الحجم أعلى من نقطة الاستقرار، يتم إخلاء أقدم ملفات الكاش وصولاً (LRU / FIFO Eviction) تدريجياً حتى ينخفض الحجم تحت الـ Watermark.
4. **المرحلة الرابعة:** تنظيف ملفات الجلسات المنتهية (`storage/sessions/sess_*`) الأقدم من 72 ساعة، والملفات المؤقتة العالقة في `storage/tmp` و `/tmp/git_*`.

### 2.3 الرقابة التلقائية فائقة الخفة (Zero-Overhead Auto Enforcement)
- لا يتم فحص حجم القرص في كل طلب لتفادي أي بطء في الاستجابة (I/O Bottleneck).
- يتم أخذ عينات دورية (كل 30 عملية كتابة في الكاش) مع قفل زمني يمنع تكرار الفحص لأكثر من مرة كل 5 دقائق (300 ثانية).
- يعمل الفحص في الخلفية بسلاسة تامة دون شعور الزائر بأي تأخير.

---

## 3. متغيرات البيئة والإعدادات (Configuration & Setup)

يمكن ضبط المعايير عبر ملف البيئة `.env` أو عبر ملف الإعدادات `config/app.php`:

### إعدادات `.env`:
```env
# الحد الأقصى لمجلد الكاش بالميجابايت قبل تفعيل التنظيف التلقائي
CACHE_MAX_SIZE_MB=50

# نسبة الحجم المستهدفة بعد التنظيف (نقطة الاستقرار لمنع التذبذب المستمر)
CACHE_PRUNE_WATERMARK=80
```

### إعدادات `config/app.php`:
```php
'cache_max_size_mb' => (int) env('CACHE_MAX_SIZE_MB', 50),
'cache_watermark'   => (int) env('CACHE_PRUNE_WATERMARK', 80),
```

---

## 4. الربط البرمجي وهيكلية الكود (Architecture & Integration)

يتكون النظام من العناصر التالية:

1. **[`src/Service/CacheCleaner.php`](file:///home/git.ysnapp.com/src/Service/CacheCleaner.php):**
   المحرك المركزي المسؤول عن حساب الأحجام، فحص ترويسات الصلاحية، حذف القوالب القديمة، وإخلاء الملفات عند تجاوز الحد الأقصى.
   - `smartClean(maxTwigAge, maxSessionAge, maxTempAge, maxCacheSizeMb, watermarkPercent): array`
   - `fullFlush(includeSessions): array`
   - `isOverThreshold(maxMb): bool`
   - `getStorageBreakdown(): array`

2. **[`src/Service/Cache.php`](file:///home/git.ysnapp.com/src/Service/Cache.php):**
   - استدعاء `autoEnforceThreshold()` تلقائياً في دالة `set()` عند تخزين أي قيمة جديدة.
   - توفير واجهة برمجية مباشرة عبر دالة `$app->cache()->smartClean()`.

3. **[`src/Controller/SystemAdminController.php`](file:///home/git.ysnapp.com/src/Controller/SystemAdminController.php):**
   - احتساب وعرض حجم الكاش المباشر `cache_formatted` في لوحة الإدارة (`/admin/system`).
   - توفير مسار ويب محمي لطلب التنظيف: `POST /{$ap}/system/smart-clean`.

4. **[`templates/github/admin/system.twig`](file:///home/git.ysnapp.com/templates/github/admin/system.twig):**
   - بطاقة إحصائية مخصصة لمساحة الكاش ضمن شبكة التخزين.
   - بطاقة إجراءات سريعة بتصميم GitHub لتشغيل التنظيف الذكي بنقرة واحدة.

---

## 5. التشغيل وجدولة المهام (CLI & Cron Automation)

### استخدام أداة سطر الأوامر (`bin/clean-cache.php`):

```bash
# 1. عرض تقرير تفصيلي بالأحجام وحالة الكاش دون حذف أي ملف
php bin/clean-cache.php --status

# 2. تشغيل التنظيف الذكي وفقاً للإعدادات المعرفة في .env
php bin/clean-cache.php

# 3. تشغيل التنظيف مع تحديد حد أقصى مخصص (مثلاً 25 ميجابايت) ونسبة استقرار 70%
php bin/clean-cache.php --threshold=25 --watermark=70

# 4. التفريغ الشامل لكافة ملفات الكاش وقوالب Twig
php bin/clean-cache.php --all
```

### إعداد الجدولة التلقائية عبر Cron (Crontab):

لجدولة التنظيف الدوري في أوقات انخفاض الزيارات، قم بفتح محرر الـ Cron:
```bash
crontab -e
```

أضف السطر التالي لتشغيل التنظيف الذكي مرة كل 6 ساعات:
```cron
0 */6 * * * /usr/bin/php /home/git.ysnapp.com/bin/clean-cache.php > /dev/null 2>&1
```

أو لتشغيله مرة واحدة يومياً عند منتصف الليل:
```cron
0 0 * * * /usr/bin/php /home/git.ysnapp.com/bin/clean-cache.php > /dev/null 2>&1
```

---

## 6. أفضل الممارسات للإنتاج (Production Best Practices)

1. **الخوادم ذات المساحة المحدودة:** يُنصح بضبط `CACHE_MAX_SIZE_MB=25` و `CACHE_PRUNE_WATERMARK=75`.
2. **الخوادم الكبيرة / عالية الزيارات:** يُنصح بضبط `CACHE_MAX_SIZE_MB=100` مع الاعتماد على Redis أو Memcached للبيانات الديناميكية وترك الكاش الملفي لقوالب Twig فقط.
3. **التنظيف الآمن:** لا يمس التنظيف الذكي أي مستودعات Git أو قواعد بيانات أو ملفات مستخدمين، بل يقتصر حصراً على الكاش المؤقت والمهملات.