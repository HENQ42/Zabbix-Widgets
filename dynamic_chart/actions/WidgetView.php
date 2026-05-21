<?php declare(strict_types = 0);

namespace Modules\DynamicChart\Actions;

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

	protected function doAction(): void {
		$name = $this->getInput('name', $this->widget->getName());

		$override_hostid = $this->fields_values['override_hostid'] ?? [];
		if ($override_hostid) {
			$hostids = is_array($override_hostid) ? $override_hostid : [$override_hostid];
		}
		else {
			$hostids = $this->fields_values['hostids'] ?? [];
		}
		$picked_itemids = (array) ($this->fields_values['itemid'] ?? []);
		$item_key = '';
		if ($picked_itemids) {
			$picked = API::Item()->get([
				'output' => ['key_'],
				'itemids' => $picked_itemids,
				'webitems' => true
			]);
			if ($picked) {
				$item_key = $picked[0]['key_'];
			}
		}
		$now = time();
		$time_from = self::parseRelative((string) ($this->fields_values['time_from'] ?? 'now-1d'), $now - 86400, true);
		$time_to = self::parseRelative((string) ($this->fields_values['time_to'] ?? 'now'), $now, false);
		if ($time_to <= $time_from) {
			$time_to = $time_from + 60;
		}

		$line_thickness = max(0, min(10, (int) ($this->fields_values['line_thickness'] ?? 3)));
		$fill_intensity = max(0, min(10, (int) ($this->fields_values['fill_intensity'] ?? 2)));

		$business_enabled = (int) ($this->fields_values['business_enabled'] ?? 0) === 1;
		$business_start = self::parseHHMM((string) ($this->fields_values['business_start'] ?? '08:00'));
		$business_end = self::parseHHMM((string) ($this->fields_values['business_end'] ?? '18:00'));
		$business_days = array_map('intval', (array) ($this->fields_values['business_days'] ?? [1, 2, 3, 4, 5]));

		$bottom_count = max(0, min(10, (int) ($this->fields_values['bottom_count'] ?? 0)));

		$series = [];
		$y_min = null;
		$y_max = null;
		$error = null;
		$item_units = '';
		$all_integer = false;

		if (!$hostids || $item_key === '') {
			$error = _('Select at least one host and one item key.');
		}
		else {
			$hosts = API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => $hostids,
				'filter' => ['status' => HOST_STATUS_MONITORED],
				'preservekeys' => true
			]);

			$active_hostids = array_keys($hosts);

			$items = $active_hostids
				? API::Item()->get([
					'output' => ['itemid', 'hostid', 'name', 'value_type', 'units'],
					'hostids' => $active_hostids,
					'filter' => ['key_' => $item_key, 'status' => ITEM_STATUS_ACTIVE],
					'webitems' => true
				])
				: [];

			if (!$items) {
				$error = _('No matching item found on selected hosts.');
			}
			else {
				$by_value_type = [];
				$item_by_id = [];
				$all_integer = true;
				$has_any = false;
				foreach ($items as $it) {
					$vt = (int) $it['value_type'];
					if (!in_array($vt, [0, 3], true)) {
						continue; // numeric only
					}
					$by_value_type[$vt][] = $it['itemid'];
					$item_by_id[$it['itemid']] = $it;
					if ($item_units === '' && !empty($it['units'])) {
						$item_units = $it['units'];
					}
					if ($vt !== 3) {
						$all_integer = false;
					}
					$has_any = true;
				}
				if (!$has_any) {
					$all_integer = false;
				}

				$use_trends = ($time_to - $time_from) > 86400;
				$points_by_item = [];

				foreach ($by_value_type as $vt => $ids) {
					if ($use_trends) {
						$rows = API::Trend()->get([
							'output' => ['itemid', 'clock', 'value_avg'],
							'itemids' => $ids,
							'time_from' => $time_from,
							'time_till' => $time_to
						]) ?: [];
						foreach ($rows as $r) {
							$points_by_item[$r['itemid']][] = [(int) $r['clock'], (float) $r['value_avg']];
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
							$points_by_item[$r['itemid']][] = [(int) $r['clock'], (float) $r['value']];
						}
					}
				}

				// Build a series per host (aggregating items if a host had multiple matches by key).
				$series_by_host = [];
				foreach ($item_by_id as $itemid => $it) {
					$hostid = $it['hostid'];
					$pts = $points_by_item[$itemid] ?? [];
					if (!$pts) {
						continue;
					}
					if (!isset($series_by_host[$hostid])) {
						$series_by_host[$hostid] = [
							'host_name' => $hosts[$hostid]['name'] ?? $hostid,
							'units' => $it['units'] ?? '',
							'points' => []
						];
					}
					foreach ($pts as $p) {
						$series_by_host[$hostid]['points'][] = $p;
					}
				}

				foreach ($series_by_host as &$s) {
					usort($s['points'], static fn($a, $b) => $a[0] <=> $b[0]);
					$sum = 0.0;
					$n = 0;
					foreach ($s['points'] as $p) {
						$sum += $p[1];
						$n++;
						if ($y_min === null || $p[1] < $y_min) $y_min = $p[1];
						if ($y_max === null || $p[1] > $y_max) $y_max = $p[1];
					}
					$s['avg'] = $n > 0 ? $sum / $n : 0.0;
				}
				unset($s);

				// Always: top-avg host + N lowest-avg hosts (N = bottom_count).
				if ($series_by_host) {
					uasort($series_by_host, static fn($a, $b) => $b['avg'] <=> $a['avg']);
					$keys = array_keys($series_by_host);
					$top_key = $keys[0];

					$picked = [$top_key => $series_by_host[$top_key] + ['role' => 'top']];

					if ($bottom_count > 0 && count($keys) > 1) {
						$bottoms = array_slice($keys, -$bottom_count, $bottom_count);
						$bottoms = array_reverse($bottoms); // lowest first
						$bottoms = array_diff($bottoms, [$top_key]);
						foreach ($bottoms as $bk) {
							$picked[$bk] = $series_by_host[$bk] + ['role' => 'bottom'];
						}
					}

					$series_by_host = $picked;
				}

				$show_role = $bottom_count > 0;
				$i = 0;
				foreach ($series_by_host as $s) {
					$suffix = $show_role
						? ($s['role'] === 'top' ? ' (top avg)' : ' (bottom avg)')
						: '';
					$series[] = [
						'name' => $s['host_name'].$suffix,
						'color' => self::PALETTE[$i % count(self::PALETTE)],
						'units' => $s['units'],
						'avg' => round($s['avg'], 4),
						'points' => $s['points']
					];
					$i++;
				}

				if (!$series) {
					$error = _('No history data in selected period.');
				}
			}
		}

		$non_business = [];
		if ($business_enabled && $business_start !== null && $business_end !== null) {
			$non_business = self::computeNonBusiness(
				$time_from, $time_to, $business_start, $business_end, $business_days
			);
		}

		$this->setResponse(new CControllerResponseData([
			'name' => $name,
			'chart' => [
				'series' => $series,
				'time_from' => $time_from,
				'time_to' => $time_to,
				'y_min' => $y_min,
				'y_max' => $y_max,
				'units' => $item_units,
				'is_integer' => $all_integer,
				'line_thickness' => $line_thickness,
				'fill_intensity' => $fill_intensity,
				'non_business' => $non_business,
				'error' => $error
			],
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	private static function parseRelative(string $s, int $fallback, bool $is_start): int {
		$s = trim($s);
		if ($s === '') return $fallback;
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

	private static function parseHHMM(string $s): ?int {
		if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($s), $m)) {
			$h = (int) $m[1];
			$mi = (int) $m[2];
			if ($h >= 0 && $h <= 24 && $mi >= 0 && $mi < 60) {
				return $h * 60 + $mi;
			}
		}
		return null;
	}

	private static function computeNonBusiness(int $tf, int $tt, int $start_min, int $end_min, array $days): array {
		$out = [];
		$day = strtotime(date('Y-m-d 00:00:00', $tf));
		while ($day < $tt) {
			$day_end = $day + 86400;
			$wday = (int) date('N', $day);

			if (!in_array($wday, $days, true)) {
				$s = max($day, $tf);
				$e = min($day_end, $tt);
				if ($e > $s) {
					$out[] = [$s, $e];
				}
			}
			else {
				$biz_start = $day + $start_min * 60;
				$biz_end = $day + $end_min * 60;

				$s = max($day, $tf);
				$e = min($biz_start, $tt);
				if ($e > $s) $out[] = [$s, $e];

				$s = max($biz_end, $tf);
				$e = min($day_end, $tt);
				if ($e > $s) $out[] = [$s, $e];
			}
			$day = $day_end;
		}
		return $out;
	}
}
