(function(){
	var cfg  = window.rnrdAuthorProfile || {};
	var i18n = window.rnrdI18n.bind( cfg.i18n || {} );
	var t    = i18n.t;

	// Repeater add/remove (vanilla, no jQuery).
	function syncHidden(repeater){
		var key = repeater.getAttribute('data-repeater');
		var fields = repeater.getAttribute('data-fields').split(',');
		var rows = repeater.querySelectorAll('.rnrd-repeater-row');
		var data = [];
		for (var i = 0; i < rows.length; i++) {
			var row = {};
			var has = false;
			for (var j = 0; j < fields.length; j++) {
				var input = rows[i].querySelector('[data-field="' + fields[j] + '"]');
				if (input) {
					row[fields[j]] = input.value;
					if (input.value) has = true;
				}
			}
			if (has) data.push(row);
		}
		var hidden = document.querySelector('input[name="rnrd_author_' + key + '"]');
		if (hidden) hidden.value = JSON.stringify(data);
	}

	function makeRow(fields, values){
		var row = document.createElement('div');
		row.className = 'rnrd-repeater-row';
		row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;align-items:center;';
		for (var i = 0; i < fields.length; i++) {
			var f = fields[i].trim();
			var input = document.createElement('input');
			input.type = 'text';
			input.setAttribute('data-field', f);
			input.placeholder = f.charAt(0).toUpperCase() + f.slice(1);
			input.value = (values && values[f]) || '';
			input.style.flex = '1';
			row.appendChild(input);
		}
		var del = document.createElement('button');
		del.type = 'button';
		del.className = 'button';
		del.textContent = '×';
		del.style.cssText = 'min-width:32px;';
		del.addEventListener('click', function(){
			// Capture the container first: after removeChild() row.parentNode is null, and the
			// old fallback synced whichever repeater came first on the page instead.
			var parent = row.parentNode;
			if (!parent) return;
			parent.removeChild(row);
			syncHidden(parent);
		});
		row.appendChild(del);
		return row;
	}

	document.querySelectorAll('.rnrd-repeater-add').forEach(function(btn){
		btn.addEventListener('click', function(){
			var key = btn.getAttribute('data-target');
			var repeater = document.querySelector('.rnrd-repeater[data-repeater="' + key + '"]');
			if (!repeater) return;
			var fields = repeater.getAttribute('data-fields').split(',');
			var row = makeRow(fields, {});
			repeater.appendChild(row);
			row.querySelectorAll('input').forEach(function(inp){
				inp.addEventListener('input', function(){ syncHidden(repeater); });
			});
		});
	});

	document.querySelectorAll('.rnrd-repeater').forEach(function(repeater){
		repeater.querySelectorAll('input').forEach(function(inp){
			inp.addEventListener('input', function(){ syncHidden(repeater); });
		});
		repeater.querySelectorAll('.rnrd-repeater-row-remove').forEach(function(btn){
			btn.addEventListener('click', function(){
				btn.closest('.rnrd-repeater-row').remove();
				syncHidden(repeater);
			});
		});
	});

	// Media picker.
	document.querySelectorAll('.rnrd-media-picker').forEach(function(btn){
		btn.addEventListener('click', function(e){
			e.preventDefault();
			if (typeof wp === 'undefined' || !wp.media) return;
			var frame = wp.media({ title: t('selectHeadshot', 'Select Headshot'), button: { text: t('useThisImage', 'Use this image') }, multiple: false });
			frame.on('select', function(){
				var attachment = frame.state().get('selection').first().toJSON();
				var target = document.getElementById(btn.getAttribute('data-target'));
				if (target) target.value = attachment.url;
			});
			frame.open();
		});
	});
})();
