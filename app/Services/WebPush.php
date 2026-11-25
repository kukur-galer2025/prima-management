<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pengirim Web Push minimal (RFC 8030 + VAPID RFC 8292) tanpa payload.
 *
 * Push dikirim kosong; service worker lalu mengambil isi pengingat dari /pengingat/terbaru.
 * Dengan begitu tidak perlu enkripsi payload (RFC 8291) maupun library tambahan.
 */
class WebPush
{
    /** Awalan DER SEC1 ECPrivateKey untuk kurva P-256, sebelum 32 byte kunci privat. */
    private const PRIVATE_KEY_DER_PREFIX = "\x30\x77\x02\x01\x01\x04\x20";

    /** OID prime256v1 + pembuka BIT STRING kunci publik (65 byte). */
    private const PRIVATE_KEY_DER_MIDDLE = "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\xa1\x44\x03\x42\x00";

    public function __construct(
        private ?string $publicKey,
        private ?string $privateKey,
        private string $subject,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->publicKey) && filled($this->privateKey);
    }

    public function publicKey(): ?string
    {
        return $this->publicKey;
    }

    /**
     * Buat pasangan kunci VAPID baru dalam format base64url standar Web Push.
     *
     * @return array{public_key: string, private_key: string}
     */
    public static function generateKeys(): array
    {
        // PHP di Windows sering tidak menemukan openssl.cnf, jadi sediakan konfigurasi minimal.
        $config = tempnam(sys_get_temp_dir(), 'vapid');
        file_put_contents($config, "[req]\ndefault_bits = 2048\ndistinguished_name = dn\n[dn]\n");

        try {
            $key = openssl_pkey_new([
                'curve_name' => 'prime256v1',
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'config' => $config,
            ]);
        } finally {
            @unlink($config);
        }

        if ($key === false) {
            throw new RuntimeException('Gagal membuat kunci VAPID: '.openssl_error_string());
        }

        $ec = openssl_pkey_get_details($key)['ec'];

        return [
            'public_key' => self::base64UrlEncode("\x04".self::padTo32($ec['x']).self::padTo32($ec['y'])),
            'private_key' => self::base64UrlEncode(self::padTo32($ec['d'])),
        ];
    }

    /**
     * Kirim push kosong ke satu perangkat. Langganan yang sudah kedaluwarsa (404/410) dihapus.
     */
    public function send(PushSubscription $subscription): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'vapid t='.$this->vapidToken($subscription->endpoint).', k='.$this->publicKey,
                    'TTL' => '3600',
                    'Urgency' => 'high',
                ])
                ->withBody('', 'application/octet-stream')
                ->post($subscription->endpoint);
        } catch (ConnectionException $exception) {
            Log::warning('Web push gagal terhubung', ['endpoint' => $subscription->endpoint, 'error' => $exception->getMessage()]);

            return false;
        }

        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();

            return false;
        }

        if ($response->failed()) {
            Log::warning('Web push ditolak', ['endpoint' => $subscription->endpoint, 'status' => $response->status(), 'body' => $response->body()]);

            return false;
        }

        return true;
    }

    /**
     * JWT ES256 yang membuktikan ke layanan push bahwa server ini pemilik kunci VAPID.
     */
    public function vapidToken(string $endpoint): string
    {
        $origin = parse_url($endpoint, PHP_URL_SCHEME).'://'.parse_url($endpoint, PHP_URL_HOST);

        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::base64UrlEncode(json_encode([
            'aud' => $origin,
            'exp' => time() + 12 * 3600,
            'sub' => $this->subject,
        ], JSON_UNESCAPED_SLASHES));

        if (! openssl_sign("{$header}.{$claims}", $derSignature, $this->privateKeyPem(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Gagal menandatangani token VAPID.');
        }

        return "{$header}.{$claims}.".self::base64UrlEncode(self::derSignatureToRaw($derSignature));
    }

    public function privateKeyPem(): string
    {
        $der = self::PRIVATE_KEY_DER_PREFIX
            .self::base64UrlDecode((string) $this->privateKey)
            .self::PRIVATE_KEY_DER_MIDDLE
            .self::base64UrlDecode((string) $this->publicKey);

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    /**
     * OpenSSL menghasilkan tanda tangan DER (SEQUENCE r, s); JWT butuh r||s masing-masing 32 byte.
     */
    private static function derSignatureToRaw(string $der): string
    {
        $offset = 2;
        $rLength = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLength);
        $offset += 2 + $rLength;
        $sLength = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLength);

        return self::padTo32(ltrim($r, "\x00")).self::padTo32(ltrim($s, "\x00"));
    }

    private static function padTo32(string $bytes): string
    {
        return str_pad($bytes, 32, "\x00", STR_PAD_LEFT);
    }
}
