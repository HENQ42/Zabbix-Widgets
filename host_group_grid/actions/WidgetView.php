<?php declare(strict_types = 0);

namespace Modules\HostGroupGrid\Actions;

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	Modules\HostGroupGrid\Includes\CWidgetFieldItemRows;

class WidgetView extends CControllerDashboardWidgetView {

	// Captures the host-name prefix (everything before the site number) and the 2-digit site number.
	// Non-greedy prefix grabs the FIRST 2-digit block after an underscore, so
	// `SEFAZ_AL_03_MIKROTIK` -> prefix=`SEFAZ_AL`, site=`03`. The site key returned to callers is
	// the composite `prefix_site` (e.g. `SEFAZ_AL_03`), so multiple prefixes can coexist without
	// colliding on the numeric portion alone.
	private const SITE_REGEX = '/^(?P<prefix>.+?)_(?P<site>\d{2})(?:_.*)?$/';

	// Captures the TIPO token of the nomenclature (the field right after the 2-digit site number), e.g.
	// `SEFAZ_AL_03_PTX_SENTIDO_NORTE-SUL` -> tipo=`PTX`. Hosts without a type (e.g. `SEFAZ_AL_03`) yield null.
	private const TYPE_REGEX = '/^.+?_\d{2}_(?P<tipo>[A-Z0-9]+)/i';

