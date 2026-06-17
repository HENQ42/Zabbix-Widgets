<?php declare(strict_types = 0);

/**
 * Host Macros widget view.
 *
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

$view_mode = (int) ($data['view_mode'] ?? 0);
$header_color = ($data['header_color'] !== '') ? $data['header_color'] : '1976D2';

// Returns an array: [value-span] or [value-span, eye-button] when toggleable.
// When $is_link is set (macro configured as a link), the value is rendered as
// a clickable anchor. The link target is built from a template:
// $link_prefix.$value.$link_suffix (e.g. "https://" + value + ":8443"). Only
// when the result has no http(s):// scheme is http:// prepended. The displayed
// text is always the original value, untouched by prefix/suffix.
$render_value = function ($value, $real_value, $toggleable, $value_class, $is_link = false, $link_prefix = '', $link_suffix = '') {
	if ($is_link && !$toggleable && is_string($value) && trim($value) !== '') {
		$target = $link_prefix.$value.$link_suffix;
		$href = (preg_match('#^https?://#i', $target) === 1)
			? $target
			: 'http://'.$target;

		$link = (new CTag('a', true, $value))
			->setAttribute('href', $href)
			->setAttribute('target', '_blank')
			->setAttribute('rel', 'noopener noreferrer')
			->setAttribute('title', (string) $value)
			->addClass($value_class)
			->addClass('hmacro-value-link');

		return [$link];
	}

	$span = (new CSpan($value))
		->addClass($value_class)
		->setAttribute('title', (string) $value);

	if (!$toggleable) {
		return [$span];
	}

	$span->addClass('hmacro-secret-value');
	$span->setAttribute('data-real', (string) $real_value);
	$span->setAttribute('data-masked', '******');

	$eye = (new CTag('span', true, ''))
		->addClass('hmacro-eye')
		->setAttribute('role', 'button')
		->setAttribute('tabindex', '0')
		->setAttribute('aria-label', _('Toggle value'))
		->setAttribute('title', _('Toggle value'));

	return [$span, $eye];
};

$css = <<<CSS
	/* Light theme (default). Background is always transparent so the widget
	   inherits the dashboard background; only text/separator colors change. */
	.hmacro-wrap {
		--hmacro-text: #1f2328;
		--hmacro-subtle: #586069;
		--hmacro-border: rgba(0, 0, 0, 0.10);
		--hmacro-row-hover: rgba(0, 0, 0, 0.035);
		--hmacro-stripe: rgba(0, 0, 0, 0.025);
		--hmacro-toolbar-bg: rgba(255, 255, 255, 0.45);
	}
	:root[color-scheme="dark"] .hmacro-wrap {
		--hmacro-text: #dfe3e7;
		--hmacro-subtle: #9aa0a6;
		--hmacro-border: rgba(255, 255, 255, 0.12);
		--hmacro-row-hover: rgba(255, 255, 255, 0.06);
		--hmacro-stripe: rgba(255, 255, 255, 0.04);
		--hmacro-toolbar-bg: rgba(58, 62, 68, 0.45);
	}
	.hmacro-wrap {
		height: 100%;
		overflow-y: auto;
		overscroll-behavior: contain;
		padding: 12px;
		box-sizing: border-box;
		color: var(--hmacro-text);
		background: transparent;
	}

	/* --- Error / empty --- */
	.hmacro-error {
		display: flex;
		align-items: center;
		justify-content: center;
		height: 100%;
		color: var(--hmacro-subtle);
		font-size: 13px;
		font-style: italic;
	}

	/* --- Single host mode --- */
	.hmacro-single {
		background: transparent;
		border: none;
		border-radius: 0;
		box-shadow: none;
		overflow: hidden;
	}
	.hmacro-host-header {
		padding: 8px 16px;
		font-size: 12px;
		font-weight: 700;
		color: #ffffff;
		letter-spacing: 0.3px;
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
	}
	.hmacro-host-name-text {
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
		min-width: 0;
		flex: 1 1 auto;
	}
	.hmacro-host-link {
		display: inline-block;
		width: 16px;
		height: 16px;
		flex-shrink: 0;
		background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6'/%3E%3Cpolyline points='15 3 21 3 21 9'/%3E%3Cline x1='10' y1='14' x2='21' y2='3'/%3E%3C/svg%3E");
		background-repeat: no-repeat;
		background-position: center;
		background-size: 14px;
		opacity: 0.75;
		transition: opacity 0.15s ease;
		text-decoration: none !important;
	}
	.hmacro-host-link:hover { opacity: 1; }
	.hmacro-row {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		padding: 8px 16px;
		font-size: 13px;
		border-bottom: 1px solid var(--hmacro-border);
	}
	.hmacro-row:last-child {
		border-bottom: none;
	}
	.hmacro-row:nth-child(even) {
		background: var(--hmacro-stripe);
	}
	.hmacro-row:hover {
		background: var(--hmacro-row-hover);
	}
	.hmacro-macro-name {
		color: var(--hmacro-subtle);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
		padding-right: 24px;
		font-family: monospace;
		font-size: 12px;
		flex: 1 1 auto;
		min-width: 0;
	}
	.hmacro-macro-value-wrap {
		flex-shrink: 0;
		text-align: right;
	}
	.hmacro-macro-value {
		font-weight: 700;
	}
	.hmacro-value-link {
		color: #1976D2;
		text-decoration: underline;
		word-break: break-all;
	}
	.hmacro-value-link:hover {
		text-decoration: none;
	}
	.hmacro-type-badge {
		display: inline-block;
		padding: 1px 6px;
		border-radius: 8px;
		font-size: 10px;
		font-weight: 600;
		color: #fff;
		margin-left: 6px;
		vertical-align: middle;
	}
	.hmacro-eye {
		display: inline-block;
		width: 16px;
		height: 16px;
		margin-left: 8px;
		padding: 0;
		border: 0 !important;
		outline: none !important;
		box-shadow: none !important;
		background-color: var(--hmacro-subtle);
		-webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/%3E%3Ccircle cx='12' cy='12' r='3'/%3E%3C/svg%3E") no-repeat center / 14px;
		mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'/%3E%3Ccircle cx='12' cy='12' r='3'/%3E%3C/svg%3E") no-repeat center / 14px;
		cursor: pointer;
		opacity: 0.6;
		vertical-align: middle;
		transition: opacity 0.15s ease;
		user-select: none;
		box-sizing: content-box;
	}
	.hmacro-eye:focus,
	.hmacro-eye:active,
	.hmacro-eye:focus-visible {
		outline: none !important;
		box-shadow: none !important;
		border: 0 !important;
	}
	.hmacro-eye:hover { opacity: 1; }
	.hmacro-eye.is-shown {
		-webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24'/%3E%3Cline x1='1' y1='1' x2='23' y2='23'/%3E%3C/svg%3E") no-repeat center / 14px;
		mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24'/%3E%3Cline x1='1' y1='1' x2='23' y2='23'/%3E%3C/svg%3E") no-repeat center / 14px;
		opacity: 0.85;
	}
	.hmacro-count {
		font-size: 11px;
		color: var(--hmacro-subtle);
		padding: 6px 16px 10px;
	}

	/* --- Group mode search bar --- */
	.hmacro-toolbar {
		display: flex;
		justify-content: flex-start;
		align-items: center;
		gap: 6px;
		min-height: 24px;
		position: sticky;
		top: 0;
		z-index: 2;
		margin: -12px -12px 18px;
		padding: 12px 12px 10px;
		background: var(--hmacro-toolbar-bg);
		backdrop-filter: blur(8px);
		-webkit-backdrop-filter: blur(8px);
	}
	.hmacro-search-toggle {
		display: inline-block;
		width: 20px;
		height: 20px;
		padding: 0;
		border: 0 !important;
		outline: none !important;
		box-shadow: none !important;
		background-color: var(--hmacro-subtle);
		-webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E") no-repeat center / 16px;
		mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E") no-repeat center / 16px;
		cursor: pointer;
		opacity: 0.6;
		transition: opacity 0.15s ease;
	}
	.hmacro-search-toggle:hover { opacity: 1; }
	.hmacro-search-toggle:focus,
	.hmacro-search-toggle:active,
	.hmacro-search-toggle:focus-visible {
		outline: none !important;
		box-shadow: none !important;
		border: 0 !important;
	}
	.hmacro-search-input {
		width: 0;
		padding: 4px 0;
		font-size: 12px;
		border: 1px solid transparent;
		border-radius: 4px;
		background: transparent;
		color: var(--hmacro-text);
		opacity: 0;
		pointer-events: none;
		transition: width 0.2s ease, padding 0.2s ease, opacity 0.15s ease, border-color 0.15s ease;
		box-sizing: border-box;
	}
	.hmacro-toolbar.is-open .hmacro-search-input {
		width: 180px;
		padding: 4px 8px;
		opacity: 1;
		pointer-events: auto;
		border-color: var(--hmacro-border);
		background: transparent;
	}
	.hmacro-search-input:focus {
		outline: none;
		border-color: #1976D2;
	}
	.hmacro-host-hidden { display: none !important; }

	/* --- Group mode --- */
	.hmacro-grid {
		display: grid;
		gap: 12px;
		align-content: start;
	}
	.hmacro-grid .hmacro-single {
		border-bottom: 1px solid var(--hmacro-border);
	}
	.hmacro-grid .hmacro-single:last-child {
		border-bottom: none;
	}
	.hmacro-host-name {
		color: var(--hmacro-subtle);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
		padding-right: 24px;
		font-size: 13px;
		flex: 1 1 auto;
		min-width: 0;
	}
	.hmacro-group-value {
		font-weight: 700;
		font-size: 13px;
		word-break: break-all;
		text-align: right;
		flex-shrink: 0;
	}
	.hmacro-group-empty {
		color: var(--hmacro-subtle);
		font-style: italic;
		font-size: 12px;
		text-align: right;
		flex-shrink: 0;
	}
