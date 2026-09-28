<?php

namespace Modules\HikaShop\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\HikaShop\Services\Shop;

define('HIKASHOP_MODULE', 'hikashop');

class HikaShopServiceProvider extends ServiceProvider
{
    protected $defer = false;

    public function boot()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'hikashop');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'hikashop');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->hooks();
    }

    public function register()
    {
    }

    public function hooks()
    {
        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(HIKASHOP_MODULE).'/css/module.css';
            return $styles;
        });

        \Eventy::addFilter('javascripts', function ($javascripts) {
            $javascripts[] = \Module::getPublicPath(HIKASHOP_MODULE).'/js/module.js';
            return $javascripts;
        });

        // A shop of its own for a mailbox, from the mailbox's settings menu.
        \Eventy::addAction('mailboxes.settings.menu', function ($mailbox) {
            if (auth()->user()->isAdmin()) {
                echo \View::make('hikashop::partials/settings_menu', ['mailbox' => $mailbox])->render();
            }
        }, 35);

        // The global shop: Manage > Settings > HikaShop.
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections[HIKASHOP_MODULE] = ['title' => 'HikaShop', 'icon' => 'shopping-cart', 'order' => 560];
            return $sections;
        }, 36);

        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section != HIKASHOP_MODULE) {
                return $params;
            }
            $params['settings'] = ['hikashop.shop_url' => []];
            return $params;
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section == HIKASHOP_MODULE ? 'hikashop::settings' : $view;
        }, 20, 2);

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section != HIKASHOP_MODULE) {
                return $settings;
            }
            $settings['hikashop.shop_url'] = \Option::get('hikashop.shop_url');
            return $settings;
        }, 20, 2);

        // The token never goes through the form: a pairing code comes in, is
        // exchanged with the shop, and the token is stored encrypted.
        \Eventy::addFilter('settings.before_save', function ($request, $section, $settings) {
            if ($section != HIKASHOP_MODULE) {
                return $request;
            }
            $values = $request->settings ?: [];
            $typed = trim((string)($values['hikashop.shop_url'] ?? ''));
            $url = Shop::cleanUrl($typed);
            if ($typed !== '' && $url === '') {
                // Refused (not http(s), or a local or private address): keep what was there.
                $request->session()->flash('flash_error', __('This shop address cannot be used: it must start with http:// or https:// and be reachable on the internet.'));
                $values['hikashop.shop_url'] = \Option::get('hikashop.shop_url');
                $request->merge(['settings' => $values]);
                return $request;
            }
            $values['hikashop.shop_url'] = $url;
            $request->merge(['settings' => $values]);

            if ($url === '') {
                \Option::remove('hikashop.token');
                return $request;
            }
            $code = trim((string)$request->input('hikashop_pairing_code'));
            if ($code === '') {
                if ($url !== \Option::get('hikashop.shop_url')) {
                    \Option::remove('hikashop.token');
                }
                return $request;
            }
            $result = Shop::connect($url, $code);
            if (isset($result['error'])) {
                $request->session()->flash('flash_error', __('The shop refused the pairing code').': '.$result['error']);
            } else {
                \Option::set('hikashop.token', encrypt($result['token']));
                $request->session()->flash('flash_success', __('Connected to :site.', ['site' => $result['site']['site_name'] ?? $url]));
            }
            return $request;
        }, 20, 3);

        // The sidebar block, filled over AJAX so a slow shop never slows the page.
        \Eventy::addAction('conversation.after_prev_convs', function ($customer, $conversation, $mailbox) {
            if (!$customer || !Shop::forMailbox($mailbox)) {
                return;
            }
            $emails = $customer->emails_cached->pluck('email')->all();
            if (!count($emails)) {
                return;
            }
            echo \View::make('hikashop::partials/sidebar', [
                'emails'     => $emails,
                'mailbox_id' => $mailbox->id,
            ])->render();
        }, 13, 3);
    }
}
