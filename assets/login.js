(function () {
	'use strict';

	const username = document.querySelector('#loginform #user_login');
	if (!username) return;

	const email = document.createElement('input');
	email.type = 'email';
	email.required = true;

	username.addEventListener('copy', function (event) {
		if (!event.clipboardData || event.defaultPrevented || username.selectionStart === username.selectionEnd) return;
		// Copy the input selection explicitly so page labels cannot enter the clipboard.
		event.clipboardData.setData('text/plain', username.value.slice(username.selectionStart, username.selectionEnd));
		event.preventDefault();
	});

	username.addEventListener('paste', function (event) {
		if (!event.clipboardData || event.defaultPrevented) return;
		const pasted = event.clipboardData.getData('text/plain');
		// Direction marks copied from RTL pages are not part of an email address.
		const cleaned = pasted.replace(/[\u200b-\u200f\u202a-\u202e\u2060-\u2069\ufeff]/g, '').trim();
		const match = cleaned.match(/^(\S+)([\s\S]*)$/);
		if (!match) return;
		email.value = match[1];
		if (!email.validity.valid) return;

		// Recognize a copied login form, without extracting emails from arbitrary text.
		const tail = match[2].trim();
		const passwordLabel = document.querySelector('#loginform label[for="user_pass"]')?.textContent.trim();
		if (tail && (!passwordLabel || !tail.startsWith(passwordLabel) || tail.includes('@'))) return;
		if (pasted === email.value) return;

		event.preventDefault();
		username.setRangeText(email.value, username.selectionStart, username.selectionEnd, 'end');
		username.dispatchEvent(new Event('input', { bubbles: true }));
	});
})();
