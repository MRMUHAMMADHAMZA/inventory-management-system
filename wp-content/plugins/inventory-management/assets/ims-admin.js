/* Inventory Management System: admin screen interactions. */
(function () {
	'use strict';
	var $ = function (s, r) { return (r || document).querySelector(s); };
	var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

	/* Confirm destructive links. */
	$$('[data-ims-confirm]').forEach(function (a) {
		a.addEventListener('click', function (e) {
			if (!window.confirm(a.getAttribute('data-ims-confirm'))) { e.preventDefault(); }
		});
	});

	/* Product form: live margin. */
	var margin = $('[data-ims-margin]');
	if (margin) {
		var cost = $('#cost_price', margin), sale = $('#sale_price', margin), out = $('.ims-margin', margin);
		var calc = function () {
			var c = parseFloat(cost.value) || 0, s = parseFloat(sale.value) || 0;
			if (!s) { out.textContent = ''; return; }
			var profit = s - c, pct = (profit / s) * 100;
			out.innerHTML = 'Margin: <strong>' + profit.toFixed(2) + '</strong> per unit (' + pct.toFixed(1) + '%)';
		};
		cost.addEventListener('input', calc); sale.addEventListener('input', calc); calc();
	}

	/* Stock movement: live "after this movement" preview. */
	var form = $('[data-ims-movement]');
	if (form) {
		var product = $('#product_id', form), qty = $('#quantity', form);
		var current = $('[data-ims-current]', form), after = $('[data-ims-after]', form), qtyLabel = $('[data-ims-qty-label]', form);
		var update = function () {
			var opt = product.options[product.selectedIndex];
			var have = parseInt(opt ? opt.getAttribute('data-qty') : 0, 10) || 0;
			var type = ($('input[name=type]:checked', form) || {}).value || 'in';
			var n = parseInt(qty.value, 10) || 0;
			var result = type === 'in' ? have + n : type === 'out' ? have - n : (qty.value === '' ? have : n);
			qtyLabel.textContent = type === 'adjust' ? 'New counted quantity' : 'Quantity';
			current.textContent = have.toLocaleString();
			after.textContent = result.toLocaleString();
			after.classList.toggle('is-bad', result < 0);
		};
		product.addEventListener('change', update);
		qty.addEventListener('input', update);
		$$('input[name=type]', form).forEach(function (r) { r.addEventListener('change', update); });
		update();
	}
})();