	protected function doAction(): void {
		$name = $this->getInput('name', $this->widget->getDefaultName());

		$switch_groupids = $this->fields_values['switch_groupids'] ?? [];
		$camera_groupids = $this->fields_values['camera_groupids'] ?? [];
		$switch_online_itemid = $this->fields_values['switch_online_itemid'] ?? [];
		$columns = (int) ($this->fields_values['columns'] ?? 3);

		// Optional host-tag name carrying the "site type" label. Lives only on the Edge Router host.
		// Empty => feature disabled (no type badge rendered).
		$site_type_tag = trim((string) ($this->fields_values['site_type_tag'] ?? ''));

		// Resolve item key from the picked itemid (same key is then matched on every host of the group).
		$switch_online_key = $this->resolveItemKey($switch_online_itemid);

		// Camera "online" is resolved per camera TYPE: each configured row pairs an item key with a TIPO
		// (PTX, PTZ, ...). The legacy single field becomes the untyped fallback row.
		$camera_online_rows = $this->resolveCameraOnlineRows();

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
		// Edge Routers carry the optional "site type" tag, so pull tags only when the feature is enabled.
		$switch_hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name'],
			'selectTags' => ($site_type_tag !== '') ? ['tag', 'value'] : [],
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
		$camera_online_map = $this->computeCameraOnline($camera_hosts, $camera_online_rows);

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

		// Recovery (OK) events that fired today. These catch the gap left by the two queries that
		// follow: a problem that STARTED before 00:00 today (so it is absent from the today-windowed
		// events query above) but was RESOLVED at some point today (so it is no longer in the active
		// Problem.get set below). Without this it would leave today's cells green up to the moment of
		// resolution. The Event API has no reverse recovery->problem link and its `filter` does not
		// accept r_eventid, so we correlate via objectid (the triggerid, shared by a problem and its
		// recovery) and then keep only the problems whose r_eventid is in today's recovery set.
		$todays_recoveries = API::Event()->get([
			'output' => ['eventid', 'clock', 'objectid'],
			'source' => 0,
			'object' => 0,
			'value' => 0,
			'hostids' => $all_hostids,
			'time_from' => $window_start,
			'preservekeys' => true
		]);

		$old_resolved = [];
		$recovery_eventids = array_keys($todays_recoveries);
		if ($recovery_eventids) {
			$recovery_objectids = array_values(array_unique(array_column($todays_recoveries, 'objectid')));

			$candidates = API::Event()->get([
				'output' => ['eventid', 'objectid', 'clock', 'severity', 'name', 'r_eventid'],
				'selectHosts' => ['hostid', 'name'],
				'source' => 0,
				'object' => 0,
				'value' => 1,
				'hostids' => $all_hostids,
				'objectids' => $recovery_objectids,
				// Started BEFORE today; problems that started today are already in $events above, so
				// this bound guarantees no overlap (no double painting).
				'time_till' => $window_start - 1,
				'sortfield' => ['clock'],
				'sortorder' => 'DESC'
			]);

			// Keep only problem events whose recovery is in today's recovery set (objectids may match
			// older, unrelated incidents on the same trigger). r_eventid carries the recovery linkage.
			$recovery_set = array_flip(array_map('strval', $recovery_eventids));
			$old_resolved = array_values(array_filter($candidates, static function ($e) use ($recovery_set) {
				return isset($recovery_set[(string) ($e['r_eventid'] ?? '0')]);
			}));
		}

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

		// Feed the old-but-resolved-today problems into the same machinery as the regular events. Their
		// recovery clocks come straight from the today's-recoveries query. The main loop below then paints
		// buckets from 00:00 (start_bucket clamps to 0) up to the recovery hour, and leaves them out of the
		// active-problem badge because their r_clock is non-null.
		foreach ($todays_recoveries as $eid => $rv) {
			$recovery_clocks[(string) $eid] = (int) $rv['clock'];
		}
		$events = array_merge($events, $old_resolved);

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

		// Event ids already represented by the today-windowed query above, so the active-problems
		// pass below does not double-count problems that started today.
		$seen_event_ids = array_flip(array_map('strval', array_column($events, 'eventid')));

		// The today-windowed events query above only returns problems that STARTED today. An incident
		// that began before 00:00 today and is still open won't be there, so without this pass it
		// would neither colour today's cells nor be listed in the drill-down. Query the CURRENT set
		// of unresolved problems directly and feed both the timeline and the "ativo" badge.
		if ($all_hostids) {
			// Problem.get does not support selectHosts; the host comes via objectid (the triggerid),
			// so we resolve triggerid -> hostids with a follow-up Trigger.get. monitored => true keeps
			// only enabled triggers on monitored hosts with enabled items, which drops problems left
			// orphaned in the problem table by a since-disabled trigger (they are not real anymore).
			$active_problems = API::Problem()->get([
				'output' => ['eventid', 'objectid', 'clock', 'severity', 'name'],
				'source' => 0,
				'object' => 0,
				'hostids' => $all_hostids
			]);

			if ($active_problems) {
				$problem_triggerids = array_values(array_unique(array_column($active_problems, 'objectid')));
				$trigger_hosts = API::Trigger()->get([
					'output' => ['triggerid'],
					'selectHosts' => ['hostid'],
					'triggerids' => $problem_triggerids,
					'monitored' => true,
					'preservekeys' => true
				]);

				foreach ($active_problems as $p) {
					$eid = (string) $p['eventid'];
					if (isset($seen_event_ids[$eid])) {
						continue; // already painted by the today-windowed events query
					}

					$trigger = $trigger_hosts[$p['objectid']] ?? null;
					if ($trigger === null) {
						continue; // trigger disabled, item disabled, or host not monitored
					}

					$sev = (int) $p['severity'];
					$start = (int) $p['clock'];

					// This problem started before today (else the events query caught it). Cover today
					// from 00:00 (bucket 0) up to the current hour.
					$end_bucket = $current_hour_bucket;
					if ($end_bucket < 0) {
						continue;
					}
					if ($end_bucket > 23) {
						$end_bucket = 23;
					}

					foreach (array_column($trigger['hosts'] ?? [], 'hostid') as $hid) {
						$hid = (string) $hid;

						$hosts_with_active_problem[$hid] = true;
						$cur_sev = $host_active_max_sev[$hid] ?? -1;
						if ($sev > $cur_sev) {
							$host_active_max_sev[$hid] = $sev;
						}

						$problem_data = [
							'eventid' => $eid,
							'name' => (string) $p['name'],
							'severity' => $sev,
							'clock' => $start,
							'r_clock' => null, // still active
							'host' => (string) ($camera_hosts[$hid]['name'] ?? $switch_hosts[$hid]['name'] ?? '')
						];

						for ($b = 0; $b <= $end_bucket; $b++) {
							$cur = $host_timeline_sev[$hid][$b] ?? -1;
							if ($sev > $cur) {
								$host_timeline_sev[$hid][$b] = $sev;
							}
							$host_timeline_problems[$hid][$b][] = $problem_data;
						}
					}
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
				if ($camera_online_map[(string) $hid] ?? false) {
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
						$is_online = $camera_online_map[$hid_s] ?? false;
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
						// Camera rows may be restricted to a specific TYPE (PTX, PTZ, ...). An empty type
						// applies to every camera (backward compatible).
						if ($type === 'camera') {
							$row_type = strtoupper(trim((string) ($row['type'] ?? '')));
							if ($row_type !== '') {
								$host_type = $this->extractType((string) $host_obj['host']);
								if ($host_type === null || $row_type !== $host_type) {
									continue;
								}
							}
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

						// Pre-resolve this row's own "critical" condition (first one flagged critical), so a
						// dependent row can later borrow its display text + colour when forced critical.
						$crit_override = null;
						foreach (($row['conditions'] ?? []) as $cond) {
							if ((int) ($cond['state'] ?? CWidgetFieldItemRows::STATE_STABLE) === CWidgetFieldItemRows::STATE_CRITICAL) {
								$crit_override = [
									'display' => (string) ($cond['display'] ?? ''),
									'color' => (string) ($cond['color'] ?? '')
								];
								break;
							}
						}

						$host_rows[] = [
							'label' => $label,
							'value' => $value,
							'color' => $color,
							'bold' => (int) ($row['bold'] ?? 0) === 1,
							'state' => $row_state,
							'dependent' => (int) ($row['dependent'] ?? 0) === 1,
							'_crit' => $crit_override
						];
					}

					// Dependency pass: a row flagged "dependent" carries a reading that is only meaningful
					// while the rest of the host is up (e.g. a streaming check that can't succeed once the
					// camera is unavailable). When EVERY non-dependent row of this host (already
					// type-filtered) is itself critical, the dependent reading is stale, so we surface it as
					// critical reusing the row's own predefined critical condition (its display text +
					// colour). Falls back to the widget's critical colour when no such condition exists.
					// Guarded by non_dependent_total > 0 so a host made only of dependent rows is never
					// forced critical on a vacuously-true "all critical" check.
					$non_dependent_total = 0;
					$non_dependent_critical = 0;
					$has_dependent = false;
					foreach ($host_rows as $r) {
						if (!empty($r['dependent'])) {
							$has_dependent = true;
							continue;
						}
						$non_dependent_total++;
						if ((int) $r['state'] === CWidgetFieldItemRows::STATE_CRITICAL) {
							$non_dependent_critical++;
						}
					}

					if ($has_dependent && $non_dependent_total > 0
							&& $non_dependent_critical === $non_dependent_total) {
						foreach ($host_rows as &$r) {
							if (empty($r['dependent']) || (int) $r['state'] === CWidgetFieldItemRows::STATE_CRITICAL) {
								continue;
							}
							$r['state'] = CWidgetFieldItemRows::STATE_CRITICAL;
							$override = $r['_crit'] ?? null;
							if ($override !== null && $override['display'] !== '') {
								$r['value'] = $override['display'];
							}
							$r['color'] = ($override !== null && $override['color'] !== '')
								? $override['color']
								: $color_critical;
						}
						unset($r);
					}

					// Drop the internal helper key before the row leaves the controller.
					foreach ($host_rows as &$r) {
						unset($r['_crit']);
					}
					unset($r);

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
				'site_label' => $site_id,
				'site_type' => $this->extractSiteType($switches, $site_type_tag),
				'switch_total' => $switch_count,
				'switch_active' => $switch_active,
				'camera_total' => $camera_count,
				'camera_active' => $camera_active,
				'types' => $this->buildTypeBadges(
					$switches, $cameras, $switch_online_key, $switch_online_hostids, $camera_online_map
				),
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
			return $m['prefix'].'_'.$m['site'];
		}
		return null;
	}

	/**
	 * Extract the canonical TIPO token (uppercased) from a host technical name, or null if the host
	 * carries no type (e.g. the short Mikrotik form `SEFAZ_AL_03`).
	 */
	private function extractType(string $host_technical_name): ?string {
		if (preg_match(self::TYPE_REGEX, $host_technical_name, $m)) {
			return strtoupper($m['tipo']);
		}
		return null;
	}

	/**
	 * Build the per-TIPO active/total list rendered as card badges.
	 *
	 * The TIPO comes purely from the canonical nomenclature (the token right after the site number).
	 * Edge Routers with no type token use the implicit MIKROTIK type (short form `SEFAZ_AL_NN`);
	 * cameras that don't parse fall back to OUTROS so misnamed hosts surface rather than vanish.
	 * Edge-Router-origin types are listed before camera-origin types; within each origin, natural
	 * name order. "Online" reuses the same resolution as the rest of the widget (switch_online_key
	 * for switches, the precomputed camera-online map for cameras).
	 *
	 * @return array<int, array{type: string, origin: string, active: int, total: int}>
	 */
	private function buildTypeBadges(array $switches, array $cameras, string $switch_online_key,
			array $switch_online_hostids, array $camera_online_map): array {
		$groups = ['switch' => [], 'camera' => []];

		foreach ($switches as $hid => $host) {
			$type = $this->extractType((string) $host['host']) ?? 'MIKROTIK';
			$online = ($switch_online_key === '' || isset($switch_online_hostids[(string) $hid]));
			if (!isset($groups['switch'][$type])) {
				$groups['switch'][$type] = ['active' => 0, 'total' => 0];
			}
			$groups['switch'][$type]['total']++;
			if ($online) {
				$groups['switch'][$type]['active']++;
			}
		}

		foreach ($cameras as $hid => $host) {
			$type = $this->extractType((string) $host['host']) ?? 'OUTROS';
			$online = $camera_online_map[(string) $hid] ?? false;
			if (!isset($groups['camera'][$type])) {
				$groups['camera'][$type] = ['active' => 0, 'total' => 0];
			}
			$groups['camera'][$type]['total']++;
			if ($online) {
				$groups['camera'][$type]['active']++;
			}
		}

		$badges = [];
		foreach (['switch', 'camera'] as $origin) {
			$types = $groups[$origin];
			uksort($types, 'strnatcasecmp');
			foreach ($types as $type => $counts) {
				$badges[] = [
					'type' => (string) $type,
					'origin' => $origin,
					'active' => (int) $counts['active'],
					'total' => (int) $counts['total']
				];
			}
		}

		return $badges;
	}

	/**
	 * Resolve the "site type" label for a site from the configured host tag.
	 *
	 * The tag lives only on the Edge Router host(s) of the site. Switches are scanned in a stable
	 * (natural-name) order and the first non-empty value of a tag whose name matches $tag_name wins.
	 * Returns '' when the feature is disabled, the site has no Edge Router, or none carries the tag.
	 */
	private function extractSiteType(array $switches, string $tag_name): string {
		if ($tag_name === '' || !$switches) {
			return '';
		}

		$ordered = array_values($switches);
		usort($ordered, static function ($a, $b) {
			return strnatcasecmp((string) ($a['host'] ?? ''), (string) ($b['host'] ?? ''));
		});

		foreach ($ordered as $host) {
			foreach (($host['tags'] ?? []) as $tag) {
				if ((string) ($tag['tag'] ?? '') === $tag_name) {
					$value = trim((string) ($tag['value'] ?? ''));
					if ($value !== '') {
						return $value;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Resolve the configured camera "online" rows into a list of ['key' => ..., 'type' => ...].
	 * Combines the multi-row per-type field (camera_online_items) with the legacy single field
	 * (camera_online_itemid), which is treated as the untyped fallback row. TYPE is uppercased.
	 */
	private function resolveCameraOnlineRows(): array {
		$rows_cfg = $this->fields_values['camera_online_items'] ?? [];
		$legacy = $this->fields_values['camera_online_itemid'] ?? [];

		$itemids = [];
		foreach ($rows_cfg as $row) {
			if (!empty($row['itemid'])) {
				$itemids[] = (int) $row['itemid'];
			}
		}

		$legacy_itemid = 0;
		if ($legacy) {
			$legacy_itemid = (int) (is_array($legacy) ? (reset($legacy) ?: 0) : $legacy);
			if ($legacy_itemid > 0) {
				$itemids[] = $legacy_itemid;
			}
		}

		$itemids = array_values(array_unique(array_filter($itemids)));

		if (!$itemids) {
			return [];
		}

		$items = API::Item()->get([
			'output' => ['itemid', 'key_'],
			'itemids' => $itemids,
			'webitems' => true,
			'preservekeys' => true
		]);

		$rows = [];
		foreach ($rows_cfg as $row) {
			$itemid = (int) ($row['itemid'] ?? 0);
			if ($itemid <= 0 || !isset($items[$itemid])) {
				continue;
			}
			$rows[] = [
				'key' => (string) $items[$itemid]['key_'],
				'type' => strtoupper(trim((string) ($row['type'] ?? '')))
			];
		}

		if ($legacy_itemid > 0 && isset($items[$legacy_itemid])) {
			$rows[] = [
				'key' => (string) $items[$legacy_itemid]['key_'],
				'type' => ''
			];
		}

		return $rows;
	}

	/**
	 * Build a [hostid => bool] online map for camera hosts.
	 *
	 * - No rows configured at all → every enabled camera counts as online (legacy default).
	 * - Otherwise the best matching row for the camera's TYPE is used (exact type first, then an
	 *   untyped fallback row). If no row matches the camera's type, the camera is NOT counted as
	 *   online. A matched row marks the host online iff its key's lastvalue == "1".
	 */
	private function computeCameraOnline(array $camera_hosts, array $online_rows): array {
		$map = [];

		if (!$online_rows) {
			foreach ($camera_hosts as $hid => $_host) {
				$map[(string) $hid] = true;
			}
			return $map;
		}

		$keys = array_values(array_unique(array_filter(array_column($online_rows, 'key'),
			static function ($k) { return $k !== ''; }
		)));

		$lastvalues = []; // hostid => key => lastvalue
		if ($keys && $camera_hosts) {
			$items = API::Item()->get([
				'output' => ['hostid', 'key_', 'lastvalue'],
				'hostids' => array_keys($camera_hosts),
				'filter' => ['key_' => $keys],
				'webitems' => true
			]);
			foreach ($items as $item) {
				$lastvalues[(string) $item['hostid']][$item['key_']] = (string) ($item['lastvalue'] ?? '');
			}
		}

		foreach ($camera_hosts as $hid => $host) {
			$hid_s = (string) $hid;
			$type = $this->extractType((string) $host['host']);
			$row = $this->matchTypeRow($online_rows, $type);

			if ($row === null || $row['key'] === '') {
				$map[$hid_s] = false;
				continue;
			}

			$map[$hid_s] = (($lastvalues[$hid_s][$row['key']] ?? null) === '1');
		}

		return $map;
	}

	/**
	 * Pick the best matching row for a camera TYPE: an exact (case-insensitive) type match wins;
	 * otherwise the first untyped row is used as fallback. Returns null when neither exists.
	 */
	private function matchTypeRow(array $rows, ?string $type): ?array {
		$fallback = null;

		foreach ($rows as $row) {
			$row_type = (string) ($row['type'] ?? '');
			if ($row_type === '') {
				if ($fallback === null) {
					$fallback = $row;
				}
				continue;
			}
			if ($type !== null && $row_type === $type) {
				return $row;
			}
		}

		return $fallback;
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
