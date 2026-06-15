<?php declare(strict_types = 0);

namespace Modules\MetricPanel\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;
use CParser;
use CRelativeTimeParser;
use CUrl;
use Modules\MetricPanel\Includes\WidgetForm;

class WidgetView extends CControllerDashboardWidgetView {

	// Principal sempre na primeira posicao; cores seguem a partir do indice 1.
	private const PALETTE = [
		'#1f7faa', '#e07b39', '#3fa796', '#b05a8d', '#778bc5',
		'#bfa36f', '#5ab195', '#d6604d', '#9467bd', '#8c6d31',
		'#637939', '#843c39', '#17becf', '#bcbd22', '#7b4173'
	];

	protected function doAction(): void {
		$name = $this->getInput('name', $this->widget->getName());

		$sample_type = (int) ($this->fields_values['sample_type'] ?? WidgetForm::SAMPLE_SINGLE);

		$main_itemid  = $this->firstId($this->fields_values['main_item'] ?? []);
		$used_itemid  = $this->firstId($this->fields_values['used_item'] ?? []);
		$total_itemid = $this->firstId($this->fields_values['total_item'] ?? []);
		$core_itemids = array_values(array_filter(array_map(
			'strval', (array) ($this->fields_values['core_items'] ?? [])
		)));

		$main_label  = trim((string) ($this->fields_values['main_label'] ?? ''));
		$accent      = $this->sanitizeColor((string) ($this->fields_values['accent_color'] ?? '#1f7faa'), '#1f7faa');

		// Conversao de unidades dos valores Used/Total (apenas tipo "Usage %").
		$convert_units = (int) ($this->fields_values['convert_units'] ?? 0) === 1;
		$used_from  = (int) ($this->fields_values['used_from'] ?? 0);
		$used_to    = (int) ($this->fields_values['used_to'] ?? 0);
		$total_from = (int) ($this->fields_values['total_from'] ?? 0);
		$total_to   = (int) ($this->fields_values['total_to'] ?? 0);

		$now = time();
		$time_from = self::parseRelative((string) ($this->fields_values['time_from'] ?? 'now-1h'), $now - 3600, true);
		$time_to   = self::parseRelative((string) ($this->fields_values['time_to'] ?? 'now'), $now, false);
		if ($time_to <= $time_from) {
			$time_to = $time_from + 60;
		}

		$line_thickness = max(0, min(10, (int) ($this->fields_values['line_thickness'] ?? 3)));
		$fill_intensity = max(0, min(10, (int) ($this->fields_values['fill_intensity'] ?? 4)));

		// Quais itens entram no grafico (tem historico buscado) por tipo.
		$chart_ids = [];
		$info_ids = []; // somente valor atual na lateral.
		if ($main_itemid !== null) {
			$chart_ids[] = $main_itemid;
		}
		if ($sample_type === WidgetForm::SAMPLE_USAGE) {
			if ($used_itemid !== null)  $info_ids[] = $used_itemid;
			if ($total_itemid !== null) $info_ids[] = $total_itemid;
		}
		elseif ($sample_type === WidgetForm::SAMPLE_MULTI) {
			foreach ($core_itemids as $cid) {
				$chart_ids[] = $cid;
			}
		}

		$series = [];
		$series_itemids = [];
		$info = [];
		$y_min = null;
		$y_max = null;
		$all_integer = true;
		$default_unit = '';
		$error = null;

		if ($main_itemid === null) {
			$error = _('Select the main item.');
		}
		else {
			$wanted_ids = array_values(array_unique(array_merge($chart_ids, $info_ids)));
			$items = API::Item()->get([
				'output' => ['itemid', 'name', 'units', 'value_type'],
				'itemids' => $wanted_ids,
				'webitems' => true,
				'preservekeys' => true
			]);
			// lastvalue / lastclock para o valor atual da lateral.
			$last = API::Item()->get([
				'output' => ['itemid', 'lastvalue', 'lastclock'],
				'itemids' => $wanted_ids,
				'webitems' => true,
				'preservekeys' => true
			]);

			if (!isset($items[$main_itemid])) {
				$error = _('Main item not found or not accessible.');
			}
			else {
				// Historico apenas dos itens do grafico.
				$points_by_item = $this->fetchHistory($chart_ids, $items, $time_from, $time_to);

				$core_idx = 1; // principal usa a cor de destaque; cores seguem a paleta.
				foreach ($chart_ids as $itemid) {
					if (!isset($items[$itemid])) {
						continue;
					}
					$it = $items[$itemid];
					$is_main = ($itemid === $main_itemid);
					$label = $is_main && $main_label !== ''
						? $main_label
						: $it['name'];

					$pts = $points_by_item[$itemid] ?? [];
					foreach ($pts as $p) {
						if ($y_min === null || $p[1] < $y_min) $y_min = $p[1];
						if ($y_max === null || $p[1] > $y_max) $y_max = $p[1];
					}
					if ((int) $it['value_type'] !== 3) {
						$all_integer = false;
					}
					if ($default_unit === '' && $it['units'] !== '') {
						$default_unit = $it['units'];
					}

					$current = isset($last[$itemid]) && $last[$itemid]['lastvalue'] !== ''
						? (float) $last[$itemid]['lastvalue']
						: ($pts ? $pts[count($pts) - 1][1] : null);

					$series[] = [
						'name'    => $label,
						'color'   => $is_main ? $accent : self::PALETTE[$core_idx++ % count(self::PALETTE)],
						'unit'    => $it['units'],
						'current' => $current,
						'points'  => $pts,
						'is_main' => $is_main,
						'link'    => self::historyGraphUrl([$itemid])
					];
					$series_itemids[] = $itemid;
				}

				// Tipo 2: linha combinada "valor usado / valor total".
				if ($sample_type === WidgetForm::SAMPLE_USAGE && ($used_itemid !== null || $total_itemid !== null)) {
					$used  = $this->infoEntry($used_itemid, $items, $last);
					$total = $this->infoEntry($total_itemid, $items, $last);
					$info[] = [
						'kind'  => 'ratio',
						'name'  => $used['name'] !== '' ? $used['name'] : $total['name'],
						'used'  => [
							'display' => self::formatScalar($used['current'], $used['unit'], $convert_units, $used_from, $used_to)
						],
						'total' => [
							'display' => self::formatScalar($total['current'], $total['unit'], $convert_units, $total_from, $total_to)
						]
					];
				}

				if (!$series) {
					$error = _('No data available for the selected item(s).');
				}
			}
		}

		// Baseline em zero para preenchimento coerente quando todos os valores sao >= 0.
		if ($y_min !== null && $y_min > 0) {
			$y_min = 0;
		}
		if ($default_unit === '%') {
			$y_min = 0;
			if ($y_max === null || $y_max < 100) {
				$y_max = 100;
			}
		}

		$this->setResponse(new CControllerResponseData([
			'name' => $name,
			'metric' => [
				'title'          => $name,
				'accent'         => $accent,
				'dark'           => $this->isDarkTheme(),
				'series'         => $series,
				'info'           => $info,
				'time_from'      => $time_from,
				'time_to'        => $time_to,
				'y_min'          => $y_min,
				'y_max'          => $y_max,
				'unit'           => $default_unit,
				'is_integer'     => $all_integer,
				'line_thickness' => $line_thickness,
				'fill_intensity' => $fill_intensity,
				'link'           => $series_itemids ? self::historyGraphUrl($series_itemids) : null,
				'error'          => $error
			],
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	/**
	 * Busca historico (ou trends para janelas longas) dos itens informados,
	 * bucketizando para ~ um ponto por minuto de janela.
	 *
	 * @return array  itemid => [[ts, value], ...]
	 */
	private function fetchHistory(array $itemids, array $items, int $time_from, int $time_to): array {
		$itemids = array_values(array_filter($itemids, static fn($id) => isset($items[$id])));
		if (!$itemids) {
			return [];
		}

		$span = $time_to - $time_from;
		$use_trends = $span > 86400;
		$bucket_seconds = max(1, (int) ceil($span / 300));
		if ($use_trends) {
			$bucket_seconds = max(3600, $bucket_seconds);
		}

		$by_value_type = [];
		foreach ($itemids as $id) {
			$vt = (int) $items[$id]['value_type'];
			if (in_array($vt, [0, 3], true)) {
				$by_value_type[$vt][] = $id;
			}
		}

		$raw = [];
		foreach ($by_value_type as $vt => $ids) {
			if ($use_trends) {
				$rows = API::Trend()->get([
					'output' => ['itemid', 'clock', 'value_avg'],
					'itemids' => $ids,
					'time_from' => $time_from,
					'time_till' => $time_to
				]) ?: [];
				foreach ($rows as $r) {
					$raw[$r['itemid']][] = [(int) $r['clock'], (float) $r['value_avg']];
				}
			}
			else {
				$rows = API::History()->get([
					'output' => ['itemid', 'clock', 'value'],
					'history' => $vt,
					'itemids' => $ids,
					'time_from' => $time_from,
					'time_till' => $time_to,
					'sortfield' => 'clock',
					'sortorder' => 'ASC'
				]) ?: [];
				foreach ($rows as $r) {
					$raw[$r['itemid']][] = [(int) $r['clock'], (float) $r['value']];
				}
			}
		}

		$out = [];
		foreach ($raw as $id => $pts) {
			usort($pts, static fn($a, $b) => $a[0] <=> $b[0]);
			$out[$id] = self::bucketize($pts, $bucket_seconds);
		}
		return $out;
	}

	/**
	 * Formata um valor escalar Used/Total da lateral.
	 *
	 * - $convert = false: valor cru com ate 2 casas decimais (zeros a direita removidos),
	 *   sem qualquer escala, mantendo a unidade original do item.
	 * - $convert = true: converte de $from_exp para $to_exp na base 1024 e usa o rotulo
	 *   binario de destino (B, KiB, MiB, ...).
	 */
	private static function formatScalar(?float $value, string $orig_unit, bool $convert, int $from_exp, int $to_exp): string {
		if ($value === null) {
			return '–';
		}

		if ($convert) {
			$value *= pow(1024, $from_exp - $to_exp);
			$unit = WidgetForm::BIN_UNITS[$to_exp] ?? $orig_unit;
		}
		else {
			$unit = $orig_unit;
		}

		// Ate 2 casas decimais, sem escala; remove zeros e ponto sobrando.
		$s = rtrim(rtrim(sprintf('%.2f', $value), '0'), '.');

		return $unit !== '' ? $s.' '.$unit : $s;
	}

	private function infoEntry(?string $itemid, array $items, array $last): array {
		if ($itemid === null || !isset($items[$itemid])) {
			return ['name' => '', 'unit' => '', 'current' => null];
		}
		$it = $items[$itemid];
		$current = isset($last[$itemid]) && $last[$itemid]['lastvalue'] !== ''
			? (float) $last[$itemid]['lastvalue']
			: null;
		return ['name' => $it['name'], 'unit' => $it['units'], 'current' => $current];
	}

	/**
	 * URL relativa para a tela de historico/grafico dos itens (mesma usada
	 * pelo widget nativo "Item value"). Relativa a raiz da UI — o front-end
	 * resolve contra a pagina pai, sem acoplar dominio ou caminho da instancia.
	 */
	private static function historyGraphUrl(array $itemids): string {
		return (new CUrl('history.php'))
			->setArgument('action', 'showgraph')
			->setArgument('itemids', array_values($itemids))
			->getUrl();
	}

	private function isDarkTheme(): bool {
		$theme = isset(\CWebUser::$data['theme']) ? (string) \CWebUser::$data['theme'] : '';
		if ($theme === '' || $theme === 'default' || $theme === 'system') {
			try {
				$theme = (string) \CSettingsHelper::getPublic(\CSettingsHelper::DEFAULT_THEME);
			}
			catch (\Throwable $e) {
				$theme = '';
			}
		}
		return stripos($theme, 'dark') !== false;
	}

	private function firstId($value): ?string {
		$arr = array_values(array_filter(array_map('strval', (array) $value)));
		return $arr ? $arr[0] : null;
	}

	private function sanitizeColor(string $color, string $fallback): string {
		$color = trim($color);
		return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : $fallback;
	}

	private static function bucketize(array $pts, int $bucket): array {
		if (!$pts || $bucket <= 1) {
			return $pts;
		}
		$buckets = [];
		foreach ($pts as $p) {
			$key = intdiv($p[0], $bucket);
			if (!isset($buckets[$key])) {
				$buckets[$key] = ['sum' => 0.0, 'n' => 0, 'tsum' => 0];
			}
			$buckets[$key]['sum'] += $p[1];
			$buckets[$key]['tsum'] += $p[0];
			$buckets[$key]['n']++;
		}
		ksort($buckets);
		$out = [];
		foreach ($buckets as $b) {
			$out[] = [(int) round($b['tsum'] / $b['n']), $b['sum'] / $b['n']];
		}
		return $out;
	}

	private static function parseRelative(string $s, int $fallback, bool $is_start): int {
		$s = trim($s);
		if ($s === '') {
			return $fallback;
		}
		$parser = new CRelativeTimeParser();
		if ($parser->parse($s) == CParser::PARSE_SUCCESS) {
			try {
				return $parser->getDateTime($is_start)->getTimestamp();
			}
			catch (\Throwable $e) {
				// fall through
			}
		}
		$ts = strtotime($s);
		return $ts !== false ? $ts : $fallback;
	}
}
