<?php

namespace Modules\HikaShop\Http\Controllers;

use App\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\HikaShop\Services\Shop;

class HikaShopController extends Controller
{
    // The sidebar's content for a conversation's customer.
    public function ajax(Request $request)
    {
        $mailbox = Mailbox::find((int)$request->mailbox_id);
        if (!$mailbox || !auth()->user()->can('view', $mailbox)) {
            return response()->json(['status' => 'error', 'msg' => __('Not allowed')]);
        }
        $shop = Shop::forMailbox($mailbox);
        if (!$shop) {
            return response()->json(['status' => 'error', 'msg' => __('No shop is connected')]);
        }
        $emails = array_values(array_filter((array)$request->emails, function ($e) {
            return filter_var($e, FILTER_VALIDATE_EMAIL);
        }));

        // The lines of one order, for the popover on its number.
        if ($request->action === 'order') {
            $order = Shop::orderView($shop, (int)$request->order_id, $emails, (bool)$request->refresh);
            return response()->json([
                'status' => 'success',
                'html'   => \View::make('hikashop::partials/order', $order)->render(),
            ]);
        }

        $view = Shop::customerView($shop, $emails, (bool)$request->refresh);

        return response()->json([
            'status' => 'success',
            'html'   => \View::make('hikashop::partials/customer', $view)->render(),
        ]);
    }

    public function mailboxSettings($id)
    {
        $mailbox = Mailbox::findOrFail($id);
        $settings = Shop::mailboxSettings($mailbox);

        return view('hikashop::mailbox_settings', [
            'mailbox'   => $mailbox,
            'shop_url'  => $settings['shop_url'] ?? '',
            'connected' => !empty($settings['token']),
        ]);
    }

    public function mailboxSettingsSave($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);
        $settings = Shop::mailboxSettings($mailbox);
        $typed = trim((string)$request->shop_url);
        $url = Shop::cleanUrl($typed);
        $code = trim((string)$request->pairing_code);

        if ($typed !== '' && $url === '') {
            \Session::flash('flash_error', __('This shop address cannot be used: it must start with http:// or https:// and be reachable on the internet.'));
            return redirect()->route('mailboxes.hikashop', ['id' => $id]);
        }
        if ($url === '') {
            $settings = [];
        } elseif ($code !== '') {
            $result = Shop::connect($url, $code);
            if (isset($result['error'])) {
                \Session::flash('flash_error', __('The shop refused the pairing code').': '.$result['error']);
                return redirect()->route('mailboxes.hikashop', ['id' => $id]);
            }
            $settings = ['shop_url' => $url, 'token' => encrypt($result['token'])];
            \Session::flash('flash_success', __('Connected to :site.', ['site' => $result['site']['site_name'] ?? $url]));
        } elseif (($settings['shop_url'] ?? '') !== $url) {
            // Another shop needs its own pairing.
            $settings = ['shop_url' => $url];
        }

        $mailbox->hikashop = $settings ? json_encode($settings) : null;
        $mailbox->save();
        if (!\Session::has('flash_success')) {
            \Session::flash('flash_success_floating', __('Settings updated'));
        }
        return redirect()->route('mailboxes.hikashop', ['id' => $id]);
    }
}
