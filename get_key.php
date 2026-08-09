<?php
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/License.php';

$pdo = Database::pdo();

try {
    $pdo->exec("INSERT INTO customers (id, name, email) VALUES (1, 'Hercule Test', 'test@hercule.local')");
} catch (Exception $e) {
    // العميل موجود
}

$result = License::issue(1, 'lifetime', 5, null);

// التأكد من طباعة النص أو المفتاح من داخل المصفوفة
$key = is_array($result) ? ($result['license_key'] ?? $result['key'] ?? reset($result)) : $result;

echo "\n==========================================\n";
echo " 🚀 YOUR NEW LICENSE KEY:\n";
echo " " . $key . "\n";
echo "==========================================\n\n";