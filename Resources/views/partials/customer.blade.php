@if (!empty($error))
    <div class="text-danger">{{ __('The shop could not be read') }}: {{ $error }}</div>
@elseif (empty($customer))
    <div class="text-help">{{ __('No customer with this address in the shop.') }}</div>
@else
    <div class="hikashop-customer">
        <strong>
            @if ($customer['link'])<a href="{{ $customer['link'] }}" target="_blank" rel="noopener">{{ $customer['name'] ?: $customer['email'] }}</a>@else{{ $customer['name'] ?: $customer['email'] }}@endif
        </strong>
        @if ($customer['guest']) <span class="label label-default">{{ __('guest') }}</span>@endif
        <div class="text-help">
            @if ($customer['since']){{ __('Customer since :date', ['date' => $customer['since']]) }} · @endif
            {{ trans_choice(':count order|:count orders', $customer['order_count'], ['count' => $customer['order_count']]) }}
        </div>
    </div>

    @if (count($orders))
        <div class="hikashop-heading">{{ __('Latest orders') }}</div>
        <ul class="sidebar-block-list hikashop-list">
            @foreach ($orders as $order)
                <li>
                    <div>
                        <a href="{{ $order['link'] ?: '#' }}" @if ($order['link']) target="_blank" rel="noopener" @endif class="hikashop-order" data-order="{{ $order['id'] }}">{{ $order['number'] }}</a>
                        <span class="pull-right">{{ $order['total'] }}</span>
                    </div>
                    <div>
                        <small class="text-help">{{ $order['date'] }}</small>
                        <small class="pull-right hikashop-status" @if ($order['color']) style="color: {{ $order['color'] }}" @endif>{{ $order['status'] }}</small>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @foreach ($sections as $section)
        @if ($section['title'] !== '')<div class="hikashop-heading">{{ $section['title'] }}</div>@endif
        <ul class="sidebar-block-list hikashop-list">
            @foreach ($section['rows'] as $row)
                <li>
                    <div>
                        @if ($row['link'])<a href="{{ $row['link'] }}" target="_blank" rel="noopener">{{ $row['label'] }}</a>@else{{ $row['label'] }}@endif
                    </div>
                    @if ($row['value'] !== '')<div><small class="text-help">{{ $row['value'] }}</small></div>@endif
                    @if ($row['badge'])
                        <div><span class="label hikashop-tone-{{ $row['badge']['tone'] }}">{{ $row['badge']['text'] }}</span></div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endforeach
@endif
