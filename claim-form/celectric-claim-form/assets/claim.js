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
		var km = 0;
		sheet.querySelectorAll('[data-cel-km]').forEach(function (input) {
			km += Math.round(num(input.value) * 10) / 10;
		});
		km = Math.round(km * 10) / 10;
		var vehicle = sheet.querySelector('[data-cel-vehicle]');
		var rate = num((cfg.vehicles || {})[vehicle && vehicle.value]);
		var net = Math.round(km * rate * 100) / 100;
		setText('[data-cel-km-total]', km.toLocaleString('en-MY', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' KM');
		setText('[data-cel-rate]', 'RM ' + rate.toFixed(2) + '/km');
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
		body.appendChild(row);
		var first = row.querySelector('input');
		if (first) { first.focus(); }
		if (row.scrollIntoView) { row.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
	}

	function onEdit(e) {
		// On phones only the first blank row is shown; filling it reveals the next one.
		var tr = e.target.closest && e.target.closest('tr.is-empty');
		if (tr && e.target.value) { tr.classList.remove('is-empty'); }
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
					? "Please fill in Employee's Name, Claim Period / Month and Vehicle Type."
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
