<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

\App\Core\App::bootstrap(BASE_PATH);

if (! extension_loaded('openssl')) {
    fwrite(STDERR, "OpenSSL extension is required.\n");
    exit(1);
}

$targetDirectory = BASE_PATH . '/storage/keys';
if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0775, true) && ! is_dir($targetDirectory)) {
    fwrite(STDERR, "Could not create key directory: {$targetDirectory}\n");
    exit(1);
}

$privateKeyPath = $targetDirectory . '/launcher_gate_private.pem';
$publicKeyPath = $targetDirectory . '/launcher_gate_public.pem';

$keyResource = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);

if ($keyResource === false) {
    fwrite(STDERR, "Could not generate launcher keypair.\n");
    exit(1);
}

$privateKeyPem = '';
if (! openssl_pkey_export($keyResource, $privateKeyPem)) {
    fwrite(STDERR, "Could not export private key.\n");
    exit(1);
}

$details = openssl_pkey_get_details($keyResource);
if (! is_array($details) || trim((string) ($details['key'] ?? '')) === '') {
    fwrite(STDERR, "Could not read public key.\n");
    exit(1);
}

$publicKeyPem = trim((string) $details['key']) . PHP_EOL;

file_put_contents($privateKeyPath, $privateKeyPem);
file_put_contents($publicKeyPath, $publicKeyPem);

@chmod($privateKeyPath, 0600);
@chmod($publicKeyPath, 0644);

echo "Launcher keypair generated.\n";
echo "Private key: {$privateKeyPath}\n";
echo "Public key:  {$publicKeyPath}\n";
echo "\n";
echo "Production env:\n";
echo "  LAUNCHER_GATE_SIGNATURE_ENABLED=true\n";
echo "  LAUNCHER_GATE_ALLOW_LEGACY_TOKEN=false\n";
echo "  LAUNCHER_GATE_KEY_ID=travel-launcher-1\n";
echo "  LAUNCHER_GATE_PUBLIC_KEY_PATH=storage/keys/launcher_gate_public.pem\n";
echo "\n";
echo "Launcher config:\n";
echo "  \"launcherKeyId\": \"travel-launcher-1\"\n";
echo "  \"launcherPrivateKeyPath\": \"launcher_gate_private.pem\"\n";
