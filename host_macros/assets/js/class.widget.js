class CWidgetHostMacros extends CWidget {

	#onBodyClick = (e) => {
		// --- Eye toggle (secret macros) ---
		const eye = e.target.closest && e.target.closest('.hmacro-eye');
		if (eye) {
			const span = eye.previousElementSibling;
			if (span && span.classList.contains('hmacro-secret-value')) {
				const shown = eye.classList.toggle('is-shown');
				span.textContent = shown
					? (span.getAttribute('data-real') || '')
					: (span.getAttribute('data-masked') || '******');
			}
			return;
		}

		// --- Search toggle (group mode) ---
		const toggle = e.target.closest && e.target.closest('.hmacro-search-toggle');
		if (toggle) {
			const toolbar = toggle.closest('.hmacro-toolbar');
			if (!toolbar) return;

			const input = toolbar.querySelector('.hmacro-search-input');
			const opening = !toolbar.classList.contains('is-open');
			toolbar.classList.toggle('is-open');

			if (opening) {
				input && input.focus();
			}
			else if (input) {
				input.value = '';
				this.#applyHostFilter(toolbar, '');
			}
		}
	};

	#onBodyInput = (e) => {
		const input = e.target.closest && e.target.closest('.hmacro-search-input');
		if (!input) return;

		const toolbar = input.closest('.hmacro-toolbar');
		this.#applyHostFilter(toolbar, input.value);
	};

	#applyHostFilter(toolbar, query) {
		if (!toolbar) return;

		const wrap = toolbar.parentElement;
		if (!wrap) return;

		const needle = (query || '').trim().toLowerCase();
		const cards = wrap.querySelectorAll('.hmacro-single[data-host-name]');

		cards.forEach((card) => {
			const name = card.getAttribute('data-host-name') || '';
			const match = needle === '' || name.indexOf(needle) !== -1;
			card.classList.toggle('hmacro-host-hidden', !match);
		});
	}

	onActivate() {
		this._body.addEventListener('click', this.#onBodyClick);
		this._body.addEventListener('input', this.#onBodyInput);
	}

	onDeactivate() {
		this._body.removeEventListener('click', this.#onBodyClick);
		this._body.removeEventListener('input', this.#onBodyInput);
	}
}
