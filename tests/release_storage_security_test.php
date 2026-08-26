<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$storage = file_get_contents($root . '/includes/ReleaseStorage.php');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_string($storage)) {
    $fail('release storage source could not be read');
}

foreach ([
    "if (isset(\$entries[\$name]))" => 'duplicate ZIP entries are not rejected',
    'MAX_MANIFEST_BYTES' => 'manifest decompression has no dedicated safety ceiling',
    'statIndex($manifestIndex)' => 'manifest uncompressed size is not inspected before decompression',
    'getFromIndex($manifestIndex, self::MAX_MANIFEST_BYTES + 1)' => 'manifest parsing can inflate an unbounded ZIP entry',
    'MAX_BLOCKMAP_BYTES' => 'blockmap decompression does not have a dedicated safety ceiling',
    'MAX_METADATA_BYTES' => 'updater metadata decompression does not have a dedicated safety ceiling',
    'self::extractEntry($zip, $name, $dest, (int)$meta[\'size\'])' => 'artifact extraction is not bounded by the verified manifest size',
    'stream_copy_to_stream($in, $out, $expectedSize + 1)' => 'ZIP extraction can decompress without a hard byte ceiling',
    '$copied !== $expectedSize' => 'ZIP extraction does not reject oversized or truncated artifacts',
    'strlen($sha512Raw) !== 64' => 'installer SHA-512 is not validated as an exact 512-bit digest',
    'file_put_contents($staging . DIRECTORY_SEPARATOR . \'manifest.json\', $storedManifest . "\\n", LOCK_EX) === false' => 'verified manifest persistence errors are ignored',
] as $needle => $message) {
    if (!str_contains($storage, $needle)) {
        $fail($message);
    }
}

if (str_contains($storage, "$manifestText = $zip->getFromName('manifest.json')")) {
    $fail('legacy unbounded manifest decompression remains');
}
if (preg_match('/extractEntry\s*\([^,]+,[^,]+,[^,]+\)\s*;/', $storage) === 1) {
    $fail('an unbounded three-argument extractEntry call remains');
}

echo "PASS release storage manifest and artifact extraction hardening\n";
