/**
 * Executor de Scripts — classe do widget.
 *
 * Caracteristicas principais:
 *  - NUNCA atualiza sozinho: _startUpdating() faz um unico _update() inicial (que
 *    monta o catalogo) e jamais agenda setInterval, independente do intervalo de
 *    atualizacao do dashboard. Assim o resultado de uma execucao nunca e apagado.
 *  - Toda a interface (catalogo, formularios, acoes, macros, resultado) e construida
 *    em JS a partir do catalogo embutido no corpo renderizado pelo PHP.
 *  - Cada script expoe uma ou mais ACOES (botoes). Cada acao mostra um preview ao vivo
 *    do comando e executa via action propria (widget.script_runner.execute) com CSRF.
 *  - Painel "Macros do host" desacoplado: escolhe um host, lista as macros (secretas
 *    mascaradas) e permite inserir "{$NOME}" nos campos. A resolucao do valor real
 *    acontece no servidor, no momento da execucao.
 */
class CWidgetScriptRunner extends CWidget {

	static DANGER = {
		low: {label: 'Baixo risco', cls: 'sr-danger-low', rank: 1},
		medium: {label: 'Risco moderado', cls: 'sr-danger-medium', rank: 2},
		high: {label: 'Alto risco', cls: 'sr-danger-high', rank: 3}
	};

	static ALERT = {
		info: 'sr-alert-info',
		warning: 'sr-alert-warning',
		danger: 'sr-alert-danger'
	};

