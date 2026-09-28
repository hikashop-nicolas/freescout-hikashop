<li @if (Route::is('mailboxes.hikashop'))class="active"@endif><a href="{{ route('mailboxes.hikashop', ['id' => $mailbox->id]) }}"><i class="glyphicon glyphicon-shopping-cart"></i> HikaShop</a></li>
