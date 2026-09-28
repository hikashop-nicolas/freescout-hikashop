{{-- Divs and spans only: Bootstrap's popover sanitizer drops tables. --}}
@if (!empty($error))
    <div class="text-danger">{{ $error }}</div>
@else
    <div class="hikashop-order-lines">
        @foreach ($items as $item)
            <div class="hikashop-line">
                <span class="hikashop-qty">{{ $item['quantity'] }} ×</span>
                <span class="hikashop-name">{{ $item['name'] }}@if ($item['code'] !== '') <span class="text-help">{{ $item['code'] }}</span>@endif</span>
                <span class="hikashop-amount">{{ $item['amount'] }}</span>
            </div>
        @endforeach
        @if ($discount !== '')
            <div class="hikashop-line hikashop-sub"><span class="hikashop-name">{{ __('Discount') }}</span><span class="hikashop-amount">-{{ $discount }}</span></div>
        @endif
        @if ($shipping !== '')
            <div class="hikashop-line hikashop-sub"><span class="hikashop-name">{{ __('Shipping') }}@if ($shipping_method !== '') <span class="text-help">{{ $shipping_method }}</span>@endif</span><span class="hikashop-amount">{{ $shipping }}</span></div>
        @endif
        <div class="hikashop-line hikashop-total"><span class="hikashop-name">{{ __('Total') }}@if ($payment_method !== '') <span class="text-help">{{ $payment_method }}</span>@endif</span><span class="hikashop-amount">{{ $total }}</span></div>
    </div>
@endif
