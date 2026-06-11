<?php declare(strict_types = 0);

namespace Modules\PassageChart\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;
use CParser;
use CRelativeTimeParser;

class WidgetView extends CControllerDashboardWidgetView {

	private const PALETTE = [
		'#1F77B4', '#FF7F0E', '#2CA02C', '#D62728', '#9467BD',
		'#8C564B', '#E377C2', '#7F7F7F', '#BCBD22', '#17BECF',
		'#393B79', '#637939', '#8C6D31', '#843C39', '#7B4173'
	];

	// retencao de history do item contador; alem disso so trends (horarios)
	private const HISTORY_DAYS = 7;

	protected function doAction(): void {
		$name = $this->getInput('name', $this->widget->getName());

		// Hosts escolhidos no widget tem prioridade; sem selecao, cai para o host do dashboard.
		$hostids = $this->fields_values['hostids'] ?? [];
		if (!$hostids) {
			$override_hostid = $this->fields_values['override_hostid'] ?? [];
			$hostids = is_array($override_hostid) ? $override_hostid : [$override_hostid];
			$hostids = array_filter($hostids);
		}

		$item_key = trim((string) ($this->fields_values['item_key'] ?? 'pumatronix.detection.count'));

		$now = time();
		$time_from = self::parseRelative((string) ($this->fields_values['time_from'] ?? 'now-24h'), $now - 86400, true);
		$time_to = self::parseRelative((string) ($this->fields_values['time_to'] ?? 'now'), $now, false);
		if ($time_to <= $time_from) {
			$time_to = $time_from + 60;
		}
		$span = $time_to - $time_from;

		$interval = (int) ($this->fields_values['interval'] ?? 0);
		if ($interval === 0) {
			$interval = $span <= 43200 ? 900 : ($span <= 3 * 86400 ? 3600 : 86400);
		}
		// Sub-hora exige history bruto: fora da retencao (ou janela longa demais) cai para 1h.
		if ($interval < 3600 && ($span > 3 * 86400 || $time_from < $now - self::HISTORY_DAYS * 86400)) {
			$interval = 3600;
		}

		$grouping = (int) ($this->fields_values['grouping'] ?? 0);
		$show_values = (int) ($this->fields_values['show_values'] ?? 1) === 1;

		$error = null;
		$series = [];
		$buckets = [];

		if (!$hostids) {
			$error = _('Select hosts in the widget or a host in the dashboard.');
		}
		else {
			$hosts = API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => $hostids,
				'filter' => ['status' => HOST_STATUS_MONITORED],
				'preservekeys' => true
			]);

			$items = $hosts
				? API::Item()->get([
					'output' => ['itemid', 'hostid', 'value_type'],
					'hostids' => array_keys($hosts),
					'filter' => ['key_' => $item_key, 'status' => ITEM_STATUS_ACTIVE],
					'webitems' => true
				])
				: [];

			$by_value_type = [];
			$host_by_item = [];
			foreach ($items as $it) {
				$vt = (int) $it['value_type'];
				if (!in_array($vt, [0, 3], true)) {
					continue; // numeric only
				}
				$by_value_type[$vt][] = $it['itemid'];
				$host_by_item[$it['itemid']] = $it['hostid'];
			}

			if (!$host_by_item) {
				$error = _('No matching numeric item found on selected hosts.');
			}
			else {
				// Grade de buckets alinhada a meia-noite local (tz do usuario, setada pelo Zabbix).
				$base = strtotime(date('Y-m-d 00:00:00', $time_from));
				$first = intdiv($time_from - $base, $interval);
				$last = intdiv($time_to - 1 - $base, $interval);
				for ($k = $first; $k <= $last; $k++) {
					$buckets[] = $base + $k * $interval;
				}
				$nbuckets = count($buckets);

				$use_history = $time_from >= $now - self::HISTORY_DAYS * 86400
					&& ($interval < 3600 || $span <= 2 * 86400);

				$sum_by_host = []; // hostid => [bucket idx => sum]

				foreach ($by_value_type as $vt => $ids) {
					if ($use_history) {
						$rows = API::History()->get([
							'output' => ['itemid', 'clock', 'value'],
							'history' => $vt,
							'itemids' => $ids,
							'time_from' => $time_from,
							'time_till' => $time_to
						]) ?: [];
						foreach ($rows as $r) {
							$idx = intdiv((int) $r['clock'] - $base, $interval) - $first;
							if ($idx < 0 || $idx >= $nbuckets) {
								continue;
							}
							$hid = $host_by_item[$r['itemid']];
							$sum_by_host[$hid][$idx] = ($sum_by_host[$hid][$idx] ?? 0.0) + (float) $r['value'];
						}
					}
					else {
						// soma exata a partir de trends: num * value_avg
						$rows = API::Trend()->get([
							'output' => ['itemid', 'clock', 'num', 'value_avg'],
							'itemids' => $ids,
							'time_from' => $time_from,
							'time_till' => $time_to
						]) ?: [];
						foreach ($rows as $r) {
							$idx = intdiv((int) $r['clock'] - $base, $interval) - $first;
							if ($idx < 0 || $idx >= $nbuckets) {
								continue;
							}
							$hid = $host_by_item[$r['itemid']];
							$sum_by_host[$hid][$idx] = ($sum_by_host[$hid][$idx] ?? 0.0)
								+ (float) $r['num'] * (float) $r['value_avg'];
						}
					}
				}

				if ($grouping === 1) {
					// uma serie por host, ordenada por total desc (empilhado no JS)
					$per_host = [];
					foreach ($sum_by_host as $hid => $vals) {
						$per_host[] = [
							'name' => $hosts[$hid]['name'] ?? (string) $hid,
							'vals' => $vals,
							'total' => array_sum($vals)
						];
					}
					usort($per_host, static fn($a, $b) => $b['total'] <=> $a['total']);
					$i = 0;
					foreach ($per_host as $ph) {
						$series[] = [
							'name' => $ph['name'],
							'color' => self::PALETTE[$i % count(self::PALETTE)],
							'total' => (int) round($ph['total']),
							'values' => self::fillValues($ph['vals'], $nbuckets)
						];
						$i++;
					}
				}
				else {
					$totals = [];
					foreach ($sum_by_host as $vals) {
						foreach ($vals as $idx => $v) {
							$totals[$idx] = ($totals[$idx] ?? 0.0) + $v;
						}
					}
					$series[] = [
						'name' => _('Total'),
						'color' => self::PALETTE[0],
						'total' => (int) round(array_sum($totals)),
						'values' => self::fillValues($totals, $nbuckets)
					];
				}

				if (!$series || array_sum(array_column($series, 'total')) == 0) {
					$error = _('No data in selected period.');
				}
			}
		}

		$this->setResponse(new CControllerResponseData([
			'name' => $name,
			'chart' => [
				'series' => $series,
				'buckets' => $buckets,
				'interval' => $interval,
				'time_from' => $time_from,
				'time_to' => $time_to,
				'stacked' => $grouping === 1,
				'show_values' => $show_values,
				'error' => $error
			],
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	private static function fillValues(array $vals, int $n): array {
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$out[] = (int) round($vals[$i] ?? 0);
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
