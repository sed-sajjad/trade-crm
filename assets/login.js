(function () {
	'use strict';

	const username = document.querySelector('#loginform #user_login');
	if (!username) return;
	const form = username.form;
	username.setAttribute('dir', 'ltr');
	username.setAttribute('autocorrect', 'off');
	username.setAttribute('spellcheck', 'false');

	const email = document.createElement('input');
	email.type = 'email';
	email.required = true;
	const plain = (value) => value.replace(/[\u200b-\u200f\u202a-\u202e\u2060-\u2069\ufeff]/g, '').trim();
	const compact = (value) => plain(value).replace(/\s+/g, ' ');
	const guide = compact(form.querySelector('.trade-crm-remember-hint')?.textContent || '').slice(0, 24);
	const labels = [...form.querySelectorAll('label[for="user_pass"], label[for="rememberme"]')].map((label) => compact(label.textContent));
	const markers = [...labels, guide].filter(Boolean);
	function validEmail(value) {
		email.value = value;
		return email.validity.valid;
	}
	function cleanEmail(value) {
		const cleaned = plain(value);
		if (validEmail(cleaned)) return cleaned;
		if (cleaned.split('@').length !== 2) return null;
		const text = compact(cleaned);
		for (const marker of markers) {
			for (let index = text.indexOf(marker, 1); index > 0; index = text.indexOf(marker, index + marker.length)) {
				if (!text.slice(index).includes('@')) {
					const candidate = text.slice(0, index).trim();
					if (validEmail(candidate)) return candidate;
				}
			}
		}
		return null;
	}
	function repairValue(event) {
		if (event?.isComposing) return;
		const value = username.value;
		const cleaned = cleanEmail(value);
		const hasGuide = Boolean(guide && compact(value).includes(guide));
		const hasFormText = cleaned !== null && compact(value) !== cleaned;
		// Normal typing can include spaces in a username; paste and committed values can be trimmed.
		const typing = event?.type === 'input' && (event.inputType === 'insertText' || event.inputType?.startsWith('delete') || event.inputType?.startsWith('history'));
		if (cleaned !== null && cleaned !== value && (!typing || hasFormText)) {
			username.value = cleaned;
			username.setSelectionRange(cleaned.length, cleaned.length);
		}
		username.setCustomValidity(cleaned === null && hasGuide ? 'متن راهنمای صفحه وارد این فیلد شده است. فیلد را پاک کنید و ایمیل را دوباره وارد کنید.' : '');
	}
	username.addEventListener('input', repairValue);
	username.addEventListener('change', repairValue);
	username.addEventListener('compositionend', repairValue);
	window.addEventListener('pageshow', repairValue);
	form.addEventListener('submit', function (event) {
		repairValue();
		if (username.validity.customError) {
			event.preventDefault();
			username.reportValidity();
		}
	}, true);
	repairValue();

	username.addEventListener('copy', function (event) {
		if (!event.clipboardData || event.defaultPrevented || username.selectionStart === username.selectionEnd) return;
		// Copy the input selection explicitly so page labels cannot enter the clipboard.
		event.clipboardData.setData('text/plain', username.value.slice(username.selectionStart, username.selectionEnd));
		event.preventDefault();
	});

	username.addEventListener('paste', function (event) {
		// Inspect the final native value too, including a delayed iOS insertion.
		window.setTimeout(repairValue, 0);
		if (!event.clipboardData || event.defaultPrevented) return;
		const pasted = event.clipboardData.getData('text/plain');
		const cleaned = cleanEmail(pasted);
		if (cleaned === null || pasted === cleaned) return;

		event.preventDefault();
		username.setRangeText(cleaned, username.selectionStart, username.selectionEnd, 'end');
		username.dispatchEvent(new Event('input', { bubbles: true }));
	});
})();
