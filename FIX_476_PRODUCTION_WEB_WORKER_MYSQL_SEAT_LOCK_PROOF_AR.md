# Hercule License Server — Fix476

## الهدف
إغلاق فشل Fix475 الناتج عن عدم توفر PHP CLI داخل Kudu sidecar (`exit 127`) بدون تخفيف بوابة G1 أو تنفيذ اختبار صوري.

## التنفيذ

- نقل اختبار MySQL الحقيقي إلى **PHP production web-worker** نفسه عبر المسار المثبت إنتاجياً:
  - `POST /public/api/v2/validate.php?g1_mysql_runtime_probe=1`
- المسار غير مفتوح للعامة عملياً:
  - GitHub Actions ينشئ token عشوائي 256-bit.
  - token يخزن في `/home/data/hercule-g1/mysql-runtime-probe.token` خارج `wwwroot`.
  - صلاحية token دقيقتان فقط.
  - المقارنة بـ `hash_equals`.
  - الاستهلاك محمي بـ `flock(LOCK_EX)` ويحدث مرة واحدة قبل بدء الاختبار.
  - token يخفى من Actions logs ويتم تنظيفه عند الخروج.
- الاختبار يستدعي الدالة الإنتاجية نفسها `EntitlementV2::withSeatLock()` بمفتاح صناعي عشوائي فقط.
- ينشئ اتصال MySQL مستقل منافس ويتحقق من:
  1. اختلاف `CONNECTION_ID()` بين الاتصالين.
  2. منع الاتصال المنافس من أخذ نفس named lock أثناء امتلاك الدالة الإنتاجية للقفل.
  3. نجاح انتقال القفل للمنافس بعد خروج `withSeatLock()`.
  4. تحرير القفل بعد الاختبار.
- لا يوجد `INSERT` أو `UPDATE` أو `DELETE` في مسار الاختبار، ولا تتم قراءة license key لأي عميل.
- الاستجابة الناجحة موقعة RSA وتحمل SHA-256 لملف `EntitlementV2.php` المنفذ فعلياً.
- بوابة CI تقارن SHA المنفذ في Production مع SHA الموجود في candidate deployment package.
- عند النجاح يتم إنشاء artifact تدقيق لمدة 30 يوماً مرتبط بـ repository + commit SHA + workflow run.

## تنظيف Fix475

تم حذف `scripts/g1_mysql_runtime_probe.php` لأنه كان CLI-only ولا يمكن تنفيذه داخل Kudu sidecar، لمنع وجود مسارين متنافسين لنفس شهادة G1.

## معيار النجاح

لا يعتبر Fix476 ناجحاً إلا إذا مرت بالتسلسل:

1. Server test suite.
2. G1 signed production attestation.
3. Production health بعد Azure restart.
4. Web-worker MySQL proof على قاعدة Production الحقيقية.
5. RSA verification للـproof.
6. Exact source SHA match.
7. G1 evidence artifact upload.
8. Exact Kudu byte verification + repeated live route probes.

أي فشل يبقي F14/G1 بحالة **NO-GO**.
