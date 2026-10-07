/* Celectric claim forms: live totals and "add row" buttons. Totals are recalculated on the server too. */
(function () {
	'use strict';

	var sheet = document.querySelector('[data-cel-sheet][data-cel-config]');
	if (!sheet) { return; }
	var cfg = {};
	try { cfg = JSON.parse(sheet.getAttribute('data-cel-config')) || {}; } catch (e) { /* keep defaults */ }
	var form = sheet.closest('form');
	var isMileage = cfg.form === 'mileage';

	function money(n) {
		return n.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function num(v) {
		var n = parseFloat(v);
		return isNaN(n) || n < 0 ? 0 : n;
	}
	function setText(sel, value) {
		sheet.querySelectorAll(sel).forEach(function (el) { el.textContent = value; });
	}

	function recalcTravel() {
		var subA = 0, subB = 0;
		sheet.querySelectorAll('[data-cel-rows="a"] tr').forEach(function (tr) {
			var out = tr.querySelector('[data-cel-out]');
			var meal = tr.querySelector('[data-cel-meal]');
			var total = num((cfg.outstation || {})[out && out.value]) + num((cfg.meal || {})[meal && meal.value]);
			var date = tr.querySelector('input[type="date"]');
			var details = tr.querySelector('input[type="text"]');
			var used = total > 0 || (date && date.value) || (details && details.value.trim());
			tr.querySelector('[data-cel-line]').textContent = used ? money(total) : '';
			subA += total;
		});
		sheet.querySelectorAll('[data-cel-amount]').forEach(function (input) {
			subB += Math.round(num(input.value) * 100) / 100;
		});
		setText('[data-cel-sub="a"], [data-cel-sum="a"]', money(subA));
		setText('[data-cel-sub="b"], [data-cel-sum="b"]', money(subB));
		setText('[data-cel-grand]', money(subA + subB));
		return subA + subB;
	}

	function recalcMileage() {
		// Each trip uses its own vehicle; payment is per vehicle (total km × rate).
		var rates = cfg.vehicles || {}, kmBy = {};
		Object.keys(rates).forEach(function (v) { kmBy[v] = 0; });
		sheet.querySelectorAll('[data-cel-rows="t"] tr').forEach(function (tr) {
			var veh = tr.querySelector('[data-cel-veh]'), kmIn = tr.querySelector('[data-cel-km]'), amt = tr.querySelector('[data-cel-amt]');
			var km = Math.round(num(kmIn && kmIn.value) * 10) / 10, v = veh ? veh.value : '';
			if (amt) { amt.textContent = km > 0 && v in rates ? money(Math.round(km * num(rates[v]) * 100) / 100) : ''; }
			if (v in kmBy) { kmBy[v] += km; }
		});
		var totalKm = 0, net = 0;
		Object.keys(kmBy).forEach(function (v) {
			var km = Math.round(kmBy[v] * 10) / 10, amount = Math.round(km * num(rates[v]) * 100) / 100;
			totalKm += km; net += amount;
			var row = sheet.querySelector('[data-cel-vrow="' + (window.CSS && CSS.escape ? CSS.escape(v) : v) + '"]');
			if (row) {
				row.querySelector('[data-cel-vkm]').textContent = km.toLocaleString('en-MY', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' KM';
				row.querySelector('[data-cel-vamt]').textContent = 'RM ' + money(amount);
			}
		});
		totalKm = Math.round(totalKm * 10) / 10;
		net = Math.round(net * 100) / 100;
		setText('[data-cel-km-total]', totalKm.toLocaleString('en-MY', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' KM');
		setText('[data-cel-net]', 'RM ' + money(net));
		return net;
	}

	var recalc = isMileage ? recalcMileage : recalcTravel;

	function addRow(section) {
		var body = sheet.querySelector('[data-cel-rows="' + section + '"]');
		var tpl = document.querySelector('[data-cel-tpl="' + section + '"]');
		if (!body || !tpl) { return; }
		var count = body.children.length;
		if (count >= (cfg.max || 40)) { window.alert('Maximum of ' + cfg.max + ' rows reached.'); return; }
		var row = tpl.content.firstElementChild.cloneNode(true);
		row.querySelector('.num').textContent = count + 1;
		if (isMileage && count % 2) { row.classList.add('stripe'); }
		row.querySelectorAll('[data-n]').forEach(function (el) {
			el.name = section + '[' + count + '][' + el.getAttribute('data-n') + ']';
		});
		// A new trip starts with the vehicle used on the row above.
		var prev = body.lastElementChild && body.lastElementChild.querySelector('[data-cel-veh]');
		var veh = row.querySelector('[data-cel-veh]');
		if (prev && veh) { veh.value = prev.value; }
		body.appendChild(row);
		var first = row.querySelector('input');
		if (first) { first.focus(); }
		if (row.scrollIntoView) { row.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
	}

	function onEdit(e) {
		// On phones only the first blank row is shown; filling it reveals the next one.
		var tr = e.target.closest && e.target.closest('tr.is-empty');
		if (tr && e.target.value && !e.target.hasAttribute('data-cel-veh')) { tr.classList.remove('is-empty'); }
		// Changing a trip's vehicle also pre-fills the blank trips below it.
		if (e.type === 'change' && e.target.hasAttribute && e.target.hasAttribute('data-cel-veh')) {
			var next = e.target.closest('tr').nextElementSibling;
			for (; next; next = next.nextElementSibling) {
				var s = next.querySelector('[data-cel-veh]');
				if (next.classList.contains('is-empty') && s) { s.value = e.target.value; }
			}
		}
		recalc();
	}
	sheet.addEventListener('input', onEdit);
	sheet.addEventListener('change', onEdit);
	document.querySelectorAll('[data-cel-add]').forEach(function (btn) {
		btn.addEventListener('click', function () { addRow(btn.getAttribute('data-cel-add')); });
	});

	if (form) {
		form.addEventListener('submit', function (e) {
			var missing = [];
			form.querySelectorAll('[required]').forEach(function (el) {
				if (!String(el.value).trim()) { missing.push(el); }
			});
			if (missing.length) {
				e.preventDefault();
				missing[0].focus();
				window.alert(isMileage
					? "Please fill in Employee's Name and Claim Period / Month."
					: 'Please fill in Claimant Name, Purpose of Travel and Department / Project.');
				return;
			}
			var total = recalc();
			if (!window.confirm('Submit this claim for RM ' + money(total) + '?\n\nA submitted claim cannot be edited.')) {
				e.preventDefault();
				return;
			}
			var btn = form.querySelector('[type="submit"]');
			btn.disabled = true;
			btn.textContent = 'Submitting…';
		});
	}

	recalc();
})();
