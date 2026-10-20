(function ($) {
	'use strict';

	// VIN decode button in the vehicle meta box.
	$('#nl-decode-vin').on('click', function () {
		var $btn = $(this), $out = $('.nl-vin-result'), vin = $('#nl_vin').val().trim();
		$out.removeClass('is-error').text('Decoding…');
		$btn.prop('disabled', true);
		$.post(nlAdmin.ajax, { action: 'nl_decode_vin', nonce: nlAdmin.nonce, vin: vin })
			.done(function (res) {
				if (!res.success) {
					$out.addClass('is-error').text(res.data.message);
					return;
				}
				var d = res.data, filled = [];
				['model', 'year', 'engine_cc', 'power_hp', 'seats', 'trim'].forEach(function (k) {
					var $f = $('#nl_' + k);
					if (d[k] && !$f.val()) { $f.val(d[k]); filled.push(k); }
				});
				// Taxonomy fields live in the classic tag boxes; add the term text there.
				[['make', 'vehicle_make'], ['body', 'vehicle_body'], ['fuel', 'vehicle_fuel'], ['transmission', 'vehicle_transmission'], ['drive', 'vehicle_drive']].forEach(function (pair) {
					var $tags = $('#tax-input-' + pair[1]);
					if (d[pair[0]] && $tags.length && !$tags.val()) {
						$tags.val(d[pair[0]]);
						filled.push(pair[0]);
						if (window.tagBox) { window.tagBox.quickClicks($('#' + pair[1])); }
					}
				});
				$out.text(d.year + ' ' + d.make + ' ' + d.model + (filled.length ? ' — filled: ' + filled.join(', ') : ' — nothing new to fill'));
			})
			.fail(function () { $out.addClass('is-error').text('Request failed'); })
			.always(function () { $btn.prop('disabled', false); });
	});

	// Gallery meta box.
	var $list = $('#nl-gallery .nl-gallery__list'), $input = $('#nl_gallery');
	function sync() {
		$input.val($list.children().map(function () { return $(this).data('id'); }).get().join(','));
	}
	if ($list.length) {
		$list.sortable({ update: sync });
		$list.on('click', '.nl-gallery__remove', function () { $(this).closest('li').remove(); sync(); });
		$('#nl-gallery-add').on('click', function (e) {
			e.preventDefault();
			var frame = wp.media({ title: 'Select photos', multiple: true, library: { type: 'image' } });
			frame.on('select', function () {
				frame.state().get('selection').each(function (att) {
					var a = att.toJSON(), url = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
					if (!$list.find('[data-id="' + a.id + '"]').length) {
						$list.append('<li data-id="' + a.id + '"><img src="' + url + '" alt=""><button type="button" class="nl-gallery__remove" aria-label="Remove">&times;</button></li>');
					}
				});
				sync();
			});
			frame.open();
		});
	}
})(jQuery);
