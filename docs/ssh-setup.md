# GitPHP — إعداد وصول SSH والمفاتيح العامة ��

> 📚 **فهرس الأدلة:** [فهرس التوثيق](README.md) • [دليل التثبيت السريع](installation.md) • [النشر على CyberPanel](deploy.md) • [الدفع بالرموز الشخصية PAT](token-push.md) • [نموذج OLS](openlitespeed.conf)

---

تمكين الاستنساخ والدفع الآمن عبر المنفذ 22:

```
ssh://git@git.ysnapp.com/admin/repo.git
# أو بالصيغة المختصرة:
git@git.ysnapp.com:admin/repo.git
```

---

## كيف يعمل؟

```
git clone ssh://git@host/admin/repo.git
        │
        ▼
SSH daemon (port 22) — مستخدم git
        │
        ▼  forced command من authorized_keys
/usr/bin/php /home/git.example.com/bin/git-shell-wrapper.php
        │
        ├─ يقرأ SSH_ORIGINAL_COMMAND
        ├─ يسمح فقط بـ git-upload-pack / git-receive-pack
        ├─ يتحقق من المستودع عبر realpath ضمن REPOS_PATH
        │
        ▼
git-upload-pack / git-receive-pack على المستودع الفعلي
```

الـ wrapper يمنع أي shell تفاعلي أو أوامر أخرى كلياً لحماية الخادم.

---

## 1. إنشاء مستخدم `git`

لإنشاء مستخدم مخصص بدون shell عادي:

```bash
sudo useradd -m -d /home/git -s /usr/sbin/nologin git
sudo mkdir -p /home/git/.ssh
sudo chmod 700 /home/git/.ssh
sudo chown -R git:git /home/git/.ssh
```

> **تجنب التعارض مع CyberPanel:** مستخدم `git` منفصل تماماً عن مستخدمي المواقع، ويشارك نفس منفذ 22 بدون تعارض.

### السماح للمستخدم بتنفيذ الـ wrapper رغم nologin

إذا كان نظامك يمنع تنفيذ الأوامر مع `/usr/sbin/nologin`، استخدم:

```bash
sudo usermod -s /bin/bash git
```

مع الحفاظ على حماية الـ forced command (`no-pty` في `authorized_keys` تمنع الجلسات التفاعلية).

---

## 2. صلاحيات المستودعات وملف authorized_keys

لتمكين مستخدم الويب في CyberPanel (مثلاً `gitys2614`) من تحديث `authorized_keys` تلقائياً:

```bash
# 1. أضف مستخدم الويب لمجموعة git
sudo usermod -aG git gitys2614

# 2. اضبط صلاحيات ملف المفاتيح
sudo touch /home/git/.ssh/authorized_keys
sudo chown git:git /home/git/.ssh/authorized_keys
sudo chmod 660 /home/git/.ssh/authorized_keys

# 3. اضبط صلاحيات مجلد المستودعات
sudo chmod -R 775 /home/git.example.com/repos
```

---

## 3. ضبط GitPHP

في ملف `.env`:

```ini
AUTHORIZED_KEYS_PATH=/home/git/.ssh/authorized_keys
```

هذا المسار هو الملف الذي يعيد GitPHP بناءه تلقائياً عند إضافة أو حذف مفتاح من لوحة الإدارة.

---

## 4. إضافة المفاتيح من لوحة الإدارة

1. سجّل الدخول إلى الموقع.
2. افتح **Settings → SSH Keys** من لوحة التحكم.
3. ألصق المفتاح العام (مخرجات `cat ~/.ssh/id_ed25519.pub`).
4. يتحقق النظام من صحة المفتاح ثم يضيفه ويحدّث ملف `authorized_keys` فوراً.

صيغة السطر المُولَّد:

```
command="/usr/bin/php /home/git.example.com/bin/git-shell-wrapper.php",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty ssh-ed25519 AAAA... user@laptop
```

---

## 5. سكريبت الصيانة التلقائي `bin/fix-push.php`

إذا تم إضافة مفاتيح في قاعدة البيانات ولم تُكتب في الملف أو ظهرت مشاكل أذونات:

```bash
php /home/git.example.com/bin/fix-push.php
```

يعيد السكريبت توليد الملف وضبط الأذونات تلقائياً.

---

## 6. الاختبار

من جهاز العميل:

```bash
# 1. اختبار الاتصال
ssh -T git@git.ysnapp.com
# متوقع: "Interactive shell is not available." (دليل على عمل الـ wrapper بنجاح)

# 2. استنساخ المستودع
git clone git@git.ysnapp.com:admin/my-project.git

# 3. الدفع
cd my-project
git commit --allow-empty -m "ssh push test"
git push origin main
```

---

## 7. مقارنة SSH مع Token Push

إذا كنت ترغب بالدفع بدون إعداد مفاتيح SSH أو في بيئات CI/CD، استخدم [دليل الدفع بالرموز الشخصية PAT](token-push.md).

---

## استكشاف الأخطاء

| المشكلة | الحل |
|---------|------|
| `Permission denied (publickey)` | المفتاح غير موجود في `authorized_keys` — أعد حفظه من اللوحة أو شغّل `bin/fix-push.php` |
| `Repository not found` | المستودع غير موجود أو الاسم غير مطابق |
| `Server misconfiguration` | `REPOS_PATH` خاطئ في `.env` |
| الدفع يعلّق أو يرفض الصلاحيات | شغّل `php bin/fix-push.php` لإصلاح أذونات `repos/` |
