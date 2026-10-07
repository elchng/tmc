/* Claims → Settings: OneDrive folder/file browser for the Microsoft 365 section. */
(function () {
	'use strict';
	var cfg = window.CEL_OD || {};
	var $ = function (id) { return document.getElementById(id); };
	var panel = $('cel-od-panel');
	if (!panel) { return; }
	var list = $('cel-od-list'), msg = $('cel-od-msg'), pathEl = $('cel-od-path'), upBtn = $('cel-od-up');
	var state = { id: '', parent: null };

	function call(action, data) {
		var body = new URLSearchParams(Object.assign({ action: action, nonce: cfg.nonce }, data || {}));
		return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (!j || !j.success) { throw new Error((j && j.data && j.data.message) || 'Request failed'); }
				return j.data;
			});
	}
	function say(text, isError) {
		msg.textContent = text || '';
		msg.style.color = isError ? '#b32d2e' : '';
	}
	function busy(on) { panel.style.opacity = on ? '.6' : ''; panel.style.pointerEvents = on ? 'none' : ''; }

	function row(icon, label, onClick, hint) {
		var li = document.createElement('li');
		li.style.cssText = 'margin:0;border-bottom:1px solid #f0f0f1';
		var b = document.createElement('button');
		b.type = 'button';
		b.style.cssText = 'display:flex;gap:8px;align-items:center;width:100%;padding:8px 10px;border:0;background:none;text-align:left;cursor:pointer;font-size:13px';
		b.onmouseenter = function () { b.style.background = '#f0f6fc'; };
		b.onmouseleave = function () { b.style.background = 'none'; };
		var i = document.createElement('span'); i.className = 'dashicons ' + icon; i.setAttribute('aria-hidden', 'true');
		var t = document.createElement('span'); t.textContent = label; t.style.flex = '1';
		b.appendChild(i); b.appendChild(t);
		if (hint) { var h = document.createElement('span'); h.textContent = hint; h.style.color = '#2271b1'; b.appendChild(h); }
		b.addEventListener('click', onClick);
		li.appendChild(b);
		return li;
	}

	function open(folderId) {
		busy(true); say('Loading…');
		call('cel_claim_od_list', { folder: folderId || '' }).then(function (d) {
			state.id = d.id; state.parent = d.parent;
			pathEl.textContent = 'OneDrive' + (d.path ? ' / ' + d.path.split('/').join(' / ') : '');
			upBtn.disabled = d.parent === null;
			list.innerHTML = '';
			if (!d.items.length) {
				var li = document.createElement('li'); li.textContent = 'No folders or Excel files here.'; li.style.cssText = 'padding:10px;color:#646970';
				list.appendChild(li);
			}
			d.items.forEach(function (it) {
				list.appendChild(it.type === 'folder'
					? row('dashicons-category', it.name, function () { open(it.id); })
					: row('dashicons-media-spreadsheet', it.name, function () { choose(it.id); }, 'Use this file'));
			});
			say('');
		}).catch(function (e) { say(e.message, true); }).finally(function () { busy(false); });
	}

	function applyChoice(d) {
		$('cel-od-current').textContent = d.path;
		document.querySelectorAll('[data-cel-od-table]').forEach(function (sel) {
			var want = sel.id === 'cel_ms_table_travel' ? d.travel : d.mileage;
			sel.innerHTML = '';
			var names = d.tables.slice();
			if (names.indexOf(want) === -1) { names.unshift(want); }
			names.forEach(function (n) {
				var o = document.createElement('option');
				o.value = n; o.textContent = n + (d.tables.indexOf(n) === -1 ? ' (not in this file)' : '');
				o.selected = n === want;
				sel.appendChild(o);
			});
		});
		panel.hidden = true;
		say(d.warning ? 'Saved: ' + d.path + '. ' + d.warning : 'Saved: ' + d.path + ' — travel claims → table “' + d.travel + '”, mileage claims → “' + d.mileage + '”. Click “Send test rows” below to check.', !!d.warning);
	}

	function choose(itemId) {
		busy(true); say('Opening workbook…');
		call('cel_claim_od_select', { item: itemId }).then(applyChoice)
			.catch(function (e) { say(e.message, true); }).finally(function () { busy(false); });
	}

	$('cel-od-browse').addEventListener('click', function () { panel.hidden = false; open(''); });
	$('cel-od-close').addEventListener('click', function () { panel.hidden = true; say(''); });
	upBtn.addEventListener('click', function () { if (state.parent !== null) { open(state.parent); } });
	$('cel-od-create').addEventListener('click', function () {
		busy(true); say('Creating Claims_Register.xlsx…');
		call('cel_claim_od_create', { folder: state.id }).then(applyChoice)
			.catch(function (e) { say(e.message, true); }).finally(function () { busy(false); });
	});
})();
