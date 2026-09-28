/**
 * HikaShop module: fills the conversation sidebar block over AJAX, so a slow
 * or unreachable shop never delays the conversation page.
 */
(function () {
	function load(panel, refresh) {
		var body = panel.find('.hikashop-body');
		body.addClass('hikashop-loading');
		$.ajax({
			url: panel.data('url'),
			method: 'POST',
			data: {
				_token: $('meta[name="csrf-token"]').attr('content'),
				mailbox_id: panel.data('mailbox'),
				emails: panel.data('emails'),
				refresh: refresh ? 1 : 0
			},
			dataType: 'json'
		}).done(function (r) {
			body.html(r && r.status === 'success' ? r.html : $('<div class="text-danger">').text((r && r.msg) || 'Error'));
		}).fail(function () {
			body.html($('<div class="text-danger">').text('Error'));
		}).always(function () {
			body.removeClass('hikashop-loading');
		});
	}

	$(document).ready(function () {
		var panel = $('#hikashop-customer');
		if (!panel.length) {
			return;
		}
		load(panel, false);
		panel.on('click', '.hikashop-refresh', function (e) {
			e.preventDefault();
			load(panel, true);
		});
	});
})();
