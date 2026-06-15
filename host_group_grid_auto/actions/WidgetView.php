<?php declare(strict_types = 0);

namespace Modules\HostGroupGridAuto\Actions;

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	Modules\HostGroupGridAuto\Includes\CWidgetFieldItemRows;

class WidgetView extends CControllerDashboardWidgetView {

	// Captures the host-name prefix (everything before the site number) and the 2-digit site number.
	// Non-greedy prefix grabs the FIRST 2-digit block after an underscore, so
	// `SEFAZ_AL_03_MIKROTIK` -> prefix=`SEFAZ_AL`, site=`03`. The site key returned to callers is
	// the composite `prefix_site` (e.g. `SEFAZ_AL_03`), so multiple prefixes can coexist without
	// colliding on the numeric portion alone.
	private const SITE_REGEX = '/^(?P<prefix>.+?)_(?P<site>\d{2})(?:_.*)?$/';

	// Fixed item key carrying each host's "online" state (1 = online, 0 = offline). Standardised across
	// every template as a dependent item that mirrors the template's own availability item, so the
	// widget needs no online-item configuration at all. Must match the key used in the templates.
	private const ONLINE_KEY = 'host.availability';

	protected function doAction(): void {
		$name = $this->getInput('name', $this->widget->getDefaultName());

		$parent_groupids = $this->fields_values['parent_group'] ?? [];

		// Fixed status palette (not user-configurable): green = stable, orange = warning, red = critical.
		$color_stable = '16A34A';
		$color_warning = 'D97706';
		$color_critical = 'DC2626';

		$empty_response = [
			'name' => $name,
			'sites' => [],
			'color_stable' => $color_stable,
			'color_critical' => $color_critical,
			'color_warning' => $color_warning,
			'user' => ['debug_mode' => $this->getDebugMode()]
		];

		if (!$parent_groupids) {
			$this->setResponse(new CControllerResponseData($empty_response));
			return;
		}

		// Auto-discover the child groups under the selected parent(s) and derive the host TYPE from the
		// group name. With parent `EMPRESA/CONTRATO`, a child `EMPRESA/CONTRATO/CAMERA/HKV` yields
		// TYPE=CAMERA (the first path segment after the parent). One TYPE may span several models
		// (CAMERA/HKV + CAMERA/PTX) — both map back to CAMERA.
		$group_tipo = $this->discoverChildGroupTypes($parent_groupids);
		$child_groupids = array_keys($group_tipo);

		if (!$child_groupids) {
			$this->setResponse(new CControllerResponseData($empty_response));
			return;
		}

		// Fetch only enabled (monitored) hosts. Pull group membership to map each host to its TYPE.
		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name'],
			'selectHostGroups' => ['groupid'],
			'groupids' => $child_groupids,
			'monitored_hosts' => true,
			'preservekeys' => true
		]);

		if (!$hosts) {
			$this->setResponse(new CControllerResponseData($empty_response));
			return;
		}

		// hostid => TYPE, resolved via the host's membership in the discovered child groups.
		$host_tipo = [];
		foreach ($hosts as $hid => $host) {
			foreach (($host['hostgroups'] ?? []) as $hg) {
				if (isset($group_tipo[$hg['groupid']])) {
					$host_tipo[(string) $hid] = $group_tipo[$hg['groupid']];
					break;
				}
			}
		}

		// Group hosts per site number using the canonical nomenclature regex.
		$sites = []; // site_id => ['hosts' => [hostid => host]]

		foreach ($hosts as $hid => $host) {
			$site = $this->extractSite($host['host']);
			if ($site === null) {
				continue;
			}
			$sites[$site]['hosts'][(string) $hid] = $host;
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

		// Resolve "online" status per host via the fixed dependent-item key (lastvalue == "1" => online).
		$online_hostids = $this->fetchOnlineHostids($hosts, self::ONLINE_KEY);

		// Collect every relevant hostid for a single bulk events query.
		$all_hostids = [];
		foreach ($sites as $site_id => $info) {
			foreach (($info['hosts'] ?? []) as $hid => $_) {
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
							'host' => (string) ($hosts[$hid]['name'] ?? '')
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

		// Resolve drill-down item rows (one set, applied per host by key_ matching, optionally
		// restricted to a host TYPE) — same model as Host Item Grid.
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

		// Collect every configured key once and fetch matching items across all hosts in a single call.
		$row_keys = [];
		foreach ($item_rows as $row) {
			$src_item = $src_items[$row['itemid'] ?? 0] ?? null;
			if ($src_item === null) {
				continue;
			}
			$row_keys[$src_item['key_']] = true;
		}

		$items_by_host_key = [];
		if ($row_keys && $all_hostids) {
			$fetched = API::Item()->get([
				'output' => ['itemid', 'hostid', 'key_', 'lastvalue', 'value_type', 'name_resolved'],
				'hostids' => $all_hostids,
				'filter' => ['key_' => array_keys($row_keys)],
				'webitems' => true
			]);
			foreach ($fetched as $item) {
				$items_by_host_key[$item['hostid']][$item['key_']] = $item;
			}
		}

		// Build output per site.
		$output_sites = [];

		foreach ($sites as $site_id => $info) {
			$site_hosts = $info['hosts'] ?? [];

			$total = count($site_hosts);
			$active = 0;
			foreach ($site_hosts as $hid => $host) {
				if (isset($online_hostids[(string) $hid])) {
					$active++;
				}
			}

			// Aggregate timeline across all site hosts (max severity wins).
			$site_hostids = array_keys($site_hosts);

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
			foreach ($site_hosts as $hid => $host_obj) {
				$hid_s = (string) $hid;
				$tipo = $host_tipo[$hid_s] ?? 'OUTROS';
				$is_online = isset($online_hostids[$hid_s]);

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
					// A row may be restricted to a specific TYPE. An empty type applies to every host.
					$row_type = strtoupper(trim((string) ($row['type'] ?? '')));
					if ($row_type !== '' && $row_type !== $tipo) {
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

				// Nome curto para o drill-down: o nome técnico segue SEFAZ_AL_<SITE>_<TIPO>_<SUBTIPO>_<VALOR>
				// e $site_id é justamente o prefixo `<PREFIXO>_<SITE>` (ex.: SEFAZ_AL_03). Removendo esse
				// prefixo sobra <TIPO>_<SUBTIPO>_<VALOR> (ex.: PTX_SENTIDO_NORTE-SUL). Se o nome não casar
				// com o prefixo (nome amigável fora do padrão), mantém o nome completo como fallback.
				$technical_name = (string) $host_obj['host'];
				$full_name = (string) ($host_obj['name'] ?? $host_obj['host']);
				$site_prefix = $site_id.'_';
				$short_name = (strncmp($technical_name, $site_prefix, strlen($site_prefix)) === 0)
					? substr($technical_name, strlen($site_prefix))
					: $full_name;

				$hosts_detail[] = [
					'hostid' => $hid_s,
					'name' => $full_name,
					'short_name' => $short_name,
					'host' => (string) $host_obj['host'],
					'type' => $tipo,
					'online' => $is_online,
					'state' => $h_status,
					'timeline' => $h_timeline,
					'rows' => $host_rows
				];
			}

			// Order hosts: criticals first, then unstable, then stable; within the same state, natural
			// order by TYPE, then by host name.
			usort($hosts_detail, static function ($a, $b) {
				$rank = ['critical' => 2, 'unstable' => 1, 'stable' => 0];
				$ra = $rank[$a['state']] ?? 0;
				$rb = $rank[$b['state']] ?? 0;
				if ($ra !== $rb) {
					return $rb <=> $ra;
				}
				if ($a['type'] !== $b['type']) {
					return strnatcasecmp($a['type'], $b['type']);
				}
				return strnatcasecmp($a['name'], $b['name']);
			});

			$output_sites[] = [
				'site_id' => $site_id,
				'site_label' => $site_id,
				'total' => $total,
				'active' => $active,
				'types' => $this->buildTypeBadges($site_hosts, $host_tipo, $online_hostids),
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
			'color_stable' => $color_stable,
			'color_critical' => $color_critical,
			'color_warning' => $color_warning,
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	/**
	 * Discover the child host groups under the selected parent group(s) and derive the host TYPE from
	 * each child group name.
	 *
	 * With parent `EMPRESA/CONTRATO`, a child group `EMPRESA/CONTRATO/CAMERA/HKV` yields TYPE=CAMERA —
	 * the first path segment after the parent prefix. Several models map back to the same TYPE
	 * (CAMERA/HKV + CAMERA/PTX => CAMERA). The parent group itself is not a child and is skipped.
	 *
	 * @return array<string, string> groupid => TYPE (uppercased)
	 */
	private function discoverChildGroupTypes(array $parent_groupids): array {
		$parent_groups = API::HostGroup()->get([
			'output' => ['name'],
			'groupids' => $parent_groupids
		]);

		if (!$parent_groups) {
			return [];
		}

		$parent_names = array_column($parent_groups, 'name');

		$all_groups = API::HostGroup()->get([
			'output' => ['groupid', 'name']
		]);

		$group_tipo = [];
		foreach ($all_groups as $group) {
			$gname = (string) $group['name'];
			foreach ($parent_names as $pname) {
				$prefix = $pname.'/';
				if (strncmp($gname, $prefix, strlen($prefix)) !== 0) {
					continue;
				}
				$rest = substr($gname, strlen($prefix));
				$segments = explode('/', $rest);
				$tipo = strtoupper(trim($segments[0] ?? ''));
				if ($tipo !== '') {
					$group_tipo[(string) $group['groupid']] = $tipo;
				}
				break;
			}
		}

		return $group_tipo;
	}

	private function extractSite(string $host_technical_name): ?string {
		if (preg_match(self::SITE_REGEX, $host_technical_name, $m)) {
			return $m['prefix'].'_'.$m['site'];
		}
		return null;
	}

	/**
	 * Build the per-TYPE active/total list rendered as card badges.
	 *
	 * The TYPE comes from the host's group membership (resolved upstream into $host_tipo). Hosts whose
	 * TYPE could not be resolved fall under OUTROS so they surface rather than vanish. Types are listed
	 * in natural-name order. "Online" reuses the same fixed-key resolution as the rest of the widget.
	 *
	 * @return array<int, array{type: string, active: int, total: int}>
	 */
	private function buildTypeBadges(array $site_hosts, array $host_tipo, array $online_hostids): array {
		$groups = [];

		foreach ($site_hosts as $hid => $host) {
			$hid_s = (string) $hid;
			$type = $host_tipo[$hid_s] ?? 'OUTROS';
			if (!isset($groups[$type])) {
				$groups[$type] = ['active' => 0, 'total' => 0];
			}
			$groups[$type]['total']++;
			if (isset($online_hostids[$hid_s])) {
				$groups[$type]['active']++;
			}
		}

		uksort($groups, 'strnatcasecmp');

		$badges = [];
		foreach ($groups as $type => $counts) {
			$badges[] = [
				'type' => (string) $type,
				'active' => (int) $counts['active'],
				'total' => (int) $counts['total']
			];
		}

		return $badges;
	}

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

	/**
	 * Returns [hostid => true] for hosts whose configured "online" item key has lastvalue == "1".
	 * Hosts missing the item (or with any other value) are treated as offline.
	 */
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
