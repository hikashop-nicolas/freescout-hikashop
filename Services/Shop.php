<?php

namespace Modules\HikaShop\Services;

// Which shop a mailbox talks to, and what the sidebar shows about a customer.
class Shop
{
    // The shop of a mailbox: its own when it has one, else the global one.
    // Returns ['url' => ..., 'token' => ...] or null when none is set up.
    public static function forMailbox($mailbox = null)
    {
        if ($mailbox) {
            $own = self::mailboxSettings($mailbox);
            if (!empty($own['shop_url']) && !empty($own['token'])) {
                return ['url' => $own['shop_url'], 'token' => self::decrypt($own['token'])];
            }
        }
        $url = \Option::get('hikashop.shop_url');
        $token = \Option::get('hikashop.token');
        if ($url && $token) {
            return ['url' => $url, 'token' => self::decrypt($token)];
        }
        return null;
    }

    public static function mailboxSettings($mailbox)
    {
        $s = json_decode((string)($mailbox->hikashop ?? ''), true);
        return is_array($s) ? $s : [];
    }

    public static function decrypt($value)
    {
        try {
            return decrypt($value);
        } catch (\Exception $e) {
            return '';
        }
    }

    // Validate a shop address typed by an admin: http(s), no path tricks.
    public static function cleanUrl($url)
    {
        $url = rtrim(trim((string)$url), '/');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        return \Helper::sanitizeRemoteUrl($url) ? $url : '';
    }

    // Pair with the shop, and check the token answers. Returns the token or ['error' => ...].
    public static function connect($url, $code)
    {
        $api = new Api($url);
        $pair = $api->pair($code, 'FreeScout ('.parse_url(config('app.url'), PHP_URL_HOST).')');
        if (isset($pair['error'])) {
            return $pair;
        }
        $site = (new Api($url, $pair['token']))->site();
        if (isset($site['error'])) {
            return $site;
        }
        return ['token' => $pair['token'], 'site' => $site['data']];
    }

    // Everything the sidebar shows for these addresses, from cache when fresh.
    public static function customerView($shop, $emails, $refresh = false)
    {
        $minutes = (int)config('hikashop.cache_minutes', 60);
        foreach ($emails as $email) {
            $key = 'hikashop_c_'.md5($shop['url'].'|'.strtolower($email));
            if (!$refresh && \Cache::has($key)) {
                $view = \Cache::get($key);
            } else {
                $view = self::buildView($shop, $email);
                if (empty($view['error'])) {
                    \Cache::put($key, $view, now()->addMinutes($minutes));
                }
            }
            if (!empty($view['error']) || !empty($view['customer'])) {
                return $view;
            }
        }
        return ['customer' => null];
    }

    private static function buildView($shop, $email)
    {
        $api = new Api($shop['url'], $shop['token']);
        $found = $api->findCustomer($email);
        if (isset($found['error'])) {
            return ['error' => $found['error']];
        }
        if (empty($found['data'])) {
            return ['customer' => null];
        }
        $detail = $api->customer($found['data']['id']);
        if (isset($detail['error'])) {
            return ['error' => $detail['error']];
        }
        $c = $detail['data'];
        $ref = self::reference($shop);

        $orders = [];
        foreach (array_slice((array)($c['orders'] ?? []), 0, (int)config('hikashop.max_orders', 5)) as $o) {
            $status = $ref['statuses'][$o['status']] ?? ['label' => ucfirst((string)$o['status']), 'color' => ''];
            $orders[] = [
                'id'     => (int)$o['id'],
                'number' => $o['number'],
                'date'   => !empty($o['created']) ? date('M j, Y', (int)$o['created']) : '',
                'status' => $status['label'],
                'color'  => $status['color'],
                'total'  => self::money($o['total'] ?? 0, $ref['currencies'][$o['currency_id'] ?? 0] ?? null),
                'link'   => self::adminLink($shop, $ref['cms'], 'order', $o['id']),
            ];
        }

        $sections = [];
        foreach ((array)($c['sections'] ?? []) as $s) {
            $rows = [];
            foreach ((array)($s['rows'] ?? []) as $r) {
                $link = $r['url'] ?? '';
                if ($link === '' && !empty($r['ref'])) {
                    $link = self::adminLink($shop, $ref['cms'], $r['ref']['type'], $r['ref']['id']);
                }
                $rows[] = [
                    'label' => $r['label'] ?? '',
                    'value' => $r['value'] ?? '',
                    'badge' => !empty($r['badge']['text']) ? [
                        'text' => (string)$r['badge']['text'],
                        'tone' => in_array($r['badge']['tone'] ?? '', ['neutral', 'success', 'warning', 'danger'], true) ? $r['badge']['tone'] : 'neutral',
                    ] : null,
                    'link'  => $link,
                ];
            }
            $sections[] = ['title' => $s['title'] ?? '', 'rows' => $rows];
        }

        return [
            'customer' => [
                'name'        => $c['name'] ?? '',
                'email'       => $c['email'] ?? $email,
                'guest'       => ($c['type'] ?? '') === 'guest',
                'since'       => !empty($c['created']) ? date('M j, Y', (int)$c['created']) : '',
                'order_count' => count((array)($c['orders'] ?? [])),
                'link'        => self::adminLink($shop, $ref['cms'], 'customer', $c['id']),
            ],
            'orders'   => $orders,
            'sections' => $sections,
        ];
    }

