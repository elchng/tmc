/* Celectric claim forms: live totals and "add row" buttons. Totals are recalculated on the server too.
 *
 * Built to keep working when:
 *  - the form appears more than once on a page (e.g. Elementor desktop + mobile sections),
 *  - the form is added to the page later (tabs, popups, page builders),
 *  - optimisation plugins defer or delay scripts.
 * All events are handled at document level, and each form is calculated on its own.
 */
(function () {
	'use strict';

	function money(n) {
		return (Math.round(n * 100) / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}
	function km1(n) {
		return (Math.round(n * 10) / 10).toFixed(1).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}
	function num(v) {
		var n = parseFloat(String(v === undefined || v === null ? '' : v).replace(/,/g, ''));
		return isNaN(n) || n < 0 ? 0 : n;
	}
	function closest(el, sel) {
		while (el && el.nodeType === 1) {
			if (el.matches ? el.matches(sel) : el.msMatchesSelector(sel)) { return el; }
			el = el.parentNode;
		}
		return null;
	}
	function each(list, fn) { Array.prototype.forEach.call(list, fn); }
	function config(sheet) {
		if (!sheet.__celCfg) {
			try { sheet.__celCfg = JSON.parse(sheet.getAttribute('data-cel-config')) || {}; } catch (e) { sheet.__celCfg = {}; }
		}
		return sheet.__celCfg;
	}
	function setText(sheet, sel, value) {
		each(sheet.querySelectorAll(sel), function (el) { el.textContent = value; });
	}
	/** The sheet that belongs to an element (inside the sheet, or anywhere in its form). */
	function sheetFor(el) {
		var s = closest(el, '[data-cel-sheet][data-cel-config]');
		if (s) { return s; }
		var form = closest(el, 'form');
		return form ? form.querySelector('[data-cel-sheet][data-cel-config]') : null;
	}

	function recalcTravel(sheet, cfg) {
		var subA = 0, subB = 0;
		each(sheet.querySelectorAll('[data-cel-rows="a"] tr'), function (tr) {
			var out = tr.querySelector('[data-cel-out]');
			var meal = tr.querySelector('[data-cel-meal]');
			var total = num((cfg.outstation || {})[out ? out.value : '']) + num((cfg.meal || {})[meal ? meal.value : '']);
			var date = tr.querySelector('input[type="date"]');
			var details = tr.querySelector('input[type="text"]');
			var used = total > 0 || (date && date.value) || (details && details.value.trim());
			var cell = tr.querySelector('[data-cel-line]');
			if (cell) { cell.textContent = used ? money(total) : ''; }
			subA += total;
		});
		each(sheet.querySelectorAll('[data-cel-amount]'), function (input) {
			subB += Math.round(num(input.value) * 100) / 100;
		});
		setText(sheet, '[data-cel-sub="a"], [data-cel-sum="a"]', money(subA));
		setText(sheet, '[data-cel-sub="b"], [data-cel-sum="b"]', money(subB));
		setText(sheet, '[data-cel-grand]', money(subA + subB));
		return subA + subB;
	}

	function recalcMileage(sheet, cfg) {
		// Each trip uses its own vehicle; payment is per vehicle (total km × rate).
		var rates = cfg.vehicles || {}, kmBy = {}, names = [];
		for (var k in rates) { if (Object.prototype.hasOwnProperty.call(rates, k)) { kmBy[k] = 0; names.push(k); } }
		each(sheet.querySelectorAll('[data-cel-rows="t"] tr'), function (tr) {
			var veh = tr.querySelector('[data-cel-veh]'), kmIn = tr.querySelector('[data-cel-km]'), amt = tr.querySelector('[data-cel-amt]');
			var km = Math.round(num(kmIn ? kmIn.value : 0) * 10) / 10, v = veh ? veh.value : '';
			var known = Object.prototype.hasOwnProperty.call(rates, v);
			if (amt) { amt.textContent = km > 0 && known ? money(km * num(rates[v])) : ''; }
			if (known) { kmBy[v] += km; }
		});
		var totalKm = 0, net = 0;
		each(sheet.querySelectorAll('[data-cel-vrow]'), function (row) {
			var v = row.getAttribute('data-cel-vrow');
			var km = Math.round((kmBy[v] || 0) * 10) / 10, amount = Math.round(km * num(rates[v]) * 100) / 100;
			var kmCell = row.querySelector('[data-cel-vkm]'), amtCell = row.querySelector('[data-cel-vamt]');
			if (kmCell) { kmCell.textContent = km1(km) + ' KM'; }
			if (amtCell) { amtCell.textContent = 'RM ' + money(amount); }
		});
		names.forEach(function (v) {
			var km = Math.round(kmBy[v] * 10) / 10;
			totalKm += km;
			net += Math.round(km * num(rates[v]) * 100) / 100;
		});
		setText(sheet, '[data-cel-km-total]', km1(totalKm) + ' KM');
		setText(sheet, '[data-cel-net]', 'RM ' + money(net));
		return Math.round(net * 100) / 100;
	}

	function recalc(sheet) {
		if (!sheet) { return 0; }
		var cfg = config(sheet);
		try {
			return cfg.form === 'mileage' ? recalcMileage(sheet, cfg) : recalcTravel(sheet, cfg);
		} catch (err) {
			if (window.console) { window.console.error('Celectric claim form:', err); }
			return 0;
		}
	}

	function addRow(btn) {
		var form = closest(btn, 'form');
		var sheet = sheetFor(btn);
		if (!form || !sheet) { return; }
		var cfg = config(sheet), section = btn.getAttribute('data-cel-add');
		var body = sheet.querySelector('[data-cel-rows="' + section + '"]');
		var tpl = form.querySelector('template[data-cel-tpl="' + section + '"]');
		if (!body || !tpl || !tpl.content) { return; }
		var count = body.children.length;
		if (count >= (cfg.max || 40)) { window.alert('Maximum of ' + (cfg.max || 40) + ' rows reached.'); return; }
		var row = tpl.content.firstElementChild.cloneNode(true);
		var numCell = row.querySelector('.num');
		if (numCell) { numCell.textContent = count + 1; }
		if (cfg.form === 'mileage' && count % 2) { row.classList.add('stripe'); }
		each(row.querySelectorAll('[data-n]'), function (el) {
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
		recalc(sheet);
	}

	function onEdit(e) {
		var t = e.target;
		if (!t || !t.getAttribute) { return; }
		var sheet = closest(t, '[data-cel-sheet][data-cel-config]');
		if (!sheet) { return; }
		var isVeh = t.hasAttribute('data-cel-veh');
		// On phones only the first blank row is shown; filling it reveals the next one.
		var tr = closest(t, 'tr.is-empty');
		if (tr && t.value && !isVeh) { tr.classList.remove('is-empty'); }
		// Changing a trip's vehicle also pre-fills the blank trips below it.
		if (isVeh && e.type === 'change') {
			for (var next = closest(t, 'tr').nextElementSibling; next; next = next.nextElementSibling) {
				var s = next.querySelector('[data-cel-veh]');
				if (s && next.classList.contains('is-empty')) { s.value = t.value; }
			}
		}
		recalc(sheet);
	}

	function onClick(e) {
		var btn = e.target && closest(e.target, '[data-cel-add]');
		if (btn) { e.preventDefault(); addRow(btn); }
	}

	function onSubmit(e) {
		var form = e.target;
		if (!form || !form.querySelector) { return; }
		var sheet = form.querySelector('[data-cel-sheet][data-cel-config]');
		if (!sheet) { return; }
		var isMileage = config(sheet).form === 'mileage';
		var missing = [];
		each(form.querySelectorAll('[required]'), function (el) {
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
		var total = recalc(sheet);
		if (!window.confirm('Submit this claim for RM ' + money(total) + '?\n\nA submitted claim cannot be edited.')) {
			e.preventDefault();
			return;
		}
		var btn = form.querySelector('[type="submit"]');
		if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }
	}

	function recalcAll() {
		each(document.querySelectorAll('[data-cel-sheet][data-cel-config]'), recalc);
	}

	if (!window.__celClaimBound) {
		window.__celClaimBound = true;
		document.addEventListener('input', onEdit, true);
		document.addEventListener('change', onEdit, true);
		document.addEventListener('click', onClick, false);
		document.addEventListener('submit', onSubmit, false);
		// Fill in totals for forms already on the page, and for any added later.
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', recalcAll);
		}
		window.addEventListener('load', recalcAll);
		if (window.jQuery) {
			window.jQuery(window).on('elementor/frontend/init', recalcAll);
		}
	}
	recalcAll();
	window.celClaimRecalc = recalcAll;
})();
