<?php

namespace Modules\HikaShop\Services;

// A client of HikaShop's connector API (HikaShop Business): the same API the
// HikaShop mobile app uses, so the module needs nothing installed on the shop.
// The documentation of the API is on hikashop.com (developer area).
class Api
{
    // The connector answers on this path whatever the shop's URL settings.
    const PATH = '/index.php/hikashop-api/v1';

    private $shopUrl;
    private $token;

    public function __construct($shopUrl, $token = '')
    {
        $this->shopUrl = rtrim((string)$shopUrl, '/');
        $this->token = (string)$token;
    }

    // Exchange a pairing code (HikaShop backend, System > App Devices) for a
    // device token. Returns ['token' => ..., 'scopes' => [...]] or ['error' => ...].
    public function pair($code, $deviceName)
    {
        $r = $this->request('POST', '/pair', [], ['code' => trim((string)$code), 'device_name' => $deviceName], false);
        if (isset($r['error'])) {
            return $r;
        }
        if (empty($r['data']['token'])) {
            return ['error' => __('The shop did not return a token.')];
        }
        return ['token' => $r['data']['token'], 'scopes' => $r['data']['scopes'] ?? []];
    }

    public function site()
    {
        return $this->request('GET', '/site');
    }

    // The customer with exactly this address, or null. Shops before HikaShop
    // 6.7.0 ignore the email filter and search instead, hence the exact check.
    public function findCustomer($email)
    {
        $r = $this->request('GET', '/customers', ['email' => $email, 'search' => $email, 'limit' => 20]);
        if (isset($r['error'])) {
            return $r;
        }
        foreach ((array)$r['data'] as $c) {
            if (isset($c['email']) && strcasecmp($c['email'], $email) === 0) {
                return ['data' => $c];
            }
        }
        return ['data' => null];
    }

    public function customer($id)
    {
        return $this->request('GET', '/customers/'.(int)$id);
    }

    public function statuses()
    {
        return $this->request('GET', '/statuses');
    }

    // Currencies come with the product editor's reference data.
    public function currencies()
    {
        $r = $this->request('GET', '/products/meta');
        if (isset($r['error'])) {
            return $r;
        }
        return ['data' => $r['data']['currencies'] ?? []];
    }

    // Returns ['data' => ..., 'meta' => ...] or ['error' => message].
    private function request($method, $path, array $query = [], $body = null, $auth = true)
    {
        $url = $this->shopUrl.self::PATH.$path;
        if ($query) {
            $url .= '?'.http_build_query($query);
        }
        $headers = ['Accept: application/json'];
        if ($auth) {
            $headers[] = 'Authorization: Bearer '.$this->token;
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'FreeScout HikaShop module',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($raw === false) {
            return ['error' => __('Could not reach the shop: :error', ['error' => $curlError])];
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || !array_key_exists('data', $json)) {
            return ['error' => __('The shop did not answer as HikaShop does (HTTP :status). Check its address, and that it runs HikaShop Business.', ['status' => $status])];
        }
        if (!empty($json['error'])) {
            return ['error' => $json['error']['message'] ?? $json['error']['code'] ?? 'error', 'code' => $json['error']['code'] ?? '', 'status' => $status];
        }
        return ['data' => $json['data'], 'meta' => $json['meta'] ?? null];
    }
}
