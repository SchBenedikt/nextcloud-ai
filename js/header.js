/* AI – header button. Fügt rechts neben den Benachrichtigungen einen
 * schnellen Einstiegspunkt in den Chat ein, wenn man gerade in einer
 * anderen Nextcloud-Ansicht steckt.
 */
(function () {
	'use strict';

	function meta(name) {
		var el = document.head.querySelector('meta[name="' + name + '"]');
		return el ? el.getAttribute('content') : '';
	}

	function inject() {
		if (typeof OC === 'undefined' || !OC.webroot) return;
		if (/\/apps\/eva_ai($|\/)/.test(location.pathname)) return;
		var right = document.getElementById('header-right');
		if (!right || !right.firstChild) return;
		if (right.querySelector('.eva-ai-header-link')) return;

		var style = document.createElement('style');
		style.textContent =
			'.eva-ai-header-link {' +
			'display:inline-flex;align-items:center;justify-content:center;' +
			'width:44px;height:44px;border-radius:50%;' +
			'background:var(--color-main-background,#fff);' +
			'box-shadow:0 0 0 1px var(--color-border,#ddd);' +
			'margin-right:6px;' +
			'}' +
			'.eva-ai-header-link:hover { background:var(--color-background-hover,#f1f2f4); }' +
			'.eva-ai-header-link img { width:26px;height:26px;border-radius:6px;display:block; }';
		document.head.appendChild(style);

		var a = document.createElement('a');
		a.className = 'eva-ai-header-link';
		a.href = OC.webroot + '/apps/eva_ai/';
		a.title = 'AI – Chat with your files';
		a.setAttribute('aria-label', 'AI – Chat with your files');
		var img = document.createElement('img');
		img.src = OC.webroot + '/apps/eva_ai/img/eva-icon.svg';
		img.alt = 'AI';
		a.appendChild(img);
		right.insertBefore(a, right.firstChild);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', inject);
	} else {
		inject();
	}
})();