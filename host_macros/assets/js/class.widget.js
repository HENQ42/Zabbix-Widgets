class CWidgetHostMacros extends CWidget {

	#onBodyClick = (e) => {
		const eye = e.target.closest && e.target.closest('.hmacro-eye');
		if (!eye) return;

		// Find the value span just before the eye button.
		const span = eye.previousElementSibling;
		if (!span || !span.classList.contains('hmacro-secret-value')) return;

		const shown = eye.classList.toggle('is-shown');
		span.textContent = shown
			? (span.getAttribute('data-real') || '')
			: (span.getAttribute('data-masked') || '******');
	};

	onActivate() {
		this._body.addEventListener('click', this.#onBodyClick);
	}

	onDeactivate() {
		this._body.removeEventListener('click', this.#onBodyClick);
	}
}
