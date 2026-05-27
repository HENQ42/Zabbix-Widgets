<?php declare(strict_types = 0);

namespace Modules\HostGroupGrid\Actions;

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	Modules\HostGroupGrid\Includes\CWidgetFieldItemRows;

class WidgetView extends CControllerDashboardWidgetView {

	private const SITE_REGEX = '/^SEFAZ_AL_(?P<site>\d{2})(?:_(?P<tipo>[A-Z0-9]+)(?:_(?P<subtipo>[A-Z0-9]+)_(?P<valor>[A-Z0-9-]+))?)?$/';

	protected function doAction(): void {
		$name = $this->getInput('name', $this->widget->getDefaultName());

		$switch_groupids = $this->fields_values['switch_groupids'] ?? [];
		$camera_groupids = $this->fields_values['camera_groupids'] ?? [];
		$switch_online_itemid = $this->fields_values['switch_online_itemid'] ?? [];
		$camera_online_itemid = $this->fields_values['camera_online_itemid'] ?? [];
		$columns = (int) ($this->fields_values['columns'] ?? 3);

		// Resolve item key from the picked itemid (same key is then matched on every host of the group).
		$switch_online_key = $this->resolveItemKey($switch_online_itemid);
		$camera_online_key = $this->resolveItemKey($camera_online_itemid);

		if ($columns < 1) {
			$columns = 1;
		}

		$color_stable = (string) ($this->fields_values['color_stable'] ?? '');
		$color_critical = (string) ($this->fields_values['color_critical'] ?? '');
		$color_warning = (string) ($this->fields_values['color_warning'] ?? '');
		if ($color_stable === '') { $color_stable = '16A34A'; }
		if ($color_critical === '') { $color_critical = 'DC2626'; }
		if ($color_warning === '') { $color_warning = 'D97706'; }

		$empty_response = [
			'name' => $name,
			'sites' => [],
			'columns' => $columns,
			'color_stable' => $color_stable,
			'color_critical' => $color_critical,
			'color_warning' => $color_warning,
			'user' => ['debug_mode' => $this->getDebugMode()]
		];

		if (!$switch_groupids || !$camera_groupids) {
			$this->setResponse(new CControllerResponseData($empty_response));
			return;
		}

		// Fetch only enabled (monitored) hosts — disabled ones are ignored entirely.
		$switch_hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name'],
			'groupids' => $switch_groupids,
			'monitored_hosts' => true,
			'preservekeys' => true
		]);

		$camera_hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name'],
			'groupids' => $camera_groupids,
			'monitored_hosts' => true,
			'preservekeys' => true
		]);

		if (!$switch_hosts && !$camera_hosts) {
			$this->setResponse(new CControllerResponseData($empty_response));
			return;
		}

		// Group hosts per site number using the canonical nomenclature regex.
		$sites = []; // site_id => ['switches' => [hostid => host], 'cameras' => [...]]

		foreach ($switch_hosts as $hostid => $host) {
			$site = $this->extractSite($host['host']);
			if ($site === null) {
				continue;
			}
			$sites[$site]['switches'][(string) $hostid] = $host;
		}

		foreach ($camera_hosts as $hostid => $host) {
			$site = $this->extractSite($host['host']);
			if ($site === null) {
				continue;
			}
			$sites[$site]['cameras'][(string) $hostid] = $host;
		}

		if (!$sites) {
			$this->setResponse(new CControllerResponseData($empty_response));
			return;
		}

		// Apply user timezone for hour-bucket alignment.
		$user_tz = \CWebUser::$data['timezone'] ?? '';
		if ($user_tz === '' || $user_tz === 'default' || $user_tz === 'system') {
			$user_tz = \CSettingsHelper::get(\CSettingsHelper::DEFAULT_TIMEZONE);
		}
		if ($user_tz === '' || $user_tz === 'system') {
			$user_tz = @date_default_timezone_get();
		}
		if ($user_tz !== '' && $user_tz !== 'system') {
			@date_default_timezone_set($user_tz);
		}

		// 24 hour buckets of today (00:00..23:00 in user tz).
		$now = time();
		$today_local = new \DateTime('@'.$now);
		$today_local->setTimezone(new \DateTimeZone(date_default_timezone_get()));
		$today_local->setTime(0, 0, 0);
		$window_start = $today_local->getTimestamp();
		$current_hour_bucket = (int) floor(($now - $window_start) / 3600);

		// Resolve "online" status per host via the configured item keys (lastvalue == "1" => online).
		$switch_online_hostids = $this->fetchOnlineHostids($switch_hosts, $switch_online_key);
		$camera_online_hostids = $this->fetchOnlineHostids($camera_hosts, $camera_online_key);

		// Collect every relevant hostid for a single bulk events query.
		$all_hostids = [];
		foreach ($sites as $site_id => $info) {
			foreach (($info['switches'] ?? []) as $hid => $_) {
				$all_hostids[$hid] = true;
			}
			foreach (($info['cameras'] ?? []) as $hid => $_) {
				$all_hostids[$hid] = true;
			}
		}
		$all_hostids = array_keys($all_hostids);

		// Today's problem events (resolved or active).
		$events = API::Event()->get([
			'output' => ['eventid', 'objectid', 'clock', 'severity', 'name', 'r_eventid'],
			'selectHosts' => ['hostid', 'name'],
			'source' => 0,
			'object' => 0,
			'value' => 1,
			'hostids' => $all_hostids,
			'time_from' => $window_start,
			'sortfield' => ['clock'],
			'sortorder' => 'ASC'
		]);

		$recovery_ids = [];
		foreach ($events as $e) {
			if (!empty($e['r_eventid']) && $e['r_eventid'] !== '0') {
				$recovery_ids[] = $e['r_eventid'];
			}
		}
		$recovery_ids = array_values(array_unique($recovery_ids));

		$recovery_clocks = [];
		if ($recovery_ids) {
			$recoveries = API::Event()->get([
				'output' => ['eventid', 'clock'],
				'eventids' => $recovery_ids,
				'preservekeys' => true
			]);
			foreach ($recoveries as $eid => $rv) {
				$recovery_clocks[(string) $eid] = (int) $rv['clock'];
			}
		}

		// Per-hostid: which hosts currently have an unresolved problem (used for the "ativo" badge).
		$hosts_with_active_problem = [];
		// Per-hostid: max severity of currently-active (unresolved) problems.
		$host_active_max_sev = [];
		// hostid => bucket => max severity (for timeline aggregation per host first, then merged per site).
		$host_timeline_sev = [];
		$host_timeline_problems = [];

		foreach ($events as $e) {
			$start = (int) $e['clock'];
			$r_eventid = (string) ($e['r_eventid'] ?? '0');
			$r_clock = ($r_eventid !== '' && $r_eventid !== '0')
				? ($recovery_clocks[$r_eventid] ?? null)
				: null;

			$end = $r_clock ?? $now;

			if ($end < $window_start) {
				continue;
			}

			$start_bucket = (int) floor(($start - $window_start) / 3600);
			$end_bucket = (int) floor(($end - $window_start) / 3600);

			if ($start_bucket < 0) {
				$start_bucket = 0;
			}
			if ($end_bucket > 23) {
				$end_bucket = 23;
			}
			if ($end_bucket < $start_bucket) {
				continue;
			}

			$sev = (int) $e['severity'];
			$hids = array_column($e['hosts'] ?? [], 'hostid');
			$host_names = array_column($e['hosts'] ?? [], 'name', 'hostid');

			$is_active = ($r_clock === null);

			foreach ($hids as $hid) {
				$hid = (string) $hid;

				if ($is_active) {
					$hosts_with_active_problem[$hid] = true;
					$cur_sev = $host_active_max_sev[$hid] ?? -1;
					if ($sev > $cur_sev) {
						$host_active_max_sev[$hid] = $sev;
					}
				}

				$problem_data = [
					'eventid' => (string) $e['eventid'],
					'name' => (string) $e['name'],
					'severity' => $sev,
					'clock' => $start,
					'r_clock' => $r_clock,
					'host' => $host_names[$hid] ?? ''
				];

				for ($b = $start_bucket; $b <= $end_bucket; $b++) {
					$cur = $host_timeline_sev[$hid][$b] ?? -1;
					if ($sev > $cur) {
						$host_timeline_sev[$hid][$b] = $sev;
					}
					$host_timeline_problems[$hid][$b][] = $problem_data;
				}
			}
		}

		// Resolve drill-down item rows (one set, applied per host by key_ matching) — same model as Host Item Grid.
		$item_rows = $this->fields_values['items'] ?? [];

		$src_items = [];
		if ($item_rows) {
			$config_itemids = [];
			foreach ($item_rows as $row) {
				if (!empty($row['itemid'])) {
					$config_itemids[] = $row['itemid'];
				}
			}
			$config_itemids = array_values(array_unique($config_itemids));

			if ($config_itemids) {
				$src_items = API::Item()->get([
					'output' => ['itemid', 'key_', 'name'],
					'itemids' => $config_itemids,
					'webitems' => true,
					'preservekeys' => true
				]);
			}
		}

		// Split keys by target so a switch-row's key doesn't accidentally match an item on a camera host (and vice-versa).
		$switch_hostids_all = array_keys($switch_hosts);
		$camera_hostids_all = array_keys($camera_hosts);

		$switch_keys = [];
		$camera_keys = [];
		foreach ($item_rows as $row) {
			$src_item = $src_items[$row['itemid'] ?? 0] ?? null;
			if ($src_item === null) {
				continue;
			}
			$row_target = (string) ($row['target'] ?? CWidgetFieldItemRows::TARGET_SWITCH);
			if ($row_target === CWidgetFieldItemRows::TARGET_CAMERA) {
				$camera_keys[$src_item['key_']] = true;
			}
			else {
				$switch_keys[$src_item['key_']] = true;
			}
		}

		$items_by_host_key = [];
		if ($switch_keys && $switch_hostids_all) {
			$fetched = API::Item()->get([
				'output' => ['itemid', 'hostid', 'key_', 'lastvalue', 'value_type', 'name_resolved'],
				'hostids' => $switch_hostids_all,
				'filter' => ['key_' => array_keys($switch_keys)],
				'webitems' => true
			]);
			foreach ($fetched as $item) {
				$items_by_host_key[$item['hostid']][$item['key_']] = $item;
			}
		}
		if ($camera_keys && $camera_hostids_all) {
			$fetched = API::Item()->get([
				'output' => ['itemid', 'hostid', 'key_', 'lastvalue', 'value_type', 'name_resolved'],
				'hostids' => $camera_hostids_all,
				'filter' => ['key_' => array_keys($camera_keys)],
				'webitems' => true
			]);
			foreach ($fetched as $item) {
				$items_by_host_key[$item['hostid']][$item['key_']] = $item;
			}
		}

		// Build output per site.
		$output_sites = [];

		// Also need: switch host that represents the site (for the title link).
		// Preference: exact "SEFAZ_AL_NN", fallback "SEFAZ_AL_NN_MIKROTIK", else first switch.
		foreach ($sites as $site_id => $info) {
			$switches = $info['switches'] ?? [];
			$cameras = $info['cameras'] ?? [];

			$switch_count = count($switches);
			$camera_count = count($cameras);

			// "Ativo" = host cujo item de online configurado retorna 1.
			// Quando a key não está configurada, todos os hosts habilitados contam como online.
			$switch_active = 0;
			foreach ($switches as $hid => $host) {
				if ($switch_online_key === '' || isset($switch_online_hostids[(string) $hid])) {
					$switch_active++;
				}
			}

			$camera_active = 0;
			foreach ($cameras as $hid => $host) {
				if ($camera_online_key === '' || isset($camera_online_hostids[(string) $hid])) {
					$camera_active++;
				}
			}

			// Aggregate timeline across all site hosts (max severity wins).
			$site_hostids = array_merge(array_keys($switches), array_keys($cameras));

			$timeline = [];
			for ($i = 0; $i < 24; $i++) {
				$hour_start = $window_start + $i * 3600;

				$max_sev = -1;
				$problems = [];

				foreach ($site_hostids as $hid) {
					$hid = (string) $hid;
					$sev = $host_timeline_sev[$hid][$i] ?? -1;
					if ($sev > $max_sev) {
						$max_sev = $sev;
					}
					if (!empty($host_timeline_problems[$hid][$i])) {
						foreach ($host_timeline_problems[$hid][$i] as $p) {
							$problems[] = $p;
						}
					}
				}

				if ($i > $current_hour_bucket) {
					$state = 'future';
				}
				elseif ($max_sev < 0) {
					$state = 'stable';
				}
				elseif ($max_sev >= 4) {
					$state = 'critical';
				}
				else {
					$state = 'warning';
				}

				$timeline[] = [
					'hour_label' => date('H:00', $hour_start),
					'state' => $state,
					'severity' => $max_sev,
					'problems' => $problems
				];
			}

			// Severity map (Zabbix): 0=Not classified, 1=Information, 2=Warning, 3=Average, 4=High, 5=Disaster.
			// High/Disaster -> critical; Warning/Average -> unstable; nada/Info/Not classified -> stable.
			$site_active_max_sev = -1;
			foreach ($site_hostids as $hid) {
				$s = $host_active_max_sev[(string) $hid] ?? -1;
				if ($s > $site_active_max_sev) {
					$site_active_max_sev = $s;
				}
			}

			if ($site_active_max_sev >= 4) {
				$state = 'critical';
				$state_rank = 2;
			}
			elseif ($site_active_max_sev >= 2) {
				$state = 'unstable';
				$state_rank = 1;
			}
			else {
				$state = 'stable';
				$state_rank = 0;
			}

			// Per-host detail (used by the drill-down screen).
			$hosts_detail = [];
			foreach (['switch' => $switches, 'camera' => $cameras] as $type => $hosts_of_type) {
				foreach ($hosts_of_type as $hid => $host_obj) {
					$hid_s = (string) $hid;

					if ($type === 'switch') {
						$is_online = ($switch_online_key === '' || isset($switch_online_hostids[$hid_s]));
					}
					else {
						$is_online = ($camera_online_key === '' || isset($camera_online_hostids[$hid_s]));
					}

					$h_timeline = [];
					for ($i = 0; $i < 24; $i++) {
						$hour_start = $window_start + $i * 3600;
						$h_sev = $host_timeline_sev[$hid_s][$i] ?? -1;
						$h_problems = $host_timeline_problems[$hid_s][$i] ?? [];

						if ($i > $current_hour_bucket) {
							$h_state = 'future';
						}
						elseif ($h_sev < 0) {
							$h_state = 'stable';
						}
						elseif ($h_sev >= 4) {
							$h_state = 'critical';
						}
						else {
							$h_state = 'warning';
						}

						$h_timeline[] = [
							'hour_label' => date('H:00', $hour_start),
							'state' => $h_state,
							'severity' => $h_sev,
							'problems' => $h_problems
						];
					}

					$h_active_sev = $host_active_max_sev[$hid_s] ?? -1;
					if ($h_active_sev >= 4) {
						$h_status = 'critical';
					}
					elseif ($h_active_sev >= 2) {
						$h_status = 'unstable';
					}
					else {
						$h_status = 'stable';
					}

					// Per-host item rows (drill-down) — same resolution pipeline as Host Item Grid.
					$host_rows = [];
					foreach ($item_rows as $row) {
						$row_target = (string) ($row['target'] ?? CWidgetFieldItemRows::TARGET_SWITCH);
						if ($row_target !== $type) {
							continue;
						}
						$src_item = $src_items[$row['itemid'] ?? 0] ?? null;
						if ($src_item === null) {
							continue;
						}
						$key = $src_item['key_'];
						$item = $items_by_host_key[$hid_s][$key] ?? null;
						if ($item === null) {
							continue;
						}

						$value = $item['lastvalue'] ?? '';
						$regex = $row['regex'] ?? '';
						if ($regex !== '') {
							if (@preg_match($regex, $value, $matches)) {
								$value = isset($matches[1]) ? $matches[1] : $matches[0];
							}
						}

						$color = $row['default_color'] ?? '';
						$row_state = (int) ($row['default_state'] ?? CWidgetFieldItemRows::STATE_STABLE);

						if (!empty($row['conditions'])) {
							foreach ($row['conditions'] as $cond) {
								if (self::matchCondition((string) $cond['value'], (string) $value)) {
									if (!empty($cond['color'])) {
										$color = $cond['color'];
									}
									if (isset($cond['display']) && $cond['display'] !== '') {
										$value = $cond['display'];
									}
									$row_state = (int) ($cond['state'] ?? CWidgetFieldItemRows::STATE_STABLE);
									break;
								}
							}
						}

						$label = $row['label'] ?? '';
						if ($label === '') {
							$label = $item['name_resolved'] ?? $src_item['name'] ?? '';
						}

						$host_rows[] = [
							'label' => $label,
							'value' => $value,
							'color' => $color,
							'bold' => (int) ($row['bold'] ?? 0) === 1,
							'state' => $row_state
						];
					}

					$hosts_detail[] = [
						'hostid' => $hid_s,
						'name' => (string) ($host_obj['name'] ?? $host_obj['host']),
						'host' => (string) $host_obj['host'],
						'type' => $type,
						'online' => $is_online,
						'state' => $h_status,
						'timeline' => $h_timeline,
						'rows' => $host_rows
					];
				}
			}

			// Order hosts: criticals first, then unstable, then stable; switches before cameras within same state; then natural name.
			usort($hosts_detail, static function ($a, $b) {
				$rank = ['critical' => 2, 'unstable' => 1, 'stable' => 0];
				$ra = $rank[$a['state']] ?? 0;
				$rb = $rank[$b['state']] ?? 0;
				if ($ra !== $rb) {
					return $rb <=> $ra;
				}
				if ($a['type'] !== $b['type']) {
					return $a['type'] === 'switch' ? -1 : 1;
				}
				return strnatcasecmp($a['name'], $b['name']);
			});

			$output_sites[] = [
				'site_id' => $site_id,
				'site_label' => 'SEFAZ_AL_'.$site_id,
				'switch_total' => $switch_count,
				'switch_active' => $switch_active,
				'camera_total' => $camera_count,
				'camera_active' => $camera_active,
				'state' => $state,
				'state_rank' => $state_rank,
				'timeline' => $timeline,
				'hosts' => $hosts_detail
			];
		}

		// Critical first, then unstable, then stable; ties broken by site id natural order.
		usort($output_sites, static function ($a, $b) {
			if ($a['state_rank'] !== $b['state_rank']) {
				return (int) $b['state_rank'] <=> (int) $a['state_rank'];
			}
			return strnatcasecmp($a['site_id'], $b['site_id']);
		});

		$this->setResponse(new CControllerResponseData([
			'name' => $name,
			'sites' => $output_sites,
			'columns' => $columns,
			'color_stable' => $color_stable,
			'color_critical' => $color_critical,
			'color_warning' => $color_warning,
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	private function resolveItemKey($itemid_value): string {
		$itemids = is_array($itemid_value) ? $itemid_value : ($itemid_value ? [$itemid_value] : []);
		if (!$itemids) {
			return '';
		}

		$items = API::Item()->get([
			'output' => ['key_'],
			'itemids' => $itemids,
			'webitems' => true
		]);

		return $items ? (string) $items[0]['key_'] : '';
	}

	private function extractSite(string $host_technical_name): ?string {
		if (preg_match(self::SITE_REGEX, $host_technical_name, $m)) {
			return $m['site'];
		}
		return null;
	}

	/**
	 * Returns [hostid => true] for hosts whose configured "online" item key has lastvalue == "1".
	 * Returns an empty array if no key is configured (caller treats empty key as "all online").
	 */
	/**
	 * Match a condition expression against an actual item value.
	 *
	 * Supported expression forms (operator must be the first non-space chars; spaces around the operand are tolerated):
	 *   "10"      → equality (string compare, same as legacy behavior)
	 *   "=10"     → equality (string compare)
	 *   ">10"     → actual > 10  (numeric; both sides must parse as numbers)
	 *   ">=10"    → actual >= 10 (numeric)
	 *   "<10"     → actual < 10  (numeric)
	 *   "<=10"    → actual <= 10 (numeric)
	 *
	 * For numeric operators (>, >=, <, <=), if either side is non-numeric the condition does not match
	 * (fail-closed — never accidentally matches strings).
	 */
	private static function matchCondition(string $expr, string $actual): bool {
		$expr = trim($expr);

		if ($expr === '') {
			return $actual === '';
		}

		$op = '=';
		$operand = $expr;

		// Order matters: two-char operators must be checked before single-char.
		if (strncmp($expr, '>=', 2) === 0) {
			$op = '>=';
			$operand = substr($expr, 2);
		}
		elseif (strncmp($expr, '<=', 2) === 0) {
			$op = '<=';
			$operand = substr($expr, 2);
		}
		elseif ($expr[0] === '>') {
			$op = '>';
			$operand = substr($expr, 1);
		}
		elseif ($expr[0] === '<') {
			$op = '<';
			$operand = substr($expr, 1);
		}
		elseif ($expr[0] === '=') {
			$op = '=';
			$operand = substr($expr, 1);
		}

		$operand = trim($operand);

		if ($op === '=') {
			// Legacy behavior preserved: plain string equality.
			return $operand === trim($actual);
		}

		// Numeric comparison — both must be numeric.
		if (!is_numeric($operand) || !is_numeric($actual)) {
			return false;
		}

		$a = (float) $actual;
		$b = (float) $operand;

		switch ($op) {
			case '>':  return $a >  $b;
			case '>=': return $a >= $b;
			case '<':  return $a <  $b;
			case '<=': return $a <= $b;
		}

		return false;
	}

	private function fetchOnlineHostids(array $hosts, string $key): array {
		if ($key === '' || !$hosts) {
			return [];
		}

		$items = API::Item()->get([
			'output' => ['hostid', 'lastvalue'],
			'hostids' => array_keys($hosts),
			'filter' => ['key_' => $key],
			'webitems' => true
		]);

		$online = [];
		foreach ($items as $item) {
			if ((string) ($item['lastvalue'] ?? '') === '1') {
				$online[(string) $item['hostid']] = true;
			}
		}

		return $online;
	}
}