    // One order's lines and totals, for the popover on its number. Only an order
    // of one of these addresses: the sidebar never shows another customer's.
    public static function orderView($shop, $orderId, $emails, $refresh = false)
    {
        $key = 'hikashop_o_'.md5($shop['url'].'|'.(int)$orderId);
        $order = (!$refresh && \Cache::has($key)) ? \Cache::get($key) : null;
        if ($order === null) {
            $r = (new Api($shop['url'], $shop['token']))->order($orderId);
            if (isset($r['error'])) {
                return ['error' => $r['error']];
            }
            $order = $r['data'];
            \Cache::put($key, $order, now()->addMinutes((int)config('hikashop.cache_minutes', 60)));
        }
        $owner = strtolower((string)($order['customer']['email'] ?? ''));
        if ($owner === '' || !in_array($owner, array_map('strtolower', $emails), true)) {
            return ['error' => __('This order is not one of this customer\'s.')];
        }

        $ref = self::reference($shop);
        $currency = $ref['currencies'][$order['currency_id'] ?? 0] ?? null;
        $items = [];
        foreach ((array)($order['items'] ?? []) as $i) {
            $qty = (int)($i['quantity'] ?? 1);
            $items[] = [
                'name'     => (string)($i['name'] ?? ''),
                'code'     => (string)($i['code'] ?? ''),
                'quantity' => $qty,
                'amount'   => self::money(((float)($i['price'] ?? 0) + (float)($i['tax'] ?? 0)) * $qty, $currency),
            ];
        }
        $t = (array)($order['totals'] ?? []);
        $fees = (array)($order['fees'] ?? []);
        return [
            'number'   => (string)($order['number'] ?? ''),
            'items'    => $items,
            'shipping' => !empty($t['shipping']) ? self::money($t['shipping'], $currency) : '',
            'shipping_method' => (string)($fees['shipping']['method_name'] ?? ''),
            'discount' => !empty($t['discount']) ? self::money($t['discount'], $currency) : '',
            'total'    => self::money($t['total'] ?? 0, $currency),
            'payment_method' => (string)($fees['payment']['method_name'] ?? ''),
        ];
    }

    // Statuses, currencies and the CMS, which change rarely: kept a day.
    private static function reference($shop)
    {
        return \Cache::remember('hikashop_ref_'.md5($shop['url']), now()->addDay(), function () use ($shop) {
            $api = new Api($shop['url'], $shop['token']);
            $ref = ['statuses' => [], 'currencies' => [], 'cms' => 'joomla'];
            $st = $api->statuses();
            foreach ((array)($st['data'] ?? []) as $s) {
                $color = (string)($s['color'] ?? '');
                $ref['statuses'][$s['namekey']] = [
                    'label' => ucfirst(str_replace('_', ' ', (string)$s['name'])),
                    'color' => preg_match('/^#[0-9a-f]{3,8}$/i', $color) ? $color : '',
                ];
            }
            $cu = $api->currencies();
            foreach ((array)($cu['data'] ?? []) as $c) {
                $ref['currencies'][(int)$c['id']] = $c;
            }
            $site = $api->site();
            if (!empty($site['data']['cms']['name'])) {
                $ref['cms'] = $site['data']['cms']['name'];
            }
            return $ref;
        });
    }

    // A price the way the shop writes it: its separators, symbol side and decimals.
    public static function money($amount, $currency)
    {
        if (!$currency) {
            return number_format((float)$amount, 2);
        }
        $n = number_format((float)$amount, (int)($currency['decimals'] ?? 2), $currency['decimal_sep'] ?? '.', $currency['thousands_sep'] ?? ',');
        $sym = (string)($currency['symbol'] ?? $currency['code'] ?? '');
        $sp = !empty($currency['space']) ? ' ' : '';
        return !empty($currency['symbol_before']) ? $sym.$sp.$n : $n.$sp.$sym;
    }

    // The record's page in the shop's backend.
    public static function adminLink($shop, $cms, $type, $id)
    {
        $ctrl = ['customer' => 'user', 'order' => 'order', 'product' => 'product'][$type] ?? null;
        if (!$ctrl || (int)$id <= 0) {
            return '';
        }
        $query = 'ctrl='.$ctrl.'&task=edit&cid[]='.(int)$id;
        if ($cms === 'wordpress') {
            return $shop['url'].'/wp-admin/admin.php?page=com_hikashop&'.$query;
        }
        return $shop['url'].'/administrator/index.php?option=com_hikashop&'.$query;
    }
}
