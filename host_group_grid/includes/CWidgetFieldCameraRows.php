<?php declare(strict_types = 0);

namespace Modules\HostGroupGrid\Includes;

use Zabbix\Widgets\CWidgetField;

/**
 * Multi-row field for "Camera online items": each row pairs an item with an optional camera TYPE
 * (the TIPO token of the host nomenclature, e.g. PTX, PTZ). The item's key is then applied only to
 * cameras whose name resolves to that TYPE. A row with an empty type acts as the fallback for any
 * camera type that has no specific row.
 */
class CWidgetFieldCameraRows extends CWidgetField {

	public const DEFAULT_VIEW = CWidgetFieldCameraRowsView::class;
	public const DEFAULT_VALUE = [];

	public const DEFAULT_ROW = [
		'itemid' => 0,
		'type' => ''
	];

	public function __construct(string $name, ?string $label = null) {
		parent::__construct($name, $label);

		$this
			->setDefault(self::DEFAULT_VALUE)
			->setValidationRules(['type' => API_OBJECTS, 'fields' => [
				'itemid' => ['type' => API_ID],
				'type' => ['type' => API_STRING_UTF8, 'length' => 50]
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

			$rows[] = [
				'itemid' => (string) $itemid,
				'type' => trim((string) ($row['type'] ?? ''))
			];
		}

		return parent::setValue($rows);
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
				'name' => $this->name.'.'.$i.'.type',
				'value' => $row['type']
			];
		}
	}
}
