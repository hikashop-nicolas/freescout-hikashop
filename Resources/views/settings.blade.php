<form class="form-horizontal margin-top margin-bottom" method="POST" action="">
    {{ csrf_field() }}

    <p class="margin-bottom">
        {{ __('Shows the customer of each conversation as your HikaShop shop knows them: their orders, their downloads, and what your extensions add. It uses the connector API of HikaShop Business, the one the HikaShop app uses, so nothing needs installing on the shop.') }}
    </p>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Shop address') }}</label>
        <div class="col-sm-6">
            <input type="url" class="form-control input-sized-lg" name="settings[hikashop.shop_url]" value="{{ old('settings') ? old('settings')['hikashop.shop_url'] : $settings['hikashop.shop_url'] }}" placeholder="https://www.example.com">
            <p class="form-help">{{ __('The address of the site the shop runs on. Leave empty to disconnect.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Pairing code') }}</label>
        <div class="col-sm-6">
            <input type="text" class="form-control input-sized" name="hikashop_pairing_code" value="" autocomplete="off" placeholder="XXX-XXX-XXX">
            <p class="form-help">
                @if (\Option::get('hikashop.token'))
                    <span class="text-success">{{ __('Connected.') }}</span>
                    {{ __('Enter a new code only to pair again.') }}
                @else
                    {{ __('In the backend of the shop, HikaShop > System > App Devices, create a pairing code for a user with read access, and enter it here within five minutes. It is used once.') }}
                @endif
            </p>
        </div>
    </div>

    <div class="form-group margin-top margin-bottom">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </div>
</form>
