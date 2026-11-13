(function ($) {
	var D = window.cpData || {};

	/* ---------- helpers ---------- */
	function ed() {
		return (window.wp && wp.data && wp.data.select && wp.data.select('core/editor')) || null;
	}
	function getTitle() {
		var e = ed();
		if (e && e.getEditedPostAttribute) { return e.getEditedPostAttribute('title') || ''; }
		return $('#title').val() || '';
	}
	function getContent() {
		var e = ed();
		if (e && e.getEditedPostContent) { return e.getEditedPostContent() || ''; }
		if (window.tinyMCE && tinyMCE.get('content') && !tinyMCE.get('content').isHidden()) {
			return tinyMCE.get('content').getContent();
		}
		return $('#content').val() || '';
	}
	function parse(html) {
		return new DOMParser().parseFromString(html || '', 'text/html');
	}
	function val(sel) { return ($(sel).val() || '').trim(); }

	/* ---------- media picker ---------- */
	$(document).on('click', '.cp-pick', function (e) {
		e.preventDefault();
		var target = $(this).data('target');
		var frame = wp.media({ title: 'Choose image', multiple: false, library: { type: 'image' } });
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			$(target).val(a.url).trigger('input');
		});
		frame.open();
	});

	/* ---------- meta box ---------- */
	var $box = $('.cp-box');
	if ($box.length) {
		$box.on('click', '.cp-tab', function () {
			var t = $(this).data('tab');
			$box.find('.cp-tab').removeClass('active');
			$(this).addClass('active');
			$box.find('.cp-pane').removeClass('active').filter('[data-pane="' + t + '"]').addClass('active');
			updatePreviews();
		});

		var imgTimer;
		function updatePreviews() {
			var title = val('#cp_title') || getTitle() || 'Post title';
			var desc = val('#cp_desc');
			var ogTitle = val('#cp_og_title') || title;
			var ogDesc = val('#cp_og_desc') || desc;
			var img = val('#cp_og_image') || D.thumb || D.defaultImage || '';

			$('.cp-serp-url').text(D.host + ' › …');
			$('.cp-serp-title').text(title);
			$('.cp-serp-desc').text(desc || 'No meta description – search engines will pick text from the page.');

			$('.cp-card-domain').text(D.host);
			$('.cp-card-title').text(ogTitle);
			$('.cp-card-desc').text(ogDesc);
			$('.cp-card-img').css('background-image', img ? 'url("' + img.replace(/"/g, '%22') + '")' : 'none');

			$('.cp-count').each(function () {
				var $c = $(this), len = ($('#' + $c.data('for')).val() || '').length;
				var ok = len >= $c.data('min') && len <= $c.data('max');
				$c.text(len + ' chars (ideal ' + $c.data('min') + '–' + $c.data('max') + ')').toggleClass('ok', ok).toggleClass('bad', len > 0 && !ok);
			});

			clearTimeout(imgTimer);
			var $note = $('.cp-img-note');
			if (!val('#cp_og_image')) {
				$note.text(img ? '' : 'No image set – shares will have no picture.');
				return;
			}
			imgTimer = setTimeout(function () {
				var im = new Image();
				im.onload = function () {
					var good = im.naturalWidth >= 1200 && im.naturalHeight >= 600;
					$note.text(im.naturalWidth + '×' + im.naturalHeight + (good ? ' ✓ good size' : ' – smaller than the recommended 1200×630'));
				};
				im.onerror = function () { $note.text('Image could not be loaded.'); };
				im.src = img;
			}, 400);
		}
		$box.on('input change', 'input, textarea', updatePreviews);
		$box.on('change', 'input[name="cp_platform"]', function () {
			$('#cp-card').attr('class', 'cp-card ' + this.value);
		});
		updatePreviews();
		setInterval(function () { if ($('.cp-pane[data-pane="seo"]').hasClass('active') || $('.cp-pane[data-pane="social"]').hasClass('active')) { updatePreviews(); } }, 3000);

		/* analysis */
		$('#cp-run-checks').on('click', function () {
			var kw = val('#cp_kw').toLowerCase();
			var title = val('#cp_title') || getTitle();
			var desc = val('#cp_desc');
			var doc = parse(getContent());
			var text = (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
			var words = text ? text.split(' ').length : 0;
			var first = text.split(' ').slice(0, 100).join(' ').toLowerCase();
			var imgs = doc.querySelectorAll('img'), noAlt = 0;
			imgs.forEach(function (i) { if (!(i.getAttribute('alt') || '').trim()) { noAlt++; } });
			var internal = 0;
			doc.querySelectorAll('a[href]').forEach(function (a) {
				var h = a.getAttribute('href') || '';
				if (h.charAt(0) === '#' || /^(mailto|tel):/.test(h)) { return; }
				if (h.charAt(0) === '/' || h.indexOf(D.host) !== -1) { internal++; }
			});
			var checks = [
				[kw !== '', 'Focus keyword is set'],
				[kw === '' || title.toLowerCase().indexOf(kw) !== -1, 'Focus keyword appears in the title'],
				[kw === '' || desc.toLowerCase().indexOf(kw) !== -1, 'Focus keyword appears in the meta description'],
				[kw === '' || first.indexOf(kw) !== -1, 'Focus keyword appears in the first 100 words'],
				[title.length >= 30 && title.length <= 60, 'Title length is 30–60 characters (now ' + title.length + ')'],
				[desc.length >= 70 && desc.length <= 160, 'Meta description is 70–160 characters (now ' + desc.length + ')'],
				[words >= 300, 'At least 300 words (now ' + words + ')'],
				[doc.querySelectorAll('h2,h3').length > 0, 'Has subheadings (H2/H3)'],
				[imgs.length > 0, 'Contains at least one image'],
				[noAlt === 0, 'All images have alt text' + (noAlt ? ' (' + noAlt + ' missing)' : '')],
				[internal > 0, 'Has at least one internal link']
			];
			var $ul = $('.cp-checks').empty();
			checks.forEach(function (c) {
				$('<li>').addClass(c[0] ? 'ok' : 'bad').text(c[1]).appendTo($ul);
			});
		});

		/* tags */
		var postId = $box.data('post-id');
		$('#cp-suggest-tags').on('click', function () {
			var $b = $(this).prop('disabled', true);
			$.post(D.ajax, { action: 'cp_suggest_tags', nonce: D.nonce, post_id: postId, title: getTitle(), content: getContent() })
				.done(function (r) {
					var $w = $('.cp-tags').empty();
					if (r.success && r.data.length) {
						r.data.forEach(function (t) {
							var $l = $('<label>');
							$('<input type="checkbox">').val(t).prop('checked', true).appendTo($l);
							$l.append(document.createTextNode(' ' + t)).appendTo($w);
						});
						$('#cp-apply-tags').show();
					} else {
						$w.text('Not enough text to suggest tags yet.');
					}
				})
				.always(function () { $b.prop('disabled', false); });
		});
		$('#cp-apply-tags').on('click', function () {
			var tags = $('.cp-tags input:checked').map(function () { return this.value; }).get();
			if (!tags.length) { return; }
			$.post(D.ajax, { action: 'cp_apply_tags', nonce: D.nonce, post_id: postId, tags: tags }).done(function (r) {
				$('.cp-tags-msg').text(r.success ? 'Tags added. Reload the editor to see them.' : 'Could not add tags (save the post first).');
			});
		});
	}

	/* ---------- ideas page ---------- */
	function render(list) {
		var $ul = $('#cp-ideas-out').empty();
		list.forEach(function (it) {
			var $li = $('<li>');
			$('<strong>').text(it.title).appendTo($li);
			$('<span class="cp-badge">').text(it.type).appendTo($li);
			if (it.reason) { $('<em>').text(it.reason).appendTo($li); }
			$('<button type="button" class="button button-small cp-draft">Create draft</button>').data('title', it.title).appendTo($li);
			$ul.append($li);
		});
	}
	function generate(mode, $btn) {
		var $msg = $('#cp-ideas-msg').text('Working…');
		$btn.prop('disabled', true);
		$.post(D.ajax, { action: 'cp_generate_ideas', nonce: D.nonce, mode: mode })
			.done(function (r) {
				if (r.success) { $msg.text(''); render(r.data); } else { $msg.text(r.data || 'Something went wrong.'); }
			})
			.fail(function () { $msg.text('Request failed.'); })
			.always(function () { $btn.prop('disabled', false); });
	}
	$('#cp-gen-basic').on('click', function () { generate('basic', $(this)); });
	$('#cp-gen-ai').on('click', function () { generate('ai', $(this)); });
	$(document).on('click', '.cp-draft', function () {
		var $b = $(this).prop('disabled', true).text('Creating…');
		$.post(D.ajax, { action: 'cp_create_draft', nonce: D.nonce, title: $b.data('title') }).done(function (r) {
			if (r.success) {
				$b.replaceWith($('<a class="button button-small" target="_blank" rel="noopener">Edit draft</a>').attr('href', r.data.edit));
			} else {
				$b.prop('disabled', false).text('Create draft');
			}
		});
	});
})(jQuery);
