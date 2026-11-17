<?php
/** Direct Google OAuth and Search Console client. GPL-3.0-or-later. */
namespace OpenSEO;
defined('ABSPATH') || exit;

final class GSC {
    public static function callback() { return admin_url('admin-post.php?action=open_seo_oauth'); }
    public static function configured() { return defined('OPEN_SEO_GOOGLE_CLIENT_ID') && defined('OPEN_SEO_GOOGLE_CLIENT_SECRET') && OPEN_SEO_GOOGLE_CLIENT_ID && OPEN_SEO_GOOGLE_CLIENT_SECRET && function_exists('openssl_encrypt'); }
    private static function key() { return hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true); }
    public static function seal($data) {
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt(wp_json_encode($data), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) { throw new \RuntimeException('Token encryption failed.'); }
        return base64_encode($iv . $tag . $encrypted);
    }
    public static function unseal($value) {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 29) { throw new \RuntimeException('Stored Google credentials are invalid. Reconnect Google.'); }
        $json = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)) { throw new \RuntimeException('Google credentials could not be decrypted. Reconnect after changing WordPress salts.'); }
        return $data;
    }
    public static function connect() {
        if (!self::configured() || !is_ssl()) { throw new \RuntimeException('Configure Google OAuth credentials and use HTTPS before connecting.'); }
        $state = bin2hex(random_bytes(32)); $verifier = bin2hex(random_bytes(32));
        set_transient('open_seo_state_' . get_current_user_id(), ['state' => $state, 'verifier' => $verifier], 600);
        $query = ['client_id' => OPEN_SEO_GOOGLE_CLIENT_ID, 'redirect_uri' => self::callback(), 'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly', 'access_type' => 'offline', 'prompt' => 'consent',
            'state' => $state, 'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
        wp_redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($query), 302, 'Open SEO'); exit;
    }
    public static function oauth() {
        guard();
        try {
            $stored = get_transient('open_seo_state_' . get_current_user_id());
            delete_transient('open_seo_state_' . get_current_user_id());
            $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
            if (!$stored || !$state || !hash_equals($stored['state'], $state)) { throw new \RuntimeException('OAuth state expired or invalid. Start the connection again.'); }
            if (!empty($_GET['error'])) { throw new \RuntimeException('Google authorization was declined.'); }
            if (!self::configured()) { throw new \RuntimeException('Google OAuth configuration is missing.'); }
            $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
            if (!$code) { throw new \RuntimeException('Google returned no authorization code.'); }
            $token = self::token(['code' => $code, 'code_verifier' => $stored['verifier'], 'redirect_uri' => self::callback(), 'grant_type' => 'authorization_code']);
            if (empty($token['refresh_token']) || empty($token['access_token'])) { throw new \RuntimeException('Google returned no offline credentials. Reconnect and grant consent.'); }
            $token['expires_at'] = time() + (int) $token['expires_in'];
            update_option('open_seo_tokens', self::seal($token), false);
            finish('Google connected with read-only Search Console access.');
        } catch (\Throwable $e) { finish($e->getMessage()); }
    }
    private static function token($body) {
        $body['client_id'] = OPEN_SEO_GOOGLE_CLIENT_ID; $body['client_secret'] = OPEN_SEO_GOOGLE_CLIENT_SECRET;
        $response = wp_remote_post('https://oauth2.googleapis.com/token', ['timeout' => 20, 'body' => $body]);
        return self::decode($response);
    }
    private static function decode($response) {
        if (is_wp_error($response)) { throw new \RuntimeException('Google connection failed. Check outbound HTTPS and retry.'); }
        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            // Do not surface provider responses that could include sensitive request data.
            throw new \RuntimeException('Google request failed (HTTP ' . (int) $status . '). Check API activation, property access, OAuth consent and quotas; reconnect if authorization expired.');
        }
        return $data;
    }
    public static function request($url, $body = null, $retry = true) {
        if (!self::configured()) { throw new \RuntimeException('Google OAuth configuration is missing.'); }
        $token = self::unseal(get_option('open_seo_tokens', ''));
        if (($token['expires_at'] ?? 0) <= time() + 60) {
            if (empty($token['refresh_token'])) { throw new \RuntimeException('Reconnect Google for offline access.'); }
            $fresh = self::token(['grant_type' => 'refresh_token', 'refresh_token' => $token['refresh_token']]);
            if (empty($fresh['access_token'])) { throw new \RuntimeException('Google returned no access token. Reconnect.'); }
            $token = array_merge($token, $fresh); $token['expires_at'] = time() + (int) ($fresh['expires_in'] ?? 3600);
            update_option('open_seo_tokens', self::seal($token), false);
        }
        $args = ['method' => $body === null ? 'GET' : 'POST', 'timeout' => 20, 'redirection' => 0,
            'headers' => ['Authorization' => 'Bearer ' . $token['access_token'], 'Content-Type' => 'application/json']];
        if ($body !== null) { $args['body'] = wp_json_encode($body); }
        $response = wp_remote_request($url, $args);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 401 && $retry) {
            $token['expires_at'] = 0; update_option('open_seo_tokens', self::seal($token), false);
            return self::request($url, $body, false);
        }
        return self::decode($response);
    }
    public static function disconnect() {
        $value = get_option('open_seo_tokens', '');
        $failed = false;
        if ($value) {
            try {
                $token = self::unseal($value);
                $response = wp_remote_post('https://oauth2.googleapis.com/revoke', ['timeout' => 15, 'body' => ['token' => $token['refresh_token'] ?? $token['access_token']]]);
                $failed = is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200;
            } catch (\Throwable $e) { $failed = true; }
        }
        delete_option('open_seo_tokens'); delete_option('open_seo_report'); delete_option('open_seo_property');
        delete_transient('open_seo_inspection_' . get_current_user_id()); delete_transient('open_seo_state_' . get_current_user_id());
        if ($failed) { throw new \RuntimeException('Local connection removed. Google revocation could not be confirmed; remove access in your Google account’s third-party connections.'); }
    }
    public static function belongs($url, $property) {
        $parts = wp_parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !empty($parts['user']) || !empty($parts['pass'])) { return false; }
        if (strpos($property, 'sc-domain:') === 0) {
            $domain = strtolower(substr($property, 10)); $host = strtolower($parts['host'] ?? '');
            return $host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain;
        }
        $prefix = wp_parse_url($property);
        if (!$prefix || strtolower($parts['host'] ?? '') !== strtolower($prefix['host'] ?? '') ||
            strtolower($parts['scheme']) !== strtolower($prefix['scheme'] ?? '') ||
            ($parts['port'] ?? null) !== ($prefix['port'] ?? null)) { return false; }
        return strpos($parts['path'] ?? '/', $prefix['path'] ?? '/') === 0;
    }
}
add_action('admin_post_open_seo_oauth', [GSC::class, 'oauth']);