	static PLACEHOLDER_RE = /\{([a-z][a-z0-9_]*)\}/g;
	static PLACEHOLDER_TEST = /\{[a-z][a-z0-9_]*\}/;
	static MACRO_RE = /\{\$[A-Z0-9_.]+(?::[^}]*)?\}/;

	onInitialize() {
		super.onInitialize();

		this._rendered_once = false;
		this._catalog = {scripts: [], errors: []};
		this._csrf = '';
		this._selected = null;
		this._running = false;

		// Estado do painel de macros (desacoplado dos scripts).
		this._hostid = null;
		this._hostname = '';
		this._last_focused = null;
		this._host_search_timer = null;

		CWidgetScriptRunner.injectStyles();
	}

	/**
	 * Renderiza uma unica vez e NUNCA agenda atualizacao recorrente.
	 */
	_startUpdating() {
		if (this._update_timeout_id !== null) {
			clearTimeout(this._update_timeout_id);
			this._update_timeout_id = null;
		}
		if (this._update_interval_id !== null) {
			clearInterval(this._update_interval_id);
			this._update_interval_id = null;
		}

		if (this._rendered_once) {
			return;
		}

		this._update();
	}

	processUpdateResponse(response) {
		super.processUpdateResponse(response);

		this._rendered_once = true;
		this._bootstrap();
	}

	_bootstrap() {
		const root = this._body ? this._body.querySelector('.script-runner') : null;

		if (root === null) {
			return;
		}

		try {
			this._catalog = JSON.parse(atob(root.dataset.catalog || '')) || {scripts: [], errors: []};
		}
		catch (e) {
			this._catalog = {scripts: [], errors: []};
		}

		this._csrf = root.dataset.csrf || '';

		this._renderLayout(root);
	}

	_renderLayout(root) {
		root.innerHTML = '';

		// Painel de macros (sempre presente, independente de script selecionado).
		root.appendChild(this._renderMacrosPanel());

		const layout = document.createElement('div');
		layout.className = 'sr-layout';

		// Coluna esquerda: catalogo.
		const aside = document.createElement('div');
		aside.className = 'sr-catalog';

		const scripts = this._catalog.scripts || [];
		const inactive_count = scripts.filter((s) => s.is_active === false).length;

		const aside_title = document.createElement('div');
		aside_title.className = 'sr-catalog-title';
		aside_title.textContent = 'Scripts disponiveis';
		if (inactive_count > 0) {
			const badge = document.createElement('span');
			badge.className = 'sr-catalog-inactive-count';
			badge.textContent = inactive_count + ' desativado' + (inactive_count > 1 ? 's' : '');
			aside_title.appendChild(badge);
		}
		aside.appendChild(aside_title);

		if (this._catalog.errors && this._catalog.errors.length > 0) {
			aside.appendChild(this._renderCatalogErrors(this._catalog.errors));
		}

		if (scripts.length === 0) {
			const empty = document.createElement('div');
			empty.className = 'sr-empty';
			empty.textContent = 'Nenhum script encontrado na pasta scripts/.';
			aside.appendChild(empty);
		}
		else {
			scripts.forEach((script) => aside.appendChild(this._renderCard(script)));
		}

		// Coluna direita: detalhe / formulario.
		this._detail = document.createElement('div');
		this._detail.className = 'sr-detail';
		this._renderPlaceholder();

		layout.appendChild(aside);
		layout.appendChild(this._detail);
		root.appendChild(layout);
	}

	/* ----------------------------------------------------------------- Macros */

	_renderMacrosPanel() {
		const panel = document.createElement('div');
		panel.className = 'sr-macros';

		const head = document.createElement('div');
		head.className = 'sr-macros-head';

		const title = document.createElement('span');
		title.className = 'sr-macros-title';
		title.textContent = 'Macros do host';
		head.appendChild(title);

		const hint = document.createElement('span');
		hint.className = 'sr-macros-hint';
		hint.textContent = 'Escolha um host para consultar suas macros e inserir {$MACRO} nos campos.';
		head.appendChild(hint);

		panel.appendChild(head);

		// Linha de selecao de host.
		const picker = document.createElement('div');
		picker.className = 'sr-host-picker';

		this._host_input = document.createElement('input');
		this._host_input.type = 'text';
		this._host_input.className = 'sr-input sr-host-input';
		this._host_input.placeholder = 'Buscar host pelo nome...';
		this._host_input.autocomplete = 'off';
		this._host_input.addEventListener('input', () => this._onHostSearchInput());
		this._host_input.addEventListener('focus', () => this._onHostSearchInput());
		picker.appendChild(this._host_input);

		this._host_results = document.createElement('div');
		this._host_results.className = 'sr-host-results';
		picker.appendChild(this._host_results);

		this._host_current = document.createElement('div');
		this._host_current.className = 'sr-host-current';
		picker.appendChild(this._host_current);

		panel.appendChild(picker);

		// Area da lista de macros.
		this._macros_box = document.createElement('div');
		this._macros_box.className = 'sr-macros-box';
		panel.appendChild(this._macros_box);

		// Fecha o dropdown ao clicar fora.
		document.addEventListener('click', (e) => {
			if (this._host_results && !picker.contains(e.target)) {
				this._host_results.innerHTML = '';
				this._host_results.classList.remove('sr-host-results-open');
			}
		});

		return panel;
	}

	_onHostSearchInput() {
		const term = this._host_input.value.trim();
		if (this._host_search_timer !== null) {
			clearTimeout(this._host_search_timer);
		}
		this._host_search_timer = setTimeout(() => this._searchHosts(term), 250);
	}

	_searchHosts(term) {
		const curl = new Curl('zabbix.php');
		curl.setArgument('action', 'widget.script_runner.hosts');

		const body = new URLSearchParams();
		body.set('search', term);
		body.set('_csrf_token', this._csrf);

		this._postJson(curl, body)
			.then((data) => this._renderHostResults(data.ok ? (data.hosts || []) : []))
			.catch(() => this._renderHostResults([]));
	}

	_renderHostResults(hosts) {
		this._host_results.innerHTML = '';

		if (hosts.length === 0) {
			this._host_results.classList.remove('sr-host-results-open');
			return;
		}

		this._host_results.classList.add('sr-host-results-open');

		hosts.forEach((host) => {
			const item = document.createElement('button');
			item.type = 'button';
			item.className = 'sr-host-item';
			item.textContent = host.name;
			if (host.host && host.host !== host.name) {
				const tech = document.createElement('span');
				tech.className = 'sr-host-item-tech';
				tech.textContent = host.host;
				item.appendChild(tech);
			}
			item.addEventListener('click', () => this._selectHost(host));
			this._host_results.appendChild(item);
		});
	}

	_selectHost(host) {
		this._hostid = host.hostid;
		this._hostname = host.name;

		this._host_input.value = '';
		this._host_results.innerHTML = '';
		this._host_results.classList.remove('sr-host-results-open');

		this._host_current.innerHTML = '';
		const label = document.createElement('span');
		label.className = 'sr-host-current-name';
		label.textContent = 'Host: ' + host.name;
		this._host_current.appendChild(label);

		const clear = document.createElement('button');
		clear.type = 'button';
		clear.className = 'sr-host-clear';
		clear.textContent = 'limpar';
		clear.addEventListener('click', () => this._clearHost());
		this._host_current.appendChild(clear);

		this._loadMacros();
	}

	_clearHost() {
		this._hostid = null;
		this._hostname = '';
		this._host_current.innerHTML = '';
		this._macros_box.innerHTML = '';
	}

	_loadMacros() {
		this._macros_box.innerHTML = '';
		const loading = document.createElement('div');
		loading.className = 'sr-macros-loading';
		loading.textContent = 'Carregando macros...';
		this._macros_box.appendChild(loading);

		const curl = new Curl('zabbix.php');
		curl.setArgument('action', 'widget.script_runner.macros');

		const body = new URLSearchParams();
		body.set('hostid', this._hostid);
		body.set('_csrf_token', this._csrf);

		this._postJson(curl, body)
			.then((data) => {
				if (!data.ok) {
					this._macros_box.innerHTML = '';
					const err = document.createElement('div');
					err.className = 'sr-macros-empty';
					err.textContent = data.error || 'Nao foi possivel carregar as macros.';
					this._macros_box.appendChild(err);
					return;
				}
				this._renderMacrosList(data.macros || []);
			})
			.catch(() => {
				this._macros_box.innerHTML = '';
				const err = document.createElement('div');
				err.className = 'sr-macros-empty';
				err.textContent = 'Falha de comunicacao ao carregar as macros.';
				this._macros_box.appendChild(err);
			});
	}

	_renderMacrosList(macros) {
		this._macros_box.innerHTML = '';

		if (macros.length === 0) {
			const empty = document.createElement('div');
			empty.className = 'sr-macros-empty';
			empty.textContent = 'Este host nao possui macros.';
			this._macros_box.appendChild(empty);
			return;
		}

		const filter = document.createElement('input');
		filter.type = 'text';
		filter.className = 'sr-input sr-macros-filter';
		filter.placeholder = 'Filtrar macros...';
		this._macros_box.appendChild(filter);

		const table = document.createElement('div');
		table.className = 'sr-macros-table';
		this._macros_box.appendChild(table);

		const render = (term) => {
			table.innerHTML = '';
			const t = term.toLowerCase();
			macros
				.filter((m) => t === '' || m.macro.toLowerCase().indexOf(t) !== -1)
				.forEach((m) => table.appendChild(this._renderMacroRow(m)));
		};

		filter.addEventListener('input', () => render(filter.value.trim()));
		render('');
	}

	_renderMacroRow(m) {
		const row = document.createElement('div');
		row.className = 'sr-macro-row';

		const name = document.createElement('code');
		name.className = 'sr-macro-name';
		name.textContent = m.macro;
		row.appendChild(name);

		const value = document.createElement('span');
		value.className = 'sr-macro-value' + (m.secret ? ' sr-macro-secret' : '');
		value.textContent = m.value;
		row.appendChild(value);

		if (m.source && m.source !== 'host') {
			const src = document.createElement('span');
			src.className = 'sr-macro-source';
			src.textContent = m.source === 'global' ? 'global' : 'template';
			row.appendChild(src);
		}

		const insert = document.createElement('button');
		insert.type = 'button';
		insert.className = 'sr-macro-insert';
		insert.textContent = 'inserir';
		insert.title = 'Inserir ' + m.macro + ' no campo focado (ou copiar)';
		insert.addEventListener('click', () => this._insertMacro(m.macro));
		row.appendChild(insert);

		return row;
	}

	_insertMacro(macro) {
		const node = this._last_focused;
		const usable = node && node.isConnected
			&& (node.tagName === 'TEXTAREA' || (node.tagName === 'INPUT' && node.type !== 'number'));

		if (usable) {
			const start = node.selectionStart !== null ? node.selectionStart : node.value.length;
			const end = node.selectionEnd !== null ? node.selectionEnd : node.value.length;
			node.value = node.value.slice(0, start) + macro + node.value.slice(end);
			const pos = start + macro.length;
			node.focus();
			try {
				node.setSelectionRange(pos, pos);
			}
			catch (e) { /* alguns tipos nao suportam */ }
			node.dispatchEvent(new Event('input', {bubbles: true}));
			this._flashMacroMsg('Inserido ' + macro + ' no campo.');
			return;
		}

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(macro)
				.then(() => this._flashMacroMsg(macro + ' copiado. Cole no campo desejado.'))
				.catch(() => this._flashMacroMsg('Selecione um campo e clique em inserir.'));
		}
		else {
			this._flashMacroMsg('Selecione um campo de texto e clique em inserir.');
		}
	}

	_flashMacroMsg(text) {
		let msg = this._macros_box.querySelector('.sr-macros-msg');
		if (!msg) {
			msg = document.createElement('div');
			msg.className = 'sr-macros-msg';
			this._macros_box.insertBefore(msg, this._macros_box.firstChild);
		}
		msg.textContent = text;
		clearTimeout(this._macros_msg_timer);
		this._macros_msg_timer = setTimeout(() => {
			if (msg) {
				msg.textContent = '';
			}
		}, 3000);
	}

	/* --------------------------------------------------------------- Catalogo */

	_renderCatalogErrors(errors) {
		const box = document.createElement('div');
		box.className = 'sr-catalog-errors';

		const title = document.createElement('div');
		title.className = 'sr-catalog-errors-title';
		title.textContent = 'Scripts ignorados (' + errors.length + ')';
		box.appendChild(title);

		errors.forEach((err) => {
			const row = document.createElement('div');
			row.className = 'sr-catalog-error-row';
			row.textContent = err.slug + ': ' + err.error;
			box.appendChild(row);
		});

		return box;
	}

	_maxDanger(script) {
		let rank = 0;
		let danger = 'low';
		(script.actions || []).forEach((a) => {
			const d = CWidgetScriptRunner.DANGER[a.danger] || CWidgetScriptRunner.DANGER.medium;
			if (d.rank > rank) {
				rank = d.rank;
				danger = a.danger;
			}
		});
		return danger;
	}

	_renderCard(script) {
		const inactive = script.is_active === false;

		const card = document.createElement('button');
		card.type = 'button';
		card.className = inactive ? 'sr-card sr-card-inactive' : 'sr-card';
		card.dataset.slug = script.slug;
		if (inactive) {
			card.disabled = true;
			card.title = 'Script desativado (isactive: false no script.json).';
		}

		const name = document.createElement('div');
		name.className = 'sr-card-name';
		name.textContent = script.name;
		card.appendChild(name);

		if (script.summary) {
			const desc = document.createElement('div');
			desc.className = 'sr-card-desc';
			desc.textContent = script.summary;
			card.appendChild(desc);
		}

		const meta = document.createElement('div');
		meta.className = 'sr-card-meta';

		if (inactive) {
			const off = document.createElement('span');
			off.className = 'sr-badge sr-badge-inactive';
			off.textContent = 'Desativado';
			meta.appendChild(off);
		}
		else {
			const danger = CWidgetScriptRunner.DANGER[this._maxDanger(script)] || CWidgetScriptRunner.DANGER.medium;
			const badge = document.createElement('span');
			badge.className = 'sr-badge ' + danger.cls;
			badge.textContent = danger.label;
			meta.appendChild(badge);
		}

		const count = document.createElement('span');
		count.className = 'sr-card-cat';
		count.textContent = (script.actions || []).length + ' acao(oes)';
		meta.appendChild(count);

		card.appendChild(meta);

		if (!inactive) {
			card.addEventListener('click', () => this._selectScript(script, card));
		}

		return card;
	}

	_selectScript(script, card) {
		if (this._running || script.is_active === false) {
			return;
		}

		this._selected = script;

		if (this._body) {
			this._body.querySelectorAll('.sr-card').forEach((c) => c.classList.remove('sr-card-active'));
		}
		card.classList.add('sr-card-active');

		this._renderForm(script);
	}

	_renderPlaceholder() {
		this._detail.innerHTML = '';
		const ph = document.createElement('div');
		ph.className = 'sr-placeholder';
		ph.textContent = 'Selecione um script a esquerda para ver os detalhes e executar.';
		this._detail.appendChild(ph);
	}

	/* ------------------------------------------------------------------ Forma */

	_renderForm(script) {
		this._detail.innerHTML = '';
		this._field_nodes = {};
		this._action_nodes = [];

		const header = document.createElement('div');
		header.className = 'sr-detail-header';

		const title = document.createElement('h3');
		title.className = 'sr-detail-title';
		title.textContent = script.name;
		header.appendChild(title);

		this._detail.appendChild(header);

		// Alerta exibido ao selecionar o script.
		if (script.alert && (script.alert.title || script.alert.message)) {
			this._detail.appendChild(this._renderAlert(script.alert));
		}

		if (script.summary) {
			const desc = document.createElement('p');
			desc.className = 'sr-detail-desc';
			desc.textContent = script.summary;
			this._detail.appendChild(desc);
		}

		const form = document.createElement('form');
		form.className = 'sr-form';
		form.autocomplete = 'off';

		(script.fields || []).forEach((field) => {
			form.appendChild(this._renderField(field));
		});

		form.addEventListener('submit', (e) => e.preventDefault());
		form.addEventListener('input', () => this._refreshPreviews(script));
		form.addEventListener('change', () => this._refreshPreviews(script));

		this._detail.appendChild(form);

		// Acoes (botoes), cada uma com seu preview de comando.
		const actions_wrap = document.createElement('div');
		actions_wrap.className = 'sr-action-list';

		const actions_title = document.createElement('div');
		actions_title.className = 'sr-action-list-title';
		actions_title.textContent = 'Acoes';
		actions_wrap.appendChild(actions_title);

		const actions_grid = document.createElement('div');
		actions_grid.className = 'sr-action-grid';
		(script.actions || []).forEach((action) => {
			actions_grid.appendChild(this._renderActionCard(script, action));
		});
		actions_wrap.appendChild(actions_grid);

		this._detail.appendChild(actions_wrap);

		const tinfo = document.createElement('div');
		tinfo.className = 'sr-timeout-info';
		tinfo.textContent = 'Tempo limite por execucao: ' + script.timeout + 's';
		this._detail.appendChild(tinfo);

		// Area de resultado (compartilhada entre as acoes deste script).
		this._result_box = document.createElement('div');
		this._result_box.className = 'sr-result-box';
		this._detail.appendChild(this._result_box);

		this._refreshPreviews(script);
	}

	_renderAlert(alert) {
		const box = document.createElement('div');
		box.className = 'sr-alert ' + (CWidgetScriptRunner.ALERT[alert.level] || CWidgetScriptRunner.ALERT.info);

		if (alert.title) {
			const t = document.createElement('div');
			t.className = 'sr-alert-title';
			t.textContent = alert.title;
			box.appendChild(t);
		}
		if (alert.message) {
			const m = document.createElement('div');
			m.className = 'sr-alert-msg';
			m.textContent = alert.message;
			box.appendChild(m);
		}

		return box;
	}

	_renderField(field) {
		const wrap = document.createElement('div');
		wrap.className = 'sr-field';

		if (field.type === 'flag') {
			const label = document.createElement('label');
			label.className = 'sr-field-flag';

			const input = document.createElement('input');
			input.type = 'checkbox';
			input.name = field.name;
			if (field.default === true) {
				input.checked = true;
			}
			this._field_nodes[field.name] = input;

			const span = document.createElement('span');
			span.textContent = field.label;

			label.appendChild(input);
			label.appendChild(span);
			wrap.appendChild(label);

			if (field.help) {
				wrap.appendChild(this._renderHelp(field.help));
			}
			return wrap;
		}

		const label = document.createElement('label');
		label.className = 'sr-field-label';
		label.textContent = field.label;
		if (field.required) {
			const req = document.createElement('span');
			req.className = 'sr-req';
			req.textContent = ' *';
			label.appendChild(req);
		}
		wrap.appendChild(label);

		let input;

		if (field.type === 'textarea') {
			input = document.createElement('textarea');
			input.rows = 3;
		}
		else if (field.type === 'select') {
			input = document.createElement('select');
			(field.options || []).forEach((opt) => {
				const o = document.createElement('option');
				o.value = opt.value;
				o.textContent = opt.label;
				input.appendChild(o);
			});
			if (field.default !== null && field.default !== undefined) {
				input.value = String(field.default);
			}
		}
		else {
			input = document.createElement('input');
			input.type = (field.type === 'integer') ? 'number'
				: (field.secret ? 'password' : 'text');
		}

		input.name = field.name;
		input.className = 'sr-input';
		if (field.placeholder) {
			input.placeholder = field.placeholder;
		}
		if (field.default !== null && field.default !== undefined && field.type !== 'select') {
			input.value = String(field.default);
		}

		// Rastreia o ultimo campo de texto focado para o botao "inserir" das macros.
		input.addEventListener('focus', () => {
			this._last_focused = input;
		});

		this._field_nodes[field.name] = input;
		wrap.appendChild(input);

		const err = document.createElement('div');
		err.className = 'sr-field-error';
		err.dataset.errorFor = field.name;
		wrap.appendChild(err);

		if (field.help) {
			wrap.appendChild(this._renderHelp(field.help));
		}

		return wrap;
	}

	_renderHelp(text) {
		const help = document.createElement('div');
		help.className = 'sr-field-help';
		help.textContent = text;
		return help;
	}

	/* ------------------------------------------------------------- Acoes/preview */

	_renderActionCard(script, action) {
		const danger = CWidgetScriptRunner.DANGER[action.danger] || CWidgetScriptRunner.DANGER.medium;

		const card = document.createElement('div');
		card.className = 'sr-action-card ' + danger.cls + '-border';

		const head = document.createElement('div');
		head.className = 'sr-action-head';

		const title = document.createElement('span');
		title.className = 'sr-action-title';
		title.textContent = action.title;
		head.appendChild(title);

		const badge = document.createElement('span');
		badge.className = 'sr-badge ' + danger.cls;
		badge.textContent = danger.label;
		head.appendChild(badge);

		card.appendChild(head);

		if (action.description) {
			const desc = document.createElement('div');
			desc.className = 'sr-action-desc';
			desc.textContent = action.description;
			card.appendChild(desc);
		}

		const preview_wrap = document.createElement('div');
		preview_wrap.className = 'sr-action-preview';

		const plabel = document.createElement('span');
		plabel.className = 'sr-action-preview-label';
		plabel.textContent = 'Comando:';
		preview_wrap.appendChild(plabel);

		const code = document.createElement('code');
		code.className = 'sr-action-preview-code';
		preview_wrap.appendChild(code);

		card.appendChild(preview_wrap);

		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'sr-btn sr-btn-run';
		btn.textContent = 'Executar';
		btn.addEventListener('click', () => this._onRun(script, action));
		card.appendChild(btn);

		this._action_nodes.push({action: action, code: code, button: btn});

		return card;
	}

	_refreshPreviews(script) {
		if (!this._action_nodes) {
			return;
		}
		const prefix = (script.entrypoint_name || 'script') + ' ';
		this._action_nodes.forEach((entry) => {
			const argv = this._resolveArgvPreview(script, entry.action);
			entry.code.textContent = prefix + argv.map((t) => CWidgetScriptRunner.shellQuote(t)).join(' ');
		});
	}

	/**
	 * Espelha CScriptCatalog::buildArgv para o preview (valores de campos secretos
	 * aparecem como "***"; macros {$X} permanecem literais — resolvidas no servidor).
	 */
	_resolveArgvPreview(script, action) {
		const fields = {};
		(script.fields || []).forEach((f) => {
			fields[f.name] = f;
		});

		const out = [];

		(action.args || []).forEach((token) => {
			if (!CWidgetScriptRunner.PLACEHOLDER_TEST.test(token)) {
				out.push(token);
				return;
			}

			let drop = false;
			const rendered = token.replace(CWidgetScriptRunner.PLACEHOLDER_RE, (m, name) => {
				const f = fields[name];
				if (!f) {
					drop = true;
					return '';
				}
				const node = this._field_nodes[name];
				if (f.type === 'flag') {
					const on = node ? node.checked : (f.default === true);
					if (!on) {
						drop = true;
						return '';
					}
					return f.switch || '';
				}
				let v = node ? node.value : '';
				if (v === null || v === undefined || String(v).trim() === '') {
					drop = true;
					return '';
				}
				if (f.secret) {
					return '***';
				}
				return String(v);
			});

			if (!drop) {
				out.push(rendered);
			}
		});

		return out;
	}

	static shellQuote(token) {
		if (token === '') {
			return '""';
		}
		if (/^[\w@%+=:,./{}$-]+$/.test(token)) {
			return token;
		}
		return '"' + token.replace(/"/g, '\\"') + '"';
	}

	/* --------------------------------------------------------------- Execucao */

	_collectParamsForAction(script, action) {
		const params = {};
		const uses = action.uses || [];
		const fields = {};
		(script.fields || []).forEach((f) => {
			fields[f.name] = f;
		});

		uses.forEach((name) => {
			const field = fields[name];
			const node = this._field_nodes[name];
			if (!field || !node) {
				return;
			}
			if (field.type === 'flag') {
				params[name] = node.checked;
			}
			else if (field.type === 'integer') {
				params[name] = (node.value.trim() === '') ? '' : Number(node.value);
			}
			else {
				params[name] = node.value;
			}
		});

		return params;
	}

	_validateActionLocally(script, action) {
		const errors = {};
		const uses = action.uses || [];
		const fields = {};
		(script.fields || []).forEach((f) => {
			fields[f.name] = f;
		});

		let needs_host = false;

		uses.forEach((name) => {
			const field = fields[name];
			const node = this._field_nodes[name];
			if (!field || !node || field.type === 'flag') {
				return;
			}
			const val = String(node.value || '');
			if (field.required && val.trim() === '') {
				errors[name] = 'Campo obrigatorio.';
			}
			if (CWidgetScriptRunner.MACRO_RE.test(val)) {
				needs_host = true;
			}
		});

		return {errors: errors, needs_host: needs_host};
	}

	_clearFieldErrors() {
		if (!this._detail) {
			return;
		}
		this._detail.querySelectorAll('.sr-field-error').forEach((n) => {
			n.textContent = '';
			n.classList.remove('sr-field-error-active');
		});
	}

	_showFieldErrors(field_errors) {
		Object.keys(field_errors).forEach((name) => {
			const node = this._detail.querySelector('[data-error-for="' + CSS.escape(name) + '"]');
			if (node) {
				node.textContent = field_errors[name];
				node.classList.add('sr-field-error-active');
			}
		});
	}

	_onRun(script, action) {
		if (this._running) {
			return;
		}

		this._clearFieldErrors();

		const check = this._validateActionLocally(script, action);

		if (Object.keys(check.errors).length > 0) {
			this._showFieldErrors(check.errors);
			return;
		}

		if (check.needs_host && !this._hostid) {
			this._renderResult({
				ok: false,
				error: 'Esta acao usa referencias {$...}. Selecione um host no painel de macros primeiro.'
			});
			return;
		}

		if (action.confirm) {
			this._confirm(script, action, () => this._execute(script, action));
		}
		else {
			this._execute(script, action);
		}
	}

	_confirm(script, action, onConfirm) {
		const overlay = document.createElement('div');
		overlay.className = 'sr-modal-overlay';

		const modal = document.createElement('div');
		modal.className = 'sr-modal';

		const title = document.createElement('div');
		title.className = 'sr-modal-title';
		title.textContent = 'Confirmar execucao';
		modal.appendChild(title);

		const body = document.createElement('div');
		body.className = 'sr-modal-body';
		body.textContent = 'Voce esta prestes a executar "' + action.title + '" do script "' + script.name + '"'
			+ (action.danger === 'high' ? ' (ALTO RISCO)' : '') + '. Deseja continuar?';
		modal.appendChild(body);

		const actions = document.createElement('div');
		actions.className = 'sr-modal-actions';

		const cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.className = 'sr-btn sr-btn-cancel';
		cancel.textContent = 'Cancelar';
		cancel.addEventListener('click', () => overlay.remove());

		const ok = document.createElement('button');
		ok.type = 'button';
		ok.className = 'sr-btn sr-btn-run';
		ok.textContent = 'Confirmar e executar';
		ok.addEventListener('click', () => {
			overlay.remove();
			onConfirm();
		});

		actions.appendChild(cancel);
		actions.appendChild(ok);
		modal.appendChild(actions);
		overlay.appendChild(modal);

		overlay.addEventListener('click', (e) => {
			if (e.target === overlay) {
				overlay.remove();
			}
		});

		this._body.appendChild(overlay);
	}

	_setRunning(running) {
		this._running = running;
		if (this._action_nodes) {
			this._action_nodes.forEach((entry) => {
				entry.button.disabled = running;
			});
		}
	}

	_execute(script, action) {
		this._setRunning(true);
		this._renderResultPending();

		const curl = new Curl('zabbix.php');
		curl.setArgument('action', 'widget.script_runner.execute');

		const body = new URLSearchParams();
		body.set('script', script.slug);
		body.set('action', action.id);
		body.set('hostid', this._hostid || '');
		body.set('params', JSON.stringify(this._collectParamsForAction(script, action)));
		body.set('_csrf_token', this._csrf);

		this._postJson(curl, body)
			.then((data) => this._renderResult(data))
			.catch((err) => this._renderResult(err && err.ok === false ? err : {
				ok: false,
				error: 'Falha de comunicacao com o servidor (a requisicao nao completou).'
			}))
			.finally(() => this._setRunning(false));
	}

	_postJson(curl, body) {
		return fetch(curl.getUrl(), {
			method: 'POST',
			headers: {
				'Accept': 'application/json, text/plain, */*',
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: body.toString()
		})
			.then((resp) => resp.text().then((text) => {
				let data;

				try {
					data = JSON.parse(text);
				}
				catch (e) {
					throw this._makeNonJsonError(resp.status, text);
				}

				return this._normalizeAjaxPayload(data, resp.status);
			}));
	}

	_normalizeAjaxPayload(data, status) {
		if (data && data.error && !Object.prototype.hasOwnProperty.call(data, 'ok')) {
			return {
				ok: false,
				error: this._formatZabbixError(data.error),
				details: {
					exit_code: null,
					duration_ms: 0,
					timed_out: false,
					stdout: '',
					stderr: 'HTTP ' + status
				}
			};
		}

		return data;
	}

	_formatZabbixError(error) {
		if (typeof error === 'string') {
			return error;
		}

		if (error && typeof error === 'object') {
			const parts = [];
			if (error.title) {
				parts.push(error.title);
			}
			if (Array.isArray(error.messages)) {
				parts.push(error.messages.join(' '));
			}
			return parts.join(' ').trim() || 'Erro retornado pelo Zabbix.';
		}

		return 'Erro retornado pelo Zabbix.';
	}

	_makeNonJsonError(status, text) {
		const snippet = (text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);

		return {
			ok: false,
			error: 'O servidor nao respondeu em JSON (HTTP ' + status + ').',
			details: {
				exit_code: null,
				duration_ms: 0,
				timed_out: false,
				stdout: '',
				stderr: snippet || '(resposta vazia)'
			}
		};
	}

	_renderResultPending() {
		this._result_box.innerHTML = '';
		const pending = document.createElement('div');
		pending.className = 'sr-result sr-result-pending';
		pending.textContent = 'Executando, aguarde...';
		this._result_box.appendChild(pending);
	}

	_renderResult(data) {
		this._result_box.innerHTML = '';

		if (data.field_errors) {
			this._showFieldErrors(data.field_errors);
		}

		const ok = data.ok === true;
		const box = document.createElement('div');
		box.className = 'sr-result ' + (ok ? 'sr-result-ok' : 'sr-result-err');

		const banner = document.createElement('div');
		banner.className = 'sr-result-banner';
		banner.textContent = ok ? 'Sucesso' : 'Falha';
		box.appendChild(banner);

		const msg = document.createElement('div');
		msg.className = 'sr-result-msg';
		msg.textContent = ok ? (data.message || 'Concluido com sucesso.')
			: (data.error || 'Ocorreu um erro na execucao.');
		box.appendChild(msg);

		if (data.result && data.result.details && typeof data.result.details === 'object') {
			box.appendChild(this._renderKeyValues(data.result.details));
		}

		if (data.details) {
			box.appendChild(this._renderTechnical(data.details));
		}

		this._result_box.appendChild(box);
	}

	_renderKeyValues(obj) {
		const dl = document.createElement('div');
		dl.className = 'sr-kv';
		Object.keys(obj).forEach((k) => {
			const row = document.createElement('div');
			row.className = 'sr-kv-row';
			const key = document.createElement('span');
			key.className = 'sr-kv-key';
			key.textContent = k;
			const val = document.createElement('span');
			val.className = 'sr-kv-val';
			val.textContent = (typeof obj[k] === 'object') ? JSON.stringify(obj[k]) : String(obj[k]);
			row.appendChild(key);
			row.appendChild(val);
			dl.appendChild(row);
		});
		return dl;
	}

	_renderTechnical(details) {
		const wrap = document.createElement('details');
		wrap.className = 'sr-tech';

		const summary = document.createElement('summary');
		summary.textContent = 'Detalhes tecnicos';
		wrap.appendChild(summary);

		const meta = document.createElement('div');
		meta.className = 'sr-tech-meta';
		const parts = [];
		if (details.exit_code !== null && details.exit_code !== undefined) {
			parts.push('codigo de saida: ' + details.exit_code);
		}
		if (details.duration_ms !== undefined) {
			parts.push('duracao: ' + details.duration_ms + ' ms');
		}
		if (details.timed_out) {
			parts.push('interrompido por timeout');
		}
		meta.textContent = parts.join('  |  ');
		wrap.appendChild(meta);

		if (details.stdout && details.stdout.trim() !== '') {
			wrap.appendChild(this._renderStream('stdout', details.stdout));
		}
		if (details.stderr && details.stderr.trim() !== '') {
			wrap.appendChild(this._renderStream('stderr', details.stderr));
		}

		return wrap;
	}

	_renderStream(label, content) {
		const block = document.createElement('div');
		block.className = 'sr-stream';
		const lbl = document.createElement('div');
		lbl.className = 'sr-stream-label';
		lbl.textContent = label;
		const pre = document.createElement('pre');
		pre.className = 'sr-stream-pre';
		pre.textContent = content;
		block.appendChild(lbl);
		block.appendChild(pre);
		return block;
	}

	static injectStyles() {
		if (document.getElementById('sr-styles')) {
			return;
		}
		const style = document.createElement('style');
		style.id = 'sr-styles';
		style.textContent = `
/* ---------------------------------------------------------------------------
 * Variaveis de tema. Valores padrao = tema claro (blue-theme).
 * O Zabbix 7.4 expoe o tema como atributo no elemento <html>
 * (ex.: <html theme="dark-theme">), definido em CHtmlPageHeader::show().
 * Os temas escuros (dark-theme e hc-dark) sobrescrevem as variaveis abaixo,
 * de modo que todas as regras .sr-* herdam as cores corretas via var(--sr-*).
 * --------------------------------------------------------------------------- */
.script-runner {
	--sr-fg: #1f2733;
	--sr-muted: #6b7585;
	--sr-faint: #8a94a6;
	--sr-border: #e3e7ed;
	--sr-border-strong: #cfd6e0;
	--sr-card-bg: #f7f9fc;
	--sr-card-hover-bg: #eef3fb;
	--sr-card-hover-border: #b9c4d4;
	--sr-surface: #fcfdff;
	--sr-surface-soft: #f9fbfe;
	--sr-input-bg: #fff;
	--sr-popover-bg: #fff;
	--sr-accent: #1976d2;
	--sr-accent-strong: #145ca8;
	--sr-accent-soft-bg: #e7f0fb;
	--sr-accent-soft-border: #c5dcf5;
	--sr-accent-fg: #1c4e80;
	--sr-on-accent: #fff;
	--sr-shadow: rgba(0,0,0,.12);
	--sr-shadow-strong: rgba(0,0,0,.25);
	--sr-overlay: rgba(20,28,40,.45);
	/* Bloco de codigo / streams: sempre escuro nos dois temas. */
	--sr-code-bg: #1f2733;
	--sr-code-fg: #e6edf3;
	--sr-code-accent: #9ee6b4;
	/* Realces de risco (badges). */
	--sr-low-bg: #e3f5e9; --sr-low-fg: #1f8a4c;
	--sr-medium-bg: #fff3da; --sr-medium-fg: #a9711a;
	--sr-high-bg: #fde4e4; --sr-high-fg: #c0392b;
	--sr-low-border: #1f8a4c; --sr-medium-border: #d8a32a; --sr-high-border: #c0392b;
	/* Alertas. */
	--sr-info-bg: #eaf2fb; --sr-info-border: #c5dcf5; --sr-info-fg: #1c4e80;
	--sr-warn-bg: #fff7e6; --sr-warn-border: #f0d9a8; --sr-warn-fg: #8a6116;
	--sr-danger-bg: #fde4e4; --sr-danger-border: #f3b9b9; --sr-danger-fg: #a02b21;
	/* Resultado. */
	--sr-pending-bg: #f0f4f9; --sr-pending-border: #d6deea; --sr-pending-fg: #5a6678;
	--sr-ok-bg: #f1faf4; --sr-ok-border: #b7e4c7; --sr-ok-fg: #1f8a4c;
	--sr-err-bg: #fdf2f2; --sr-err-border: #f3c0c0; --sr-err-fg: #c0392b;
	--sr-btn-cancel-bg: #e3e7ed; --sr-btn-cancel-fg: #3a4250; --sr-btn-cancel-hover: #d3d9e2;
	--sr-btn-run-disabled: #9bb8d8;

	height: 100%; font-size: 13px; color: var(--sr-fg); display: flex; flex-direction: column;
}
/* Tema escuro padrao. */
html[theme="dark-theme"] .script-runner {
	--sr-fg: #e6edf3;
	--sr-muted: #9aa5b5;
	--sr-faint: #7d8696;
	--sr-border: #3a4350;
	--sr-border-strong: #4a5562;
	--sr-card-bg: #2b3340;
	--sr-card-hover-bg: #333d4c;
	--sr-card-hover-border: #56657a;
	--sr-surface: #262d38;
	--sr-surface-soft: #232a34;
	--sr-input-bg: #1f2630;
	--sr-popover-bg: #2b3340;
	--sr-accent: #4ea1f0;
	--sr-accent-strong: #6cb4f5;
	--sr-accent-soft-bg: #2a3a4d;
	--sr-accent-soft-border: #3d597a;
	--sr-accent-fg: #9cc8f5;
	--sr-on-accent: #0e1012;
	--sr-shadow: rgba(0,0,0,.5);
	--sr-shadow-strong: rgba(0,0,0,.6);
	--sr-overlay: rgba(0,0,0,.6);
	--sr-code-bg: #11161d;
	--sr-code-fg: #e6edf3;
	--sr-code-accent: #9ee6b4;
	--sr-low-bg: #16331f; --sr-low-fg: #6ddf94;
	--sr-medium-bg: #3a2f12; --sr-medium-fg: #e6b95c;
	--sr-high-bg: #3a1c1c; --sr-high-fg: #f08a82;
	--sr-low-border: #2f8a4c; --sr-medium-border: #c79a3a; --sr-high-border: #d05a52;
	--sr-info-bg: #1c2c40; --sr-info-border: #2f4a6b; --sr-info-fg: #9cc8f5;
	--sr-warn-bg: #332a12; --sr-warn-border: #5a4a1f; --sr-warn-fg: #e6b95c;
	--sr-danger-bg: #3a1c1c; --sr-danger-border: #6b2f2f; --sr-danger-fg: #f08a82;
	--sr-pending-bg: #232a34; --sr-pending-border: #3a4350; --sr-pending-fg: #9aa5b5;
	--sr-ok-bg: #16291d; --sr-ok-border: #2f5a3c; --sr-ok-fg: #6ddf94;
	--sr-err-bg: #2c1818; --sr-err-border: #5a2f2f; --sr-err-fg: #f08a82;
	--sr-btn-cancel-bg: #3a4350; --sr-btn-cancel-fg: #d6dde6; --sr-btn-cancel-hover: #4a5562;
	--sr-btn-run-disabled: #335577;
}
/* Tema escuro de alto contraste. */
html[theme="hc-dark"] .script-runner {
	--sr-fg: #ffffff;
	--sr-muted: #c8cdd4;
	--sr-faint: #aab0b8;
	--sr-border: #555;
	--sr-border-strong: #777;
	--sr-card-bg: #1a1a1a;
	--sr-card-hover-bg: #2a2a2a;
	--sr-card-hover-border: #888;
	--sr-surface: #141414;
	--sr-surface-soft: #181818;
	--sr-input-bg: #000;
	--sr-popover-bg: #1a1a1a;
	--sr-accent: #5ab0ff;
	--sr-accent-strong: #7cc0ff;
	--sr-accent-soft-bg: #10243a;
	--sr-accent-soft-border: #3a5f88;
	--sr-accent-fg: #aad4ff;
	--sr-on-accent: #000;
	--sr-shadow: rgba(0,0,0,.7);
	--sr-shadow-strong: rgba(0,0,0,.8);
	--sr-overlay: rgba(0,0,0,.7);
	--sr-code-bg: #000;
	--sr-code-fg: #fff;
	--sr-code-accent: #7dffa6;
	--sr-low-bg: #0d2914; --sr-low-fg: #7dffa6;
	--sr-medium-bg: #332600; --sr-medium-fg: #ffcf5c;
	--sr-high-bg: #330d0d; --sr-high-fg: #ff8f87;
	--sr-low-border: #4caf6a; --sr-medium-border: #d8a32a; --sr-high-border: #e05a52;
	--sr-info-bg: #10243a; --sr-info-border: #3a5f88; --sr-info-fg: #aad4ff;
	--sr-warn-bg: #332600; --sr-warn-border: #5a4400; --sr-warn-fg: #ffcf5c;
	--sr-danger-bg: #330d0d; --sr-danger-border: #6b2222; --sr-danger-fg: #ff8f87;
	--sr-pending-bg: #181818; --sr-pending-border: #555; --sr-pending-fg: #c8cdd4;
	--sr-ok-bg: #0d2914; --sr-ok-border: #2f6b3c; --sr-ok-fg: #7dffa6;
	--sr-err-bg: #2c0d0d; --sr-err-border: #6b2222; --sr-err-fg: #ff8f87;
	--sr-btn-cancel-bg: #333; --sr-btn-cancel-fg: #fff; --sr-btn-cancel-hover: #444;
	--sr-btn-run-disabled: #2f4a66;
}
.sr-layout { display: flex; gap: 12px; flex: 1 1 auto; min-height: 0; box-sizing: border-box; }
.sr-catalog { flex: 0 0 280px; overflow-y: auto; border-right: 1px solid var(--sr-border); padding-right: 10px; }
.sr-catalog-title { font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: .04em; color: var(--sr-muted); margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between; gap: 6px; }
.sr-catalog-inactive-count { font-weight: 700; font-size: 10px; letter-spacing: 0; text-transform: none; color: var(--sr-faint); background: var(--sr-card-bg); border: 1px solid var(--sr-border); border-radius: 10px; padding: 1px 7px; white-space: nowrap; }
.script-runner .sr-card { -webkit-appearance: none; appearance: none; display: block; width: 100%; max-width: 100%; height: auto; min-height: 0; box-sizing: border-box; text-align: left; white-space: normal; background: var(--sr-card-bg); border: 1px solid var(--sr-border); border-radius: 6px; margin: 0 0 8px; padding: 10px; cursor: pointer; transition: border-color .12s, background .12s; color: var(--sr-fg); font-family: inherit; font-size: 13px; line-height: 1.3; }
.script-runner .sr-card:hover { border-color: var(--sr-card-hover-border); background: var(--sr-card-hover-bg); }
.script-runner .sr-card-active { border-color: var(--sr-accent); background: var(--sr-accent-soft-bg); box-shadow: inset 3px 0 0 var(--sr-accent); }
.script-runner .sr-card-inactive { cursor: not-allowed; opacity: .55; }
.script-runner .sr-card-inactive:hover { border-color: var(--sr-border); background: var(--sr-card-bg); }
.sr-card-name { font-weight: 600; margin-bottom: 2px; }
.sr-card-desc { font-size: 12px; color: var(--sr-muted); margin-bottom: 6px; line-height: 1.3; }
.sr-card-meta { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.sr-card-cat { font-size: 11px; color: var(--sr-faint); }
.sr-badge { font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 10px; text-transform: uppercase; letter-spacing: .03em; }
.sr-danger-low { background: var(--sr-low-bg); color: var(--sr-low-fg); }
.sr-danger-medium { background: var(--sr-medium-bg); color: var(--sr-medium-fg); }
.sr-danger-high { background: var(--sr-high-bg); color: var(--sr-high-fg); }
.sr-badge-inactive { background: var(--sr-card-bg); color: var(--sr-faint); border: 1px solid var(--sr-border); }
.sr-detail { flex: 1 1 auto; overflow-y: auto; padding: 0 4px; }
.sr-placeholder, .sr-empty { color: var(--sr-faint); padding: 16px; font-style: italic; }
.sr-detail-header { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.sr-detail-title { margin: 0; font-size: 16px; color: var(--sr-fg); }
.sr-detail-desc { color: var(--sr-muted); margin: 0 0 12px; line-height: 1.4; }
.sr-alert { border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; line-height: 1.45; border: 1px solid; }
.sr-alert-title { font-weight: 700; margin-bottom: 4px; }
.sr-alert-msg { font-size: 12.5px; }
.sr-alert-info { background: var(--sr-info-bg); border-color: var(--sr-info-border); color: var(--sr-info-fg); }
.sr-alert-warning { background: var(--sr-warn-bg); border-color: var(--sr-warn-border); color: var(--sr-warn-fg); }
.sr-alert-danger { background: var(--sr-danger-bg); border-color: var(--sr-danger-border); color: var(--sr-danger-fg); }
.sr-form { max-width: 680px; }
.sr-field { margin-bottom: 14px; }
.sr-field-label { display: block; font-weight: 600; margin-bottom: 4px; color: var(--sr-fg); }
.sr-req { color: var(--sr-high-fg); }
.sr-input, .sr-form textarea, .sr-form select { width: 100%; box-sizing: border-box; padding: 7px 9px; border: 1px solid var(--sr-border-strong); border-radius: 5px; font-size: 13px; font-family: inherit; background: var(--sr-input-bg); color: var(--sr-fg); }
.sr-input::placeholder, .sr-form textarea::placeholder { color: var(--sr-faint); }
.sr-input:focus, .sr-form textarea:focus, .sr-form select:focus { outline: none; border-color: var(--sr-accent); box-shadow: 0 0 0 2px rgba(78,161,240,.25); }
.sr-field-flag { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; color: var(--sr-fg); }
.sr-field-flag input { width: 16px; height: 16px; }
.sr-field-help { font-size: 12px; color: var(--sr-faint); margin-top: 4px; line-height: 1.3; }
.sr-field-error { font-size: 12px; color: var(--sr-high-fg); margin-top: 4px; display: none; }
.sr-field-error-active { display: block; }
.sr-action-list { margin: 18px 0 6px; }
.sr-action-list-title { font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: .04em; color: var(--sr-muted); margin-bottom: 8px; }
.sr-action-grid { display: flex; flex-wrap: wrap; gap: 10px; align-items: stretch; }
.sr-action-card { flex: 1 1 300px; min-width: 280px; display: flex; flex-direction: column; border: 1px solid var(--sr-border); border-left-width: 4px; border-radius: 6px; padding: 12px; background: var(--sr-surface); }
.sr-danger-low-border { border-left-color: var(--sr-low-border); }
.sr-danger-medium-border { border-left-color: var(--sr-medium-border); }
.sr-danger-high-border { border-left-color: var(--sr-high-border); }
.sr-action-head { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.sr-action-title { font-weight: 700; font-size: 14px; color: var(--sr-fg); }
.sr-action-desc { font-size: 12.5px; color: var(--sr-muted); line-height: 1.4; margin-bottom: 8px; }
.sr-action-preview { background: var(--sr-code-bg); border-radius: 5px; padding: 8px 10px; margin-bottom: 10px; overflow-x: auto; flex: 1 0 auto; }
.sr-action-grid .sr-btn-run { margin-top: auto; }
.sr-action-preview-label { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: var(--sr-faint); margin-bottom: 3px; }
.sr-action-preview-code { color: var(--sr-code-accent); font-size: 12px; white-space: pre-wrap; word-break: break-word; }
/* Botoes: neutraliza o reset/estilo nativo de button do Zabbix. */
.script-runner .sr-btn { -webkit-appearance: none; appearance: none; display: inline-block; width: auto; max-width: 100%; height: auto; min-height: 0; box-sizing: border-box; border: 1px solid transparent; border-radius: 5px; margin: 0; padding: 8px 18px; font-size: 13px; line-height: 1.2; font-weight: 600; font-family: inherit; text-align: center; text-decoration: none; cursor: pointer; vertical-align: middle; }
.script-runner .sr-btn-run { background: var(--sr-accent); border-color: var(--sr-accent); color: var(--sr-on-accent); }
.script-runner .sr-btn-run:hover:not(:disabled) { background: var(--sr-accent-strong); border-color: var(--sr-accent-strong); }
.script-runner .sr-btn-run:disabled { background: var(--sr-btn-run-disabled); border-color: var(--sr-btn-run-disabled); color: var(--sr-on-accent); cursor: default; opacity: .85; }
.script-runner .sr-btn-cancel { background: var(--sr-btn-cancel-bg); border-color: var(--sr-btn-cancel-bg); color: var(--sr-btn-cancel-fg); }
.script-runner .sr-btn-cancel:hover:not(:disabled) { background: var(--sr-btn-cancel-hover); border-color: var(--sr-btn-cancel-hover); }
.sr-timeout-info { font-size: 12px; color: var(--sr-faint); margin: 6px 0 0; }
.sr-result-box { margin-top: 18px; max-width: 680px; }
.sr-result { border-radius: 6px; padding: 12px 14px; border: 1px solid; }
.sr-result-pending { background: var(--sr-pending-bg); border-color: var(--sr-pending-border); color: var(--sr-pending-fg); }
.sr-result-ok { background: var(--sr-ok-bg); border-color: var(--sr-ok-border); }
.sr-result-err { background: var(--sr-err-bg); border-color: var(--sr-err-border); }
.sr-result-banner { font-weight: 700; text-transform: uppercase; font-size: 12px; letter-spacing: .04em; margin-bottom: 6px; }
.sr-result-ok .sr-result-banner { color: var(--sr-ok-fg); }
.sr-result-err .sr-result-banner { color: var(--sr-err-fg); }
.sr-result-msg { line-height: 1.4; color: var(--sr-fg); }
.sr-kv { margin-top: 10px; display: grid; gap: 4px; }
.sr-kv-row { display: flex; gap: 8px; font-size: 12px; }
.sr-kv-key { font-weight: 600; color: var(--sr-muted); min-width: 120px; }
.sr-kv-val { color: var(--sr-fg); word-break: break-word; }
.sr-tech { margin-top: 12px; }
.sr-tech summary { cursor: pointer; font-size: 12px; font-weight: 600; color: var(--sr-muted); }
.sr-tech-meta { font-size: 12px; color: var(--sr-muted); margin: 8px 0; }
.sr-stream { margin-top: 8px; }
.sr-stream-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--sr-faint); margin-bottom: 2px; }
.sr-stream-pre { background: var(--sr-code-bg); color: var(--sr-code-fg); padding: 10px; border-radius: 5px; font-size: 12px; max-height: 240px; overflow: auto; white-space: pre-wrap; word-break: break-word; margin: 0; }
.sr-catalog-errors { background: var(--sr-warn-bg); border: 1px solid var(--sr-warn-border); border-radius: 6px; padding: 8px 10px; margin-bottom: 10px; }
.sr-catalog-errors-title { font-size: 11px; font-weight: 700; color: var(--sr-warn-fg); margin-bottom: 4px; }
.sr-catalog-error-row { font-size: 11px; color: var(--sr-warn-fg); line-height: 1.3; }
.sr-macros { flex: 0 0 auto; border: 1px solid var(--sr-border); border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; background: var(--sr-surface-soft); }
.sr-macros-head { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; }
.sr-macros-title { font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: .04em; color: var(--sr-muted); }
.sr-macros-hint { font-size: 11.5px; color: var(--sr-faint); }
.sr-host-picker { position: relative; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sr-host-input { max-width: 320px; }
.sr-host-results { position: absolute; top: 100%; left: 0; right: 0; max-width: 320px; background: var(--sr-popover-bg); border: 1px solid var(--sr-border-strong); border-radius: 5px; box-shadow: 0 8px 24px var(--sr-shadow); z-index: 50; max-height: 240px; overflow-y: auto; display: none; }
.sr-host-results-open { display: block; }
.script-runner .sr-host-item { -webkit-appearance: none; appearance: none; display: block; width: 100%; height: auto; min-height: 0; box-sizing: border-box; text-align: left; white-space: normal; background: none; border: none; margin: 0; padding: 7px 10px; cursor: pointer; font-size: 13px; line-height: 1.3; font-family: inherit; color: var(--sr-fg); border-bottom: 1px solid var(--sr-border); }
.sr-host-item:hover { background: var(--sr-card-hover-bg); }
.sr-host-item-tech { display: block; font-size: 11px; color: var(--sr-faint); }
.sr-host-current { display: flex; align-items: center; gap: 8px; }
.sr-host-current-name { font-weight: 600; color: var(--sr-accent-fg); }
.script-runner .sr-host-clear { -webkit-appearance: none; appearance: none; height: auto; min-height: 0; background: none; border: none; padding: 0; margin: 0; color: var(--sr-accent); cursor: pointer; font-size: 12px; line-height: 1.2; text-decoration: underline; font-family: inherit; }
.sr-macros-box { margin-top: 10px; }
.sr-macros-loading, .sr-macros-empty { font-size: 12px; color: var(--sr-faint); font-style: italic; }
.sr-macros-msg { font-size: 12px; color: var(--sr-ok-fg); margin-bottom: 6px; min-height: 14px; }
.sr-macros-filter { max-width: 320px; margin-bottom: 8px; }
.sr-macros-table { display: grid; gap: 2px; max-height: 220px; overflow-y: auto; }
.sr-macro-row { display: flex; align-items: center; gap: 10px; padding: 4px 6px; border-radius: 4px; }
.sr-macro-row:hover { background: var(--sr-card-hover-bg); }
.sr-macro-name { font-family: monospace; font-size: 12px; color: var(--sr-fg); flex: 0 0 240px; word-break: break-all; }
.sr-macro-value { font-size: 12px; color: var(--sr-muted); flex: 1 1 auto; word-break: break-all; }
.sr-macro-secret { color: var(--sr-medium-fg); font-style: italic; letter-spacing: .1em; }
.sr-macro-source { font-size: 10px; text-transform: uppercase; color: var(--sr-faint); background: var(--sr-card-bg); padding: 1px 5px; border-radius: 8px; }
.script-runner .sr-macro-insert { -webkit-appearance: none; appearance: none; display: inline-block; width: auto; height: auto; min-height: 0; box-sizing: border-box; background: var(--sr-accent-soft-bg); border: 1px solid var(--sr-accent-soft-border); color: var(--sr-accent); border-radius: 4px; margin: 0; padding: 2px 8px; font-size: 11px; line-height: 1.3; cursor: pointer; font-family: inherit; }
.script-runner .sr-macro-insert:hover { background: var(--sr-card-hover-bg); }
.sr-modal-overlay { position: fixed; inset: 0; background: var(--sr-overlay); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.sr-modal { background: var(--sr-popover-bg); color: var(--sr-fg); border-radius: 8px; padding: 20px; max-width: 460px; width: 90%; box-shadow: 0 12px 40px var(--sr-shadow-strong); }
.sr-modal-title { font-weight: 700; font-size: 16px; margin-bottom: 10px; color: var(--sr-fg); }
.sr-modal-body { line-height: 1.4; margin-bottom: 18px; color: var(--sr-muted); }
.sr-modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
`;
		document.head.appendChild(style);
	}
}
