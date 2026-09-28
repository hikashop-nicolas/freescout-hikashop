@extends('layouts.app')

@section('title_full', 'HikaShop - '.$mailbox->name)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="section-heading">HikaShop</div>

    @include('partials/flash_messages')

    <div class="row-container">
        <div class="row">
            <div class="col-xs-12">
                <form class="form-horizontal margin-top margin-bottom" method="POST" action="">
                    {{ csrf_field() }}

                    <p class="margin-bottom">
                        {{ __('A shop for this mailbox only. Leave the address empty to use the shop of Manage > Settings > HikaShop.') }}
                    </p>

                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('Shop address') }}</label>
                        <div class="col-sm-6">
                            <input type="url" class="form-control input-sized-lg" name="shop_url" value="{{ old('shop_url', $shop_url) }}" placeholder="https://www.example.com">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('Pairing code') }}</label>
                        <div class="col-sm-6">
                            <input type="text" class="form-control input-sized" name="pairing_code" value="" autocomplete="off" placeholder="XXX-XXX-XXX">
                            <p class="form-help">
                                @if ($connected)
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
            </div>
        </div>
    </div>
@endsection
