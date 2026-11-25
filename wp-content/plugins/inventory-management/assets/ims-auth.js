/* Inventory Management System: account page interactions. */
(function () {
	'use strict';

	var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

	/* Show / hide password */
	$$('.ims-pw-toggle').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var input = document.getElementById(btn.getAttribute('aria-controls'));
			if (!input) return;
			var show = input.type === 'password';
			input.type = show ? 'text' : 'password';
			btn.classList.toggle('is-visible', show);
			btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
			input.focus();
		});
	});

	/* Password strength meter */
	var LABELS = ['Too weak', 'Weak', 'Fair', 'Good', 'Strong'];
	function score(v) {
		if (!v) return -1;
		var s = 0;
		if (v.length >= 8) s++;
		if (v.length >= 12) s++;
		if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
		if (/\d/.test(v)) s++;
		if (/[^A-Za-z0-9]/.test(v)) s++;
		if (v.length < 8 || !/[A-Za-z]/.test(v) || !/\d/.test(v)) s = Math.min(s, 1);
		return Math.min(s, 4);
	}
	$$('input[data-strength]').forEach(function (input) {
		var wrap = input.closest('.ims-field').querySelector('.ims-strength');
		if (!wrap) return;
		var text = wrap.querySelector('.ims-strength-text');
		var hint = text.textContent;
		input.addEventListener('input', function () {
			var s = score(input.value);
			wrap.setAttribute('data-score', s);
			text.textContent = s < 0 ? hint : LABELS[s] + (s < 3 ? '. ' + hint : '');
		});
	});

	/* Confirm-password match: positive feedback only (a mismatch is shown as a field error) */
	$$('input[data-match]').forEach(function (input) {
		var other = document.getElementById(input.getAttribute('data-match'));
		var out = input.closest('.ims-field').querySelector('.ims-match-text');
		if (!other || !out) return;
		function check() {
			var ok = input.value && input.value === other.value;
			out.textContent = ok ? 'Passwords match' : '';
			out.className = 'ims-hint ims-match-text' + (ok ? ' is-ok' : '');
		}
		input.addEventListener('input', check);
		other.addEventListener('input', check);
	});

	/* ---------- Field validation (mirrors the server-side rules) ---------- */
	var RULES = {
		email: /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/,
		username: /^[A-Za-z0-9 _.\-@]{3,60}$/,
		phone: /^\+?[0-9\s\-().]{7,20}$/
	};
	var VALIDATABLE = '[data-required],[data-email],[data-username],[data-phone],[data-strength],[data-match],[data-required-if-changed]';

	function message(el) {
		var d = el.dataset;
		if (el.matches('[data-otp]')) {
			var n = $$('input', el).filter(function (i) { return /^\d$/.test(i.value); }).length;
			if (n === 0) return d.required;
			return n < 6 ? d.incomplete : '';
		}
		if (el.type === 'checkbox') {
			return (d.required !== undefined && !el.checked) ? d.required : '';
		}
		var v = el.type === 'password' ? el.value : el.value.trim();
		if (!v) {
			if (d.required) return d.required;
			if (d.requiredIfChanged) {
				var src = document.getElementById(d.requiredIfChanged);
				if (src && src.value.trim().toLowerCase() !== (src.dataset.original || '').toLowerCase()) return d.requiredMsg;
			}
			return '';
		}
		if (d.email !== undefined && !RULES.email.test(v)) return d.email || 'Please enter a valid email address.';
		if (d.username !== undefined && !RULES.username.test(v)) return d.username;
		if (d.phone !== undefined && !RULES.phone.test(v)) return d.phone;
		if (d.strength !== undefined) {
			if (v.length < 8) return 'Password must be at least 8 characters long.';
			if (!/[A-Za-z]/.test(v) || !/\d/.test(v)) return 'Password must include at least one letter and one number.';
		}
		if (d.match) {
			var other = document.getElementById(d.match);
			if (other && v !== other.value) return 'Passwords do not match.';
		}
		return '';
	}

	function show(el, msg) {
		var field = el.closest('.ims-field');
		if (!field) return;
		var slot = field.querySelector('.ims-field-error');
		if (slot) {
			slot.querySelector('span').textContent = msg || '';
			slot.hidden = !msg;
		}
		field.classList.toggle('has-error', !!msg);
		if (msg) el.setAttribute('aria-invalid', 'true'); else el.removeAttribute('aria-invalid');
	}

	function validate(el) { var m = message(el); show(el, m); return !m; }
	function hasError(el) { return el.closest('.ims-field').classList.contains('has-error'); }

	$$('form.ims-form').forEach(function (form) {
		var fields = $$(VALIDATABLE, form);
		if (!fields.length) return;

		fields.forEach(function (el) {
			var isGroup = el.matches('[data-otp]');
			var touched = false;
			// Validate when leaving a field (once something was typed); re-check live while an error is showing.
			el.addEventListener(isGroup ? 'focusout' : 'blur', function (e) {
				if (isGroup && el.contains(e.relatedTarget)) return;
				if (touched || (el.value && el.value.length)) { touched = true; validate(el); }
			});
			el.addEventListener(el.type === 'checkbox' ? 'change' : 'input', function () {
				touched = true;
				if (hasError(el)) validate(el);
			});
			// Keep dependent fields in sync (confirm password; current password when the email changes).
			var dep = el.dataset.match || el.dataset.requiredIfChanged;
			var src = dep && document.getElementById(dep);
			if (src) src.addEventListener('input', function () {
				if (hasError(el) || (el.dataset.match && el.value)) validate(el);
			});
		});

		form.addEventListener('submit', function (e) {
			var first = null;
			fields.forEach(function (el) { if (!validate(el) && !first) first = el; });
			if (first) {
				e.preventDefault();
				form.removeAttribute('data-sent');
				var target = first.matches('[data-otp]') ? first.querySelector('input') : first;
				target.focus({ preventScroll: true });
				first.closest('.ims-field').scrollIntoView({ block: 'center', behavior: 'smooth' });
			}
		});
	});

	/* OTP boxes: auto-advance, backspace, paste, auto-submit */
	$$('[data-otp]').forEach(function (box) {
		var inputs = $$('input', box);
		var form = box.closest('form');
		function filled() { return inputs.every(function (i) { return /^\d$/.test(i.value); }); }
		function submitIfDone() {
			if (filled() && form && !form.dataset.sent) {
				form.dataset.sent = '1';
				form.requestSubmit ? form.requestSubmit() : form.submit();
			}
		}
		function spread(digits, start) {
			digits.split('').forEach(function (d, k) { if (inputs[start + k]) inputs[start + k].value = d; });
			var next = Math.min(start + digits.length, inputs.length - 1);
			inputs[next].focus();
			submitIfDone();
		}
		inputs.forEach(function (input, idx) {
			input.addEventListener('input', function () {
				var digits = input.value.replace(/\D/g, '');
				input.value = '';
				if (digits) { spread(digits.slice(0, inputs.length - idx), idx); }
				input.classList.toggle('has-value', !!input.value);
				box.dispatchEvent(new Event('input'));
			});
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Backspace' && !input.value && idx > 0) { inputs[idx - 1].value = ''; inputs[idx - 1].focus(); e.preventDefault(); }
				if (e.key === 'ArrowLeft' && idx > 0) inputs[idx - 1].focus();
				if (e.key === 'ArrowRight' && idx < inputs.length - 1) inputs[idx + 1].focus();
			});
			input.addEventListener('paste', function (e) {
				var text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
				if (!text) return;
				e.preventDefault();
				spread(text.slice(0, inputs.length), 0);
			});
			input.addEventListener('focus', function () { input.select(); });
		});
	});

	/* Resend countdown */
	$$('.ims-link-btn[data-wait]').forEach(function (btn) {
		var wait = parseInt(btn.getAttribute('data-wait'), 10) || 0;
		var label = btn.textContent;
		if (wait <= 0) return;
		btn.disabled = true;
		(function tick() {
			if (wait <= 0) { btn.disabled = false; btn.textContent = label; return; }
			btn.textContent = label + ' in ' + wait + 's';
			wait--;
			setTimeout(tick, 1000);
		})();
	});

	/* Loading state on submit */
	$$('form.ims-form').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			var btn = form.querySelector('.ims-btn[type=submit]');
			if (!btn || e.defaultPrevented) return;
			setTimeout(function () { btn.classList.add('is-loading'); btn.disabled = true; }, 0);
		});
	});
})();
