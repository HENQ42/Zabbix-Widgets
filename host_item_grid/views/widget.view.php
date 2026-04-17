<?php declare(strict_types = 0);

/**
 * Host Item Grid widget view.
 *
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

if (empty($data['hosts'])) {
	$view->addItem(
		(new CTableInfo())->setNoDataMessage(_('No hosts or items to display.'))
	);
}
else {
	$columns = (int) ($data['columns'] ?? 3);
	if ($columns < 1) {
		$columns = 1;
	}

	$color_stable = $data['color_stable'] !== '' ? $data['color_stable'] : '4CAF50';
	$color_critical = $data['color_critical'] !== '' ? $data['color_critical'] : 'E53935';
	$color_warning = (isset($data['color_warning']) && $data['color_warning'] !== '') ? $data['color_warning'] : 'FFA726';

	$css = <<<CSS
		.higrid-wrap {
			--higrid-box-bg: #ffffff;
			--higrid-box-border: #ccd5db;
			--higrid-box-shadow: 0 1px 2px rgba(0,0,0,0.04);
			--higrid-text: inherit;
			--higrid-header-border: #e4e9ec;
			--higrid-row-border: #eef1f3;
		}
		:root[color-scheme="dark"] .higrid-wrap {
			--higrid-box-bg: #26282b;
			--higrid-box-border: #3a3f44;
			--higrid-box-shadow: 0 1px 2px rgba(0,0,0,0.4);
			--higrid-text: #e6e6e6;
			--higrid-header-border: #3a3f44;
			--higrid-row-border: #2f3236;
		}
		.higrid-wrap { display: grid; gap: 12px; padding: 12px; height: 100%; overflow-y: auto; box-sizing: border-box; align-content: start; }
		.higrid-box {
			border: 1px solid var(--higrid-box-border);
			border-radius: 8px;
			padding: 10px 14px;
			background: var(--higrid-box-bg);
			box-shadow: var(--higrid-box-shadow);
			color: var(--higrid-text);
			display: flex;
			flex-direction: column;
			min-width: 0;
			overflow: hidden;
		}
		.higrid-header {
			display: flex;
			align-items: center;
			gap: 8px;
			font-weight: 600;
			font-size: 13px;
			padding-bottom: 6px;
			margin-bottom: 6px;
			border-bottom: 1px solid var(--higrid-header-border);
		}
		.higrid-header-name { flex: 1 1 auto; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.higrid-badge {
			flex-shrink: 0;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 2px 8px;
			border-radius: 10px;
			font-size: 11px;
			font-weight: 700;
			color: #ffffff;
			line-height: 1.4;
		}
		.higrid-row { display: flex; justify-content: space-between; align-items: center; padding: 4px 0; }
		.higrid-row + .higrid-row { border-top: 1px dashed var(--higrid-row-border); }
		.higrid-label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-right: 12px; min-width: 0; flex: 1 1 auto; }
		.higrid-value { white-space: nowrap; flex-shrink: 0; }
		.higrid-timeline {
			display: grid;
			grid-template-columns: repeat(12, 1fr);
			gap: 3px;
			margin-bottom: 8px;
		}
		.higrid-cell {
			aspect-ratio: 1 / 1;
			border-radius: 3px;
			box-shadow: inset 0 0 0 1px rgba(0,0,0,0.08);
			transition: transform 0.15s ease;
		}
		.higrid-cell:hover {
			transform: scale(1.15);
			box-shadow: inset 0 0 0 1px rgba(0,0,0,0.25), 0 2px 4px rgba(0,0,0,0.2);
		}
		.higrid-cell.has-problems { cursor: pointer; }
		.higrid-cell.future {
			background-color: transparent !important;
			box-shadow: inset 0 0 0 1px rgba(128,128,128,0.25);
		}
		dialog.higrid-dialog {
			border: 1px solid #ccd5db;
			border-radius: 8px;
			padding: 0;
			max-width: 520px;
			width: 90%;
			background: #ffffff;
			color: #1f2328;
			box-shadow: 0 6px 24px rgba(0,0,0,0.25);
		}
		dialog.higrid-dialog::backdrop { background: rgba(0,0,0,0.35); }
		:root[color-scheme="dark"] dialog.higrid-dialog { background: #26282b; color: #e6e6e6; border-color: #3a3f44; }
		.higrid-dlg-head { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid rgba(128,128,128,0.25); font-weight: 600; }
		.higrid-dlg-close { background: transparent; border: 0; font-size: 18px; cursor: pointer; color: inherit; line-height: 1; padding: 0 4px; }
		.higrid-dlg-body { padding: 12px 14px; max-height: 60vh; overflow-y: auto; }
		.higrid-dlg-item { padding: 8px 0; border-bottom: 1px dashed rgba(128,128,128,0.3); }
		.higrid-dlg-item:last-child { border-bottom: 0; }
		.higrid-dlg-name { font-weight: 600; margin-bottom: 4px; }
		.higrid-dlg-meta { font-size: 12px; opacity: 0.85; }
		.higrid-sev { display: inline-block; padding: 1px 7px; border-radius: 8px; font-size: 11px; font-weight: 700; color: #fff; margin-left: 6px; }
	CSS;

	$grid = (new CDiv())
		->addClass('higrid-wrap')
		->addStyle('grid-template-columns: repeat('.$columns.', minmax(0, 1fr));');

	foreach ($data['hosts'] as $host) {
		$stripe_color = ((int) ($host['state'] ?? 0) === 1) ? $color_critical : $color_stable;

		$box = (new CDiv())
			->addClass('higrid-box')
			->addStyle('border-left: 5px solid #'.$stripe_color.';');

		$is_critical = (int) ($host['state'] ?? 0) === 1;
		$badge_label = $is_critical ? _('Crítico') : _('Estável');

		$badge = (new CSpan($badge_label))
			->addClass('higrid-badge')
			->addStyle('background-color: #'.$stripe_color.';');

		$header = (new CDiv([
			(new CSpan($host['name']))->addClass('higrid-header-name'),
			$badge
		]))->addClass('higrid-header');

		$box->addItem($header);

		if (!empty($host['timeline'])) {
			$timeline_rows = [
				array_slice($host['timeline'], 0, 12),
				array_slice($host['timeline'], 12, 12)
			];

			foreach ($timeline_rows as $row_cells) {
				$timeline_div = (new CDiv())->addClass('higrid-timeline');

				foreach ($row_cells as $cell) {
					$cell_color = $color_stable;
					$state_label = _('Estável');
					$extra_class = '';

					if ($cell['state'] === 'future') {
						$state_label = _('Futuro');
						$extra_class = ' future';
					}
					elseif ($cell['state'] === 'critical') {
						$cell_color = $color_critical;
						$state_label = _('Crítico');
					}
					elseif ($cell['state'] === 'warning') {
						$cell_color = $color_warning;
						$state_label = _('Atenção');
					}

					$problems = $cell['problems'] ?? [];
					$count = count($problems);

					$tooltip = $cell['hour_label'].' — '.$state_label;
					if ($count > 0) {
						$tooltip .= ' ('.$count.' '.($count === 1 ? _('problema') : _('problemas')).')';
					}

					$cell_div = (new CDiv())
						->addClass('higrid-cell'.$extra_class)
						->setAttribute('title', $tooltip);

					if ($cell['state'] !== 'future') {
						$cell_div->addStyle('background-color: #'.$cell_color.';');
					}

					if ($count > 0) {
						$cell_div->addClass('has-problems');
						$cell_div->setAttribute('data-problems', json_encode($problems, JSON_UNESCAPED_UNICODE));
						$cell_div->setAttribute('data-hour', $cell['hour_label']);
					}

					$timeline_div->addItem($cell_div);
				}

				$box->addItem($timeline_div);
			}
		}

		foreach ($host['rows'] as $item) {
			$row = (new CDiv())->addClass('higrid-row');

			$label = (new CSpan($item['label']))->addClass('higrid-label');

			$value_style = '';
			if ($item['color'] !== '') {
				$value_style .= 'color: #'.$item['color'].';';
			}
			if ($item['bold']) {
				$value_style .= ' font-weight: bold;';
			}

			$value = (new CSpan($item['value'] !== '' ? $item['value'] : _('No data')))
				->addClass('higrid-value');

			if ($value_style !== '') {
				$value->addStyle($value_style);
			}

			$row->addItem([$label, $value]);
			$box->addItem($row);
		}

		$grid->addItem($box);
	}

	$view->addItem([
		new CTag('style', true, $css),
		$grid
	]);
}

$view->show();
