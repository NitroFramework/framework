<?php

namespace Nitro\Htmx\Support;

class HxEncryptor
{
    private string $secret;

    public function __construct(
        private bool $enabled,
        private string $appKey,
    ) {
        $this->secret = substr(hash('sha256', $this->appKey ?: 'secret'), 0, 16);
    }

    public function encrypt(array $vals): string
    {
        if (!$this->enabled) {
            return base64_encode(json_encode($vals));
        }

        $json = json_encode($vals);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($json, 'aes-128-ctr', $this->secret, 0, $iv);

        return rtrim(base64_encode($iv . $encrypted), '=');
    }

    public function decrypt(string $payload): array
    {
        if (!$this->enabled) {
            return json_decode(base64_decode($payload), true) ?? [];
        }

        $data = base64_decode($payload);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        $json = openssl_decrypt($encrypted, 'aes-128-ctr', $this->secret, 0, $iv);

        return json_decode($json, true) ?? [];
    }
}