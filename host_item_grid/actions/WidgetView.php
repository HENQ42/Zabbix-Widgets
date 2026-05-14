<?php declare(strict_types = 0);

namespace Modules\HostItemGrid\Actions;

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	Modules\HostItemGrid\Includes\CWidgetFieldItemRows;

class WidgetView extends CControllerDashboardWidgetView {

	protected function doAction(): void {
		$name = $this->getInput('name', $this->widget->getDefaultName());

		$rows = $this->fields_values['items'] ?? [];
		$groupids = $this->fields_values['groupids'] ?? [];
		$direct_hostids = $this->fields_values['hostids'] ?? [];
		$columns = (int) ($this->fields_values['columns'] ?? 3);

		if ($columns < 1) {
			$columns = 1;
		}

		$color_stable = (string) ($this->fields_values['color_stable'] ?? '4CAF50');
		$color_critical = (string) ($this->fields_values['color_critical'] ?? 'E53935');
		$color_warning = (string) ($this->fields_values['color_warning'] ?? 'FFA726');

		if (!$rows || (!$groupids && !$direct_hostids)) {
			$this->setResponse(new CControllerResponseData([
				'name' => $name,
				'hosts' => [],
				'columns' => $columns,
				'color_stable' => $color_stable,
				'color_critical' => $color_critical,
				'color_warning' => $color_warning,
				'user' => ['debug_mode' => $this->getDebugMode()]
			]));

			return;
		}

		// Resolve item keys from the configured rows (itemid -> key_).
		$config_itemids = [];

		foreach ($rows as $row) {
			if (!empty($row['itemid'])) {
				$config_itemids[] = $row['itemid'];
			}
		}

		$config_itemids = array_values(array_unique($config_itemids));

		$src_items = API::Item()->get([
			'output' => ['itemid', 'key_', 'name'],
			'itemids' => $config_itemids,
			'webitems' => true,
			'preservekeys' => true
		]);

		// Collect host ids: direct hostids + hosts from groups.
		$hostids = [];

		foreach ($direct_hostids as $hid) {
			$hostids[(string) $hid] = true;
		}

		if ($groupids) {
			$group_hosts = API::Host()->get([
				'output' => ['hostid'],
				'groupids' => $groupids,
				'monitored_hosts' => true,
				'preservekeys' => true
			]);

			foreach ($group_hosts as $hid => $_) {
				$hostids[(string) $hid] = true;
			}
		}

		$hostids = array_keys($hostids);

		if (!$hostids) {
			$this->setResponse(new CControllerResponseData([
				'name' => $name,
				'hosts' => [],
				'columns' => $columns,
				'color_stable' => $color_stable,
				'color_critical' => $color_critical,
				'color_warning' => $color_warning,
				'user' => ['debug_mode' => $this->getDebugMode()]
			]));

			return;
		}

		// Apply user timezone so hour-bucket boundaries and labels match the user's clock.
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

		// Timeline: 24 buckets of 1h from 00:00 to 23:00 of today (in user tz).
		$now = time();
		$today_local = new \DateTime('@'.$now);
		$today_local->setTimezone(new \DateTimeZone(date_default_timezone_get()));
		$today_local->setTime(0, 0, 0);
		$window_start = $today_local->getTimestamp();
		$current_hour_bucket = (int) floor(($now - $window_start) / 3600);

		// Fetch ALL problem events that started today (resolved or not).
		$events = API::Event()->get([
			'output' => ['eventid', 'objectid', 'clock', 'severity', 'name', 'r_eventid'],
			'selectHosts' => ['hostid'],
			'source' => 0, // EVENT_SOURCE_TRIGGERS
			'object' => 0, // EVENT_OBJECT_TRIGGER
			'value' => 1,  // TRIGGER_VALUE_TRUE (problem)
			'hostids' => $hostids,
			'time_from' => $window_start,
			'sortfield' => ['clock'],
			'sortorder' => 'ASC'
		]);

		// Resolve recovery clocks for events that have been closed.
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

		// hostid -> bucket index (0..23) -> max severity seen
		// hostid -> bucket index (0..23) -> list of problems covering that bucket
		$timeline_sev = [];
		$timeline_problems = [];

		foreach ($events as $e) {
			$start = (int) $e['clock'];
			$r_eventid = (string) ($e['r_eventid'] ?? '0');
			$r_clock = ($r_eventid !== '' && $r_eventid !== '0')
				? ($recovery_clocks[$r_eventid] ?? null)
				: null;

			// End of problem window: resolution time, or "now" if still active.
			$end = $r_clock ?? $now;

			if ($end < $window_start) {
				continue;
			}

			$start_bucket = (int) floor(($start - $window_start) / 3600);
			$end_bucket = (int) floor(($end - $window_start) / 3600);

			// Clamp to today's 24-bucket window.
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

			$problem_data = [
				'eventid' => (string) $e['eventid'],
				'name' => (string) $e['name'],
				'severity' => $sev,
				'clock' => $start,
				'r_clock' => $r_clock // null = still active
			];

			foreach ($hids as $hid) {
				$hid = (string) $hid;

				for ($b = $start_bucket; $b <= $end_bucket; $b++) {
					$cur = $timeline_sev[$hid][$b] ?? -1;
					if ($sev > $cur) {
						$timeline_sev[$hid][$b] = $sev;
					}
					$timeline_problems[$hid][$b][] = $problem_data;
				}
			}
		}

		// Fetch host display info, ordered by name.
		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name'],
			'hostids' => $hostids,
			'monitored_hosts' => true,
			'preservekeys' => true
		]);

		uasort($hosts, static function ($a, $b) {
			return strnatcasecmp($a['name'], $b['name']);
		});

		// Fetch all items for these hosts that match any configured key.
		$keys = array_values(array_unique(array_column($src_items, 'key_')));

		$fetched_items = [];

		if ($keys) {
			$fetched_items = API::Item()->get([
				'output' => ['itemid', 'hostid', 'key_', 'lastvalue', 'value_type', 'name_resolved'],
				'hostids' => $hostids,
				'filter' => ['key_' => $keys],
				'webitems' => true
			]);
		}

		// Index by hostid -> key_ -> item.
		$items_by_host_key = [];

		foreach ($fetched_items as $item) {
			$items_by_host_key[$item['hostid']][$item['key_']] = $item;
		}

		$output_hosts = [];

		foreach ($hosts as $hostid => $host) {
			$host_rows = [];

			foreach ($rows as $row) {
				$src_item = $src_items[$row['itemid'] ?? 0] ?? null;

				if ($src_item === null) {
					continue;
				}

				$key = $src_item['key_'];
				$item = $items_by_host_key[$hostid][$key] ?? null;

				if ($item === null) {
					// Host doesn't have this key — hide row.
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
				$state = (int) ($row['default_state'] ?? CWidgetFieldItemRows::STATE_STABLE);

				if (!empty($row['conditions'])) {
					foreach ($row['conditions'] as $cond) {
						if ((string) $cond['value'] === (string) $value) {
							if (!empty($cond['color'])) {
								$color = $cond['color'];
							}
							if (isset($cond['display']) && $cond['display'] !== '') {
								$value = $cond['display'];
							}
							$state = (int) ($cond['state'] ?? CWidgetFieldItemRows::STATE_STABLE);
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
					'state' => $state
				];
			}

			if (!$host_rows) {
				continue;
			}

			$host_state = CWidgetFieldItemRows::STATE_STABLE;

			foreach ($host_rows as $hr) {
				if ((int) $hr['state'] === CWidgetFieldItemRows::STATE_CRITICAL) {
					$host_state = CWidgetFieldItemRows::STATE_CRITICAL;
					break;
				}
			}

			// Build 24-cell timeline (00:00..23:00 of today) for this host.
			$timeline = [];
			for ($i = 0; $i < 24; $i++) {
				$hour_start = $window_start + $i * 3600;
				$sev = $timeline_sev[(string) $hostid][$i] ?? -1;
				$problems = $timeline_problems[(string) $hostid][$i] ?? [];

				if ($i > $current_hour_bucket) {
					$state = 'future';
				}
				elseif ($sev < 0) {
					$state = 'stable';
				}
				elseif ($sev >= 4) {
					$state = 'critical';
				}
				else {
					$state = 'warning';
				}

				$timeline[] = [
					'hour_label' => date('H:00', $hour_start),
					'state' => $state,
					'severity' => $sev,
					'problems' => $problems
				];
			}

			$output_hosts[] = [
				'name' => $host['name'],
				'rows' => $host_rows,
				'state' => $host_state,
				'timeline' => $timeline
			];
		}

		// Critical hosts first, then stable; preserve name order within each group.
		usort($output_hosts, static function ($a, $b) {
			if ($a['state'] !== $b['state']) {
				return (int) $b['state'] <=> (int) $a['state'];
			}

			return strnatcasecmp($a['name'], $b['name']);
		});

		$this->setResponse(new CControllerResponseData([
			'name' => $name,
			'hosts' => $output_hosts,
			'columns' => $columns,
			'color_stable' => $color_stable,
			'color_critical' => $color_critical,
			'color_warning' => $color_warning,
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}
}