CSS;

// Handle errors / empty state.
if (!empty($data['error'])) {
	$view->addItem([
		new CTag('style', true, $css),
		(new CDiv())
			->addClass('hmacro-wrap')
			->addItem(
				(new CDiv($data['error']))->addClass('hmacro-error')
			)
	]);
	$view->show();

	return;
}

if ($view_mode === 0) {
	// ========== SINGLE HOST MODE ==========

	if (empty($data['macros']) || !$data['host']) {
		$view->addItem(
			(new CTableInfo())->setNoDataMessage(_('No macros found for this host.'))
		);
		$view->show();

		return;
	}

	$wrap = (new CDiv())->addClass('hmacro-wrap');
	$card = (new CDiv())->addClass('hmacro-single');

	// Header with host name + link to host dashboard.
	$host_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'host.dashboard.view')
		->setArgument('hostid', $data['host']['hostid'])
		->getUrl();

	$header = (new CDiv([
		(new CSpan($data['host']['name']))->addClass('hmacro-host-name-text'),
		(new CTag('a', true, ''))
			->setAttribute('href', $host_url)
			->setAttribute('title', _('Open host dashboard'))
			->setAttribute('aria-label', _('Open host dashboard'))
			->addClass('hmacro-host-link')
	]))
		->addClass('hmacro-host-header')
		->addStyle('background-color: #'.$header_color.';');

	$card->addItem($header);

	// Macro rows.
	$rows_container = (new CDiv());

	foreach ($data['macros'] as $macro) {
		$row = (new CDiv())->addClass('hmacro-row');

		// Macro name + type badge.
		$name_parts = [(new CSpan($macro['macro']))->setAttribute('title', (string) $macro['macro'])];

		if ((int) $macro['type'] === 1) {
			$name_parts[] = (new CSpan('Secret'))
				->addClass('hmacro-type-badge')
				->addStyle('background:#E53935;');
		}
		elseif ((int) $macro['type'] === 2) {
			$name_parts[] = (new CSpan('Vault'))
				->addClass('hmacro-type-badge')
				->addStyle('background:#7B1FA2;');
		}

		$name_cell = (new CDiv($name_parts))->addClass('hmacro-macro-name');

		// Value + (optional) eye toggle + description.
		$value_parts = $render_value(
			$macro['value'],
			$macro['real_value'] ?? null,
			!empty($macro['toggleable']),
			'hmacro-macro-value',
			!empty($macro['is_link']),
			$macro['link_prefix'] ?? '',
			$macro['link_suffix'] ?? ''
		);

		$value_cell = (new CDiv($value_parts))->addClass('hmacro-macro-value-wrap');

		$row->addItem([$name_cell, $value_cell]);
		$rows_container->addItem($row);
	}

	$card->addItem($rows_container);

	// Count.
	$count = count($data['macros']);
	$card->addItem(
		(new CDiv(
			$count.' '.($count === 1 ? _('macro') : _('macros'))
		))->addClass('hmacro-count')
	);

	$wrap->addItem($card);

	$view->addItem([
		new CTag('style', true, $css),
		$wrap
	]);
}
elseif ($view_mode === 1) {
	// ========== GROUP MODE (filtered) ==========

	if (empty($data['hosts'])) {
		$view->addItem(
			(new CTableInfo())->setNoDataMessage(_('No hosts found.'))
		);
		$view->show();

		return;
	}

	$wrap = (new CDiv())->addClass('hmacro-wrap');

	$toolbar = (new CDiv())->addClass('hmacro-toolbar is-open')->addItem([
		(new CTag('span', true, ''))
			->addClass('hmacro-search-toggle')
			->setAttribute('role', 'button')
			->setAttribute('tabindex', '0')
			->setAttribute('aria-label', _('Search hosts'))
			->setAttribute('title', _('Search hosts')),
		(new CTag('input', false))
			->setAttribute('type', 'text')
			->setAttribute('placeholder', _('Filter hosts…'))
			->addClass('hmacro-search-input')
	]);
	$wrap->addItem($toolbar);

	$grid = (new CDiv())
		->addClass('hmacro-grid')
		->addStyle('grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));');

	foreach ($data['hosts'] as $host_entry) {
		$card = (new CDiv())
			->addClass('hmacro-single')
			->setAttribute('data-host-name', mb_strtolower((string) $host_entry['name']));

		// Header with host name + link to host dashboard.
		$host_url = (new CUrl('zabbix.php'))
			->setArgument('action', 'host.dashboard.view')
			->setArgument('hostid', $host_entry['hostid'])
			->getUrl();

		$card_header = (new CDiv([
			(new CSpan($host_entry['name']))->addClass('hmacro-host-name-text'),
			(new CTag('a', true, ''))
				->setAttribute('href', $host_url)
				->setAttribute('title', _('Open host dashboard'))
				->setAttribute('aria-label', _('Open host dashboard'))
				->addClass('hmacro-host-link')
		]))
			->addClass('hmacro-host-header')
			->addStyle('background-color: #'.$header_color.';');

		$card->addItem($card_header);

		// Macro value row.
		$row = (new CDiv())->addClass('hmacro-row');

		$name_cell = (new CDiv($data['macro_name']))
			->addClass('hmacro-macro-name')
			->setAttribute('title', (string) $data['macro_name']);

		if ($host_entry['value'] !== null) {
			$value_parts = $render_value(
				$host_entry['value'],
				$host_entry['real_value'] ?? null,
				!empty($host_entry['toggleable']),
				'hmacro-group-value',
				!empty($host_entry['is_link']),
				$host_entry['link_prefix'] ?? '',
				$host_entry['link_suffix'] ?? ''
			);
		}
		else {
			$value_parts = [(new CSpan(_('N/A')))->addClass('hmacro-group-empty')];
		}

		$value_cell = (new CDiv($value_parts))->addClass('hmacro-macro-value-wrap');

		$row->addItem([$name_cell, $value_cell]);
		$card->addItem($row);
		$grid->addItem($card);
	}

	$wrap->addItem($grid);

	$view->addItem([
		new CTag('style', true, $css),
		$wrap
	]);
}
elseif ($view_mode === 2) {
	// ========== GROUP MODE — ALL MACROS (no filter) ==========

	if (empty($data['hosts'])) {
		$view->addItem(
			(new CTableInfo())->setNoDataMessage(_('No hosts found.'))
		);
		$view->show();

		return;
	}

	$wrap = (new CDiv())->addClass('hmacro-wrap');

	$toolbar = (new CDiv())->addClass('hmacro-toolbar is-open')->addItem([
		(new CTag('span', true, ''))
			->addClass('hmacro-search-toggle')
			->setAttribute('role', 'button')
			->setAttribute('tabindex', '0')
			->setAttribute('aria-label', _('Search hosts'))
			->setAttribute('title', _('Search hosts')),
		(new CTag('input', false))
			->setAttribute('type', 'text')
			->setAttribute('placeholder', _('Filter hosts…'))
			->addClass('hmacro-search-input')
	]);
	$wrap->addItem($toolbar);

	$grid = (new CDiv())
		->addClass('hmacro-grid')
		->addStyle('grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));');

	foreach ($data['hosts'] as $host_entry) {
		if (empty($host_entry['macros'])) {
			continue;
		}

		$card = (new CDiv())
			->addClass('hmacro-single')
			->setAttribute('data-host-name', mb_strtolower((string) $host_entry['name']));

		// Header with host name + link to host dashboard.
		$host_url = (new CUrl('zabbix.php'))
			->setArgument('action', 'host.dashboard.view')
			->setArgument('hostid', $host_entry['hostid'])
			->getUrl();

		$header = (new CDiv([
			(new CSpan($host_entry['name']))->addClass('hmacro-host-name-text'),
			(new CTag('a', true, ''))
				->setAttribute('href', $host_url)
				->setAttribute('title', _('Open host dashboard'))
				->setAttribute('aria-label', _('Open host dashboard'))
				->addClass('hmacro-host-link')
		]))
			->addClass('hmacro-host-header')
			->addStyle('background-color: #'.$header_color.';');

		$card->addItem($header);

		// Macro rows (same style as single host mode).
		$rows_container = (new CDiv());

		foreach ($host_entry['macros'] as $macro) {
			$row = (new CDiv())->addClass('hmacro-row');

			$name_parts = [(new CSpan($macro['macro']))->setAttribute('title', (string) $macro['macro'])];

			if ((int) $macro['type'] === 1) {
				$name_parts[] = (new CSpan('Secret'))
					->addClass('hmacro-type-badge')
					->addStyle('background:#E53935;');
			}
			elseif ((int) $macro['type'] === 2) {
				$name_parts[] = (new CSpan('Vault'))
					->addClass('hmacro-type-badge')
					->addStyle('background:#7B1FA2;');
			}

			$name_cell = (new CDiv($name_parts))->addClass('hmacro-macro-name');

			$value_parts = $render_value(
				$macro['value'],
				$macro['real_value'] ?? null,
				!empty($macro['toggleable']),
				'hmacro-macro-value',
				!empty($macro['is_link']),
				$macro['link_prefix'] ?? '',
				$macro['link_suffix'] ?? ''
			);

			$value_cell = (new CDiv($value_parts))->addClass('hmacro-macro-value-wrap');

			$row->addItem([$name_cell, $value_cell]);
			$rows_container->addItem($row);
		}

		$card->addItem($rows_container);

		$count = count($host_entry['macros']);
		$card->addItem(
			(new CDiv(
				$count.' '.($count === 1 ? _('macro') : _('macros'))
			))->addClass('hmacro-count')
		);

		$grid->addItem($card);
	}

	$wrap->addItem($grid);

	$view->addItem([
		new CTag('style', true, $css),
		$wrap
	]);
}

$view->show();
