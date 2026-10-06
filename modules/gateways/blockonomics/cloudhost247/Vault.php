<?php
/**
 * Server-side credential vault for the Blockonomics API key.
 *
 * AES-256-GCM; key derived from the WHMCS installation secret
 * (cc_encryption_hash) with a per-purpose domain separator. Ciphertext lives
 * in the governance store under `vault.api_key`. The key is never rendered,
 * returned, logged or audited — only `mask()` output is safe to display.
 *
 * @package CloudHost247\Blockonomics
 */

namespace CloudHost247\Blockonomics;

class Vault
{
    const STORE_KEY = 'vault.api_key';

    const MASK = '••••••••••••••••••••••••••••••••';

    const AUDIT_REDACTED = '[REDACTED]';

    /** @var GovernanceStoreInterface */
    private $store;

    /** @var string raw key material (never the API key itself) */
    private $material;

    /**
     * @param string $installationSecret WHMCS cc_encryption_hash — any
     *   non-empty site-unique secret; empty string disables the vault
     *   (resolver falls back to legacy storage, fail-closed for writes).
     */
    public function __construct(GovernanceStoreInterface $store, $installationSecret)
    {
        $this->store = $store;
        $this->material = hash('sha256', 'chs.blockonomics.vault|' . (string) $installationSecret, true);
    }

    public function available()
    {
        return $this->material !== hash('sha256', 'chs.blockonomics.vault|', true);
    }

    /** Store a new key in a slot. Empty input deletes the slot. Audit elsewhere. */
    public function saveSlot($slot, $secret)
    {
        $secret = trim((string) $secret);
        if (!$this->available()) {
            throw new \RuntimeException('Vault is not available: installation secret missing.');
        }
        $key = 'vault.' . preg_replace('/[^a-z0-9_.-]/i', '', (string) $slot);
        if ($secret === '') {
            $this->store->set($key, '');
            return;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $secret,
            'aes-256-gcm',
            $this->material,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        $this->store->set($key, base64_encode($iv . $tag . $cipher));
    }

    /** @return string decrypted slot value, '' when not configured */
    public function readSlot($slot)
    {
        if (!$this->available()) {
            return '';
        }
        $blob = $this->store->get('vault.' . preg_replace('/[^a-z0-9_.-]/i', '', (string) $slot));
        if (!$blob) {
            return '';
        }
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 12 + 16 + 1) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $this->material,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        return $plain === false ? '' : (string) $plain;
    }

    /** Store a new key. Empty input deletes the stored key. Audit elsewhere. */
    public function save($apiKey)
    {
        $this->saveSlot('api_key', $apiKey);
    }

    /** @return string decrypted key, '' when not configured */
    public function read()
    {
        return $this->readSlot('api_key');
    }

    public function slotConfigured($slot)
    {
        return $this->readSlot($slot) !== '';
    }

    public function configured()
    {
        return $this->read() !== '';
    }

    /** Display-safe rendering; never reveals even length information. */
    public function mask()
    {
        return $this->configured() ? self::MASK : '';
    }
}
