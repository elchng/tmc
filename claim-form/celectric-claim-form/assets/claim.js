/* Celectric claim form: live totals and "add row" buttons. Totals are recalculated on the server too. */
(function () {
	'use strict';

	var sheet = document.querySelector('[data-cel-sheet]');
	if (!sheet) { return; }
	var cfg = { outstation: {}, meal: {}, max: 40 };
	try { cfg = JSON.parse(sheet.getAttribute('data-cel-config')) || cfg; } catch (e) { /* keep defaults */ }
	var form = sheet.closest('form');

	function money(n) {
		return n.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function rate(table, key) { return Number(table[key] || 0); }
	function setText(sel, value) {
		sheet.querySelectorAll(sel).forEach(function (el) { el.textContent = value; });
	}

	function recalc() {
		var subA = 0, subB = 0;

		sheet.querySelectorAll('[data-cel-rows="a"] tr').forEach(function (tr) {
			var out = tr.querySelector('[data-cel-out]');
			var meal = tr.querySelector('[data-cel-meal]');
			var total = rate(cfg.outstation, out && out.value) + rate(cfg.meal, meal && meal.value);
			var date = tr.querySelector('input[type="date"]');
			var details = tr.querySelector('input[type="text"]');
			var used = total > 0 || (date && date.value) || (details && details.value.trim());
			tr.querySelector('[data-cel-line]').textContent = used ? money(total) : '';
			subA += total;
		});

		sheet.querySelectorAll('[data-cel-amount]').forEach(function (input) {
			var v = parseFloat(input.value);
			if (!isNaN(v) && v > 0) { subB += Math.round(v * 100) / 100; }
		});

		setText('[data-cel-sub="a"], [data-cel-sum="a"]', money(subA));
		setText('[data-cel-sub="b"], [data-cel-sum="b"]', money(subB));
		setText('[data-cel-grand]', money(subA + subB));
		return subA + subB;
	}

	function addRow(section) {
		var body = sheet.querySelector('[data-cel-rows="' + section + '"]');
		var tpl = document.querySelector('[data-cel-tpl="' + section + '"]');
		var count = body.children.length;
		if (count >= cfg.max) { window.alert('Maximum of ' + cfg.max + ' rows reached.'); return; }
		var row = tpl.content.firstElementChild.cloneNode(true);
		row.querySelector('.num').textContent = count + 1;
		row.querySelectorAll('[data-n]').forEach(function (el) {
			el.name = section + '[' + count + '][' + el.getAttribute('data-n') + ']';
		});
		body.appendChild(row);
		var first = row.querySelector('input');
		if (first) { first.focus(); }
	}

	sheet.addEventListener('input', recalc);
	sheet.addEventListener('change', recalc);
	document.querySelectorAll('[data-cel-add]').forEach(function (btn) {
		btn.addEventListener('click', function () { addRow(btn.getAttribute('data-cel-add')); });
	});

	if (form) {
		form.addEventListener('submit', function (e) {
			var missing = [];
			form.querySelectorAll('[required]').forEach(function (el) {
				if (!el.value.trim()) { missing.push(el); }
			});
			if (missing.length) {
				e.preventDefault();
				missing[0].focus();
				window.alert('Please fill in Claimant Name, Purpose of Travel and Department / Project.');
				return;
			}
			var total = recalc();
			if (!window.confirm('Submit this claim for RM ' + money(total) + '?\n\nA submitted claim cannot be edited.')) {
				e.preventDefault();
				return;
			}
			form.querySelector('[type="submit"]').disabled = true;
		});
	}

	recalc();
})();
