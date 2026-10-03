(() => {
	'use strict';

	const pending = () => Array.from(document.querySelectorAll('script[type="text/vyompress-delayed"][data-vyompress-src]'));
	let started = false;

	const activate = async () => {
		if (started) {
			return;
		}

		started = true;
		for (const original of pending()) {
			await new Promise((resolve) => {
				const script = document.createElement('script');
				for (const attribute of original.attributes) {
					if (!['type', 'data-vyompress-src'].includes(attribute.name)) {
						script.setAttribute(attribute.name, attribute.value);
					}
				}
				script.src = original.dataset.vyompressSrc;
				script.addEventListener('load', resolve, { once: true });
				script.addEventListener('error', resolve, { once: true });
				original.replaceWith(script);
			});
		}
	};

	for (const eventName of ['pointerdown', 'keydown', 'touchstart', 'scroll']) {
		window.addEventListener(eventName, activate, { once: true, passive: true });
	}

	window.setTimeout(activate, Math.max(1000, Number(window.vyompressBoostDelay?.timeout || 5000)));
})();
