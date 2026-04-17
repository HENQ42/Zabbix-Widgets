<?php declare(strict_types = 0);

namespace Modules\HostItemGrid\Includes;

use Zabbix\Widgets\CWidgetField;

class CWidgetFieldItemRows extends CWidgetField {

	public const DEFAULT_VIEW = CWidgetFieldItemRowsView::class;
	public const DEFAULT_VALUE = [];

	public const STATE_STABLE = 0;
	public const STATE_CRITICAL = 1;

	public const DEFAULT_ROW = [
		'itemid' => 0,
		'label' => '',
		'regex' => '',
		'bold' => 0,
		'default_color' => '',
		'default_state' => self::STATE_STABLE,
		'conditions' => []
	];

	public const DEFAULT_CONDITION = [
		'value' => '',
		'display' => '',
		'color' => '',
		'state' => self::STATE_STABLE
	];

	public function __construct(string $name, ?string $label = null) {
		parent::__construct($name, $label);

		$this
			->setDefault(self::DEFAULT_VALUE)
			->setValidationRules(['type' => API_OBJECTS, 'fields' => [
				'itemid' => ['type' => API_ID],
				'label' => ['type' => API_STRING_UTF8, 'length' => 255],
				'regex' => ['type' => API_STRING_UTF8, 'length' => 500],
				'bold' => ['type' => API_INT32, 'in' => '0,1'],
				'default_color' => ['type' => API_COLOR, 'flags' => API_ALLOW_NULL],
				'default_state' => ['type' => API_INT32, 'in' => '0,1'],
				'conditions' => ['type' => API_OBJECTS, 'fields' => [
					'value' => ['type' => API_STRING_UTF8, 'length' => 255],
					'display' => ['type' => API_STRING_UTF8, 'length' => 255],
					'color' => ['type' => API_COLOR, 'flags' => API_ALLOW_NULL],
					'state' => ['type' => API_INT32, 'in' => '0,1']
				]]
			]]);
	}

	public function setValue($value): self {
		$rows = [];

		foreach ((array) $value as $row) {
			if (!is_array($row)) {
				continue;
			}

			$itemid = 0;

			if (isset($row['itemid'])) {
				if (is_array($row['itemid'])) {
					$itemid = (int) (reset($row['itemid']) ?: 0);
				}
				else {
					$itemid = (int) $row['itemid'];
				}
			}

			if ($itemid <= 0) {
				continue;
			}

			$conditions = [];

			if (isset($row['conditions']) && is_array($row['conditions'])) {
				foreach ($row['conditions'] as $cond) {
					if (is_array($cond) && isset($cond['value'])) {
						$conditions[] = [
							'value' => (string) $cond['value'],
							'display' => (string) ($cond['display'] ?? ''),
							'color' => (string) ($cond['color'] ?? ''),
							'state' => (int) ($cond['state'] ?? self::STATE_STABLE)
						];
					}
				}
			}

			$rows[] = [
				'itemid' => (string) $itemid,
				'label' => (string) ($row['label'] ?? ''),
				'regex' => (string) ($row['regex'] ?? ''),
				'bold' => (int) ($row['bold'] ?? 0),
				'default_color' => (string) ($row['default_color'] ?? ''),
				'default_state' => (int) ($row['default_state'] ?? self::STATE_STABLE),
				'conditions' => $conditions
			];
		}

		return parent::setValue($rows);
	}

	public function validate(bool $strict = false): array {
		$errors = parent::validate($strict);

		if ($errors) {
			return $errors;
		}

		if (($this->getFlags() & self::FLAG_NOT_EMPTY) && !$this->getValue()) {
			$errors[] = _s('Invalid parameter "%1$s": %2$s.', $this->getLabel() ?? $this->name,
				_('cannot be empty')
			);
		}

		return $errors;
	}

	public function toApi(array &$widget_fields = []): void {
		foreach ($this->getValue() as $i => $row) {
			$widget_fields[] = [
				'type' => ZBX_WIDGET_FIELD_TYPE_ITEM,
				'name' => $this->name.'.'.$i.'.itemid',
				'value' => $row['itemid']
			];
			$widget_fields[] = [
				'type' => ZBX_WIDGET_FIELD_TYPE_STR,
				'name' => $this->name.'.'.$i.'.label',
				'value' => $row['label']
			];
			$widget_fields[] = [
				'type' => ZBX_WIDGET_FIELD_TYPE_STR,
				'name' => $this->name.'.'.$i.'.regex',
				'value' => $row['regex']
			];
			$widget_fields[] = [
				'type' => ZBX_WIDGET_FIELD_TYPE_INT32,
				'name' => $this->name.'.'.$i.'.bold',
				'value' => $row['bold']
			];
			$widget_fields[] = [
				'type' => ZBX_WIDGET_FIELD_TYPE_STR,
				'name' => $this->name.'.'.$i.'.default_color',
				'value' => $row['default_color']
			];
			$widget_fields[] = [
				'type' => ZBX_WIDGET_FIELD_TYPE_INT32,
				'name' => $this->name.'.'.$i.'.default_state',
				'value' => $row['default_state']
			];

			foreach ($row['conditions'] as $ci => $cond) {
				$widget_fields[] = [
					'type' => ZBX_WIDGET_FIELD_TYPE_STR,
					'name' => $this->name.'.'.$i.'.conditions.'.$ci.'.value',
					'value' => $cond['value']
				];
				$widget_fields[] = [
					'type' => ZBX_WIDGET_FIELD_TYPE_STR,
					'name' => $this->name.'.'.$i.'.conditions.'.$ci.'.display',
					'value' => $cond['display']
				];
				$widget_fields[] = [
					'type' => ZBX_WIDGET_FIELD_TYPE_STR,
					'name' => $this->name.'.'.$i.'.conditions.'.$ci.'.color',
					'value' => $cond['color']
				];
				$widget_fields[] = [
					'type' => ZBX_WIDGET_FIELD_TYPE_INT32,
					'name' => $this->name.'.'.$i.'.conditions.'.$ci.'.state',
					'value' => $cond['state']
				];
			}
		}
	}
}
