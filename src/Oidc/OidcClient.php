<?php

/**
 * @package     IDer Login for Joomla
 * @copyright   (C) IDer
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace IDer\Plugin\System\IderLogin\Oidc;

\defined('_JEXEC') or die;

/**
 * Client OpenID Connect minimo per IDer: authorization code, state e nonce,
 * verifica RS256 dell'id_token con le chiavi JWKS, userinfo.
 */
final class OidcClient
{
    public const ISSUER = 'https://oid.ider.com/core';
    private const CACHE_TTL = 86400;
    private const CLOCK_SKEW = 300;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $cacheDir,
        private readonly string $issuer = self::ISSUER
    ) {
    }

    /** @return array{state:string,nonce:string} */
    public static function newRequest(): array
    {
        return ['state' => self::randomString(32), 'nonce' => self::randomString(32)];
    }

    public function authorizationUrl(string $redirectUri, string $scope, array $request): string
    {
        $config = $this->discovery();
        $params = [
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => $redirectUri,
            'scope'         => trim('openid ' . $scope),
            'state'         => $request['state'],
            'nonce'         => $request['nonce'],
        ];

        return $config['authorization_endpoint'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** Scambia il codice, verifica l'id_token e restituisce i dati di userinfo. */
    public function finish(string $code, string $redirectUri, array $request): array
    {
        $config = $this->discovery();
        $tokens = $this->request($config['token_endpoint'], http_build_query([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirectUri,
        ], '', '&'), [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
        ]);

        if (empty($tokens['id_token']) || empty($tokens['access_token'])) {
            throw new OidcException('Token response without id_token or access_token');
        }

        $idClaims = $this->verifyIdToken($tokens['id_token'], $request['nonce']);
        $userInfo = $this->request($config['userinfo_endpoint'], null, ['Authorization: Bearer ' . $tokens['access_token']]);

        if (!isset($userInfo['sub']) || !hash_equals((string) $idClaims['sub'], (string) $userInfo['sub'])) {
            throw new OidcException('userinfo subject does not match id_token');
        }

        return $userInfo;
    }

    public function verifyIdToken(string $jwt, string $nonce): array
    {
        $parts = explode('.', $jwt);

        if (\count($parts) !== 3) {
            throw new OidcException('Malformed id_token');
        }

        $header    = json_decode(self::base64UrlDecode($parts[0]), true);
        $claims    = json_decode(self::base64UrlDecode($parts[1]), true);
        $signature = self::base64UrlDecode($parts[2]);

        if (!\is_array($header) || !\is_array($claims) || ($header['alg'] ?? '') !== 'RS256') {
            throw new OidcException('Malformed or unsupported id_token');
        }

        $kid = isset($header['kid']) ? (string) $header['kid'] : null;
        $key = $this->findKey($kid, false) ?? $this->findKey($kid, true);

        if ($key === null) {
            throw new OidcException('No signing key for id_token');
        }

        if (openssl_verify($parts[0] . '.' . $parts[1], $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new OidcException('Invalid id_token signature');
        }

        $now = time();

        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $this->issuer) {
            throw new OidcException('Wrong id_token issuer');
        }

        if (!\in_array($this->clientId, (array) ($claims['aud'] ?? []), true)) {
            throw new OidcException('Wrong id_token audience');
        }

        if (!isset($claims['exp']) || $claims['exp'] + self::CLOCK_SKEW < $now) {
            throw new OidcException('Expired id_token');
        }

        if (isset($claims['iat']) && $claims['iat'] - self::CLOCK_SKEW > $now) {
            throw new OidcException('id_token issued in the future');
        }

        if (!hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new OidcException('Wrong id_token nonce');
        }

        if (empty($claims['sub'])) {
            throw new OidcException('id_token without subject');
        }

        return $claims;
    }

    private function findKey(?string $kid, bool $refresh): ?string
    {
        $jwks = $this->cached('jwks', $this->discovery()['jwks_uri'], $refresh);

        foreach ((array) ($jwks['keys'] ?? []) as $jwk) {
            if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
                continue;
            }

            if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
                continue;
            }

            if ($kid !== null && isset($jwk['kid']) && $jwk['kid'] !== $kid) {
                continue;
            }

            return self::rsaPublicKeyPem(self::base64UrlDecode($jwk['n']), self::base64UrlDecode($jwk['e']));
        }

        return null;
    }

    public static function rsaPublicKeyPem(string $modulus, string $exponent): string
    {
        $rsaKey    = self::derSequence(self::derInteger($modulus) . self::derInteger($exponent));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $bitString = "\x03" . self::derLength(\strlen($rsaKey) + 1) . "\x00" . $rsaKey;
        $der       = self::derSequence($algorithm . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '' || \ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::derLength(\strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(\strlen($content)) . $content;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return \chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return \chr(0x80 | \strlen($bytes)) . $bytes;
    }

    public function discovery(): array
    {
        $config = $this->cached('discovery', $this->issuer . '/.well-known/openid-configuration', false);

        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri'] as $key) {
            if (empty($config[$key]) || !str_starts_with((string) $config[$key], 'https://')) {
                throw new OidcException('Invalid discovery document');
            }
        }

        return $config;
    }

    private function cached(string $name, string $url, bool $refresh): array
    {
        $file = $this->cacheDir . '/' . $name . '.json';

        if (!$refresh && is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);

            if (\is_array($data) && ($data['u'] ?? '') === $url && ($data['t'] ?? 0) + self::CACHE_TTL > time()) {
                return (array) $data['v'];
            }
        }

        $value = $this->request($url, null, []);

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }

        @file_put_contents($file, json_encode(['t' => time(), 'u' => $url, 'v' => $value]), LOCK_EX);

        return $value;
    }

    public function clearCache(): void
    {
        foreach (['discovery', 'jwks'] as $name) {
            @unlink($this->cacheDir . '/' . $name . '.json');
        }
    }

    private function request(string $url, ?string $body, array $headers): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new OidcException('Refusing non-HTTPS request');
        }

        $headers[] = 'Accept: application/json';
        $ch        = curl_init($url);
        $options   = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'IDerLogin-Joomla/1.0',
        ];

        // CA bundle di Joomla (alcuni hosting non hanno quello di sistema)
        $caFile = JPATH_LIBRARIES . '/vendor/composer/ca-bundle/res/cacert.pem';

        if (is_file($caFile)) {
            $options[CURLOPT_CAINFO] = $caFile;
        }

        if ($body !== null) {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        if ($response === false) {
            throw new OidcException('HTTP error: ' . $error);
        }

        $data = json_decode((string) $response, true);

        if ($status < 200 || $status >= 300) {
            if (\is_array($data) && isset($data['error'])) {
                $detail = (string) $data['error'];
            } else {
                $detail = mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $response))), 0, 160);
            }

            throw new OidcException('IDer server error ' . $status . ' on ' . parse_url($url, PHP_URL_PATH) . ' (' . preg_replace('/[^\w .:,()\/-]/u', '', $detail) . ')');
        }

        if (!\is_array($data)) {
            throw new OidcException('Invalid JSON from IDer server');
        }

        return $data;
    }

    public static function randomString(int $bytes): string
    {
        return self::base64UrlEncode(random_bytes($bytes));
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad  = \strlen($data) % 4;

        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode($data, true);
    }
}
