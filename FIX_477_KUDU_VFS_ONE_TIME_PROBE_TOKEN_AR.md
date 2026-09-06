# Hercule License Server — Fix477

## السبب
Fix476 وصل بنجاح إلى Production ومرّت قبله بوابات النشر وإعادة التشغيل وHealth وG1 الموقّع، لكن اختبار MySQL لم يبدأ لأن Kudu `/api/command` فشل في إنشاء ملف الـone-time token.

النتيجة المسجلة كانت:

`ERROR: could not arm one-time production MySQL probe token.`

لذلك هذا الفشل لا يثبت مشكلة في `EntitlementV2::withSeatLock()` أو MySQL؛ القفل لم يُختبر بعد.

## الإصلاح

تم حذف اعتماد مرحلة تسليح الـtoken على shell/Kudu command واستبداله بـ **Kudu VFS API** مباشرة:

- token عشوائي 256-bit عبر `openssl rand -hex 32`.
- صلاحية دقيقتين.
- الملف خارج `wwwroot`:
  - `/home/data/hercule-g1-mysql-runtime-probe.token`
  - Kudu VFS: `/api/vfs/data/hercule-g1-mysql-runtime-probe.token`
- الكتابة عبر VFS `PUT` مباشرة.
- القراءة الفورية عبر VFS `GET`.
- مقارنة SHA-256 والحجم بين البايتات المرسلة والملف الذي أعاده Kudu قبل استدعاء الـPHP worker.
- لا يتم طباعة token أو محتوى الملف في logs، ويتم إخفاء token وpayload عبر `::add-mask::`.
- التنظيف عبر VFS `DELETE` سواء نجح الاختبار أو فشل.

## اختبار Production بعد التسليح

بعد إثبات أن token موجود بالبايتات الدقيقة، يتم استدعاء:

`POST /public/api/v2/validate.php?g1_mysql_runtime_probe=1`

مع header one-time token.

الـPHP production worker يستهلك token مرة واحدة قبل أي عمل MySQL ثم يختبر الدالة الإنتاجية نفسها:

`EntitlementV2::withSeatLock()`

باستخدام مفتاح صناعي عشوائي فقط واتصال MySQL مستقل منافس.

الشهادة لا تمر إلا إذا ثبت:

1. MySQL الحقيقي هو driver الحالي.
2. Entitlement v2 schema جاهز.
3. اتصالا MySQL مستقلان (`CONNECTION_ID()` مختلف).
4. الاتصال المنافس لا يستطيع أخذ نفس named lock أثناء امتلاك `withSeatLock()` له.
5. القفل ينتقل للمنافس مباشرة بعد خروج `withSeatLock()`.
6. لا `INSERT` أو `UPDATE` أو `DELETE` ولا قراءة license key لعميل.
7. RSA signature صحيحة.
8. SHA-256 لـ`EntitlementV2.php` المنفذ Production يساوي candidate artifact بالضبط.
9. Evidence artifact مربوط بـrepository + commit + workflow run ويتم الاحتفاظ به 30 يوماً.
10. Final Kudu exact-byte verification و12 live probes تمر بعد ذلك.

## قرار الإطلاق

Fix477 لا يغلق F14/G1 بمجرد نجاح CI المحلي. الإغلاق يتطلب نجاح **Production workflow بالكامل** بما فيه MySQL web-worker proof ورفع Evidence artifact والـfinal drift diagnostics.
