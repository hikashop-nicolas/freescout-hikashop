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

	// An order's lines in a popover on its number, loaded the first time it is pointed at.
	var orders = {};
	function showOrder(panel, link) {
		var id = link.data('order');
		var open = function (html) {
			if (!link.is(':hover')) {
				return;
			}
			link.popover({html: true, trigger: 'manual', placement: 'left', container: 'body',
				template: '<div class="popover hikashop-popover" role="tooltip"><div class="arrow"></div><div class="popover-content"></div></div>',
				content: function () { return orders[id]; }});
			link.popover('show');
		};
		if (orders[id]) {
			open(orders[id]);
			return;
		}
		$.ajax({
			url: panel.data('url'),
			method: 'POST',
			data: {
				_token: $('meta[name="csrf-token"]').attr('content'),
				action: 'order',
				order_id: id,
				mailbox_id: panel.data('mailbox'),
				emails: panel.data('emails')
			},
			dataType: 'json'
		}).done(function (r) {
			if (r && r.status === 'success') {
				orders[id] = r.html;
				open(r.html);
			}
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
			orders = {};
			load(panel, true);
		});
		panel.on('mouseenter', '.hikashop-order', function () {
			showOrder(panel, $(this));
		});
		panel.on('mouseleave', '.hikashop-order', function () {
			$(this).popover('destroy');
		});
	});
})();
