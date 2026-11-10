<?php

/**
 * @file
 * Creates the deployment signing key pair.
 *
 * Usage: php keygen.php <github-owner/repository>
 *
 * The private key goes straight into the repository's GitHub secrets through
 * the GitHub CLI: it is never printed nor written to disk. The public key is
 * written to deploy/deploy-key.pub, committed, and built into the receiver.
 * Run it again to rotate the key; the next deployment then needs the new
 * receiver uploaded once by hand (the old one rejects the new key).
 */

declare(strict_types=1);

$repository = $argv[1] ?? '';
if (!preg_match('#^[\w.-]+/[\w.-]+$#', $repository)) {
  fwrite(STDERR, "Usage: php keygen.php <github-owner/repository>\n");
  exit(2);
}

$pair = sodium_crypto_sign_keypair();
$secret = base64_encode(sodium_crypto_sign_secretkey($pair));
$public = base64_encode(sodium_crypto_sign_publickey($pair));
sodium_memzero($pair);

$process = proc_open(
  ['gh', 'secret', 'set', 'DEPLOY_PRIVATE_KEY', '--repo', $repository],
  [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
  $pipes,
);
if (!is_resource($process)) {
  fwrite(STDERR, "Could not start the GitHub CLI (gh).\n");
  exit(1);
}
fwrite($pipes[0], $secret);
fclose($pipes[0]);
sodium_memzero($secret);
if (proc_close($process) !== 0) {
  fwrite(STDERR, "The secret was not saved; the public key is unchanged.\n");
  exit(1);
}

file_put_contents(__DIR__ . '/deploy-key.pub', $public . "\n");
echo "Private key saved as the DEPLOY_PRIVATE_KEY secret of $repository.\n";
echo "Public key written to deploy/deploy-key.pub.\n";
