<div class="conv-sidebar-block">
    <div class="panel-group accordion accordion-empty">
        <div class="panel panel-default hikashop-panel" id="hikashop-customer"
             data-url="{{ route('hikashop.ajax') }}" data-mailbox="{{ $mailbox_id }}" data-emails="{{ json_encode($emails) }}">
            <div class="panel-heading">
                <h4 class="panel-title">
                    <a data-toggle="collapse" href=".hikashop-collapse">HikaShop <b class="caret"></b></a>
                    <a href="#" class="hikashop-refresh pull-right" title="{{ __('Refresh') }}"><i class="glyphicon glyphicon-refresh"></i></a>
                </h4>
            </div>
            <div class="hikashop-collapse panel-collapse collapse in">
                <div class="panel-body hikashop-body">
                    <img src="{{ asset('img/loader-tiny.gif') }}" alt="" />
                </div>
            </div>
        </div>
    </div>
</div>
