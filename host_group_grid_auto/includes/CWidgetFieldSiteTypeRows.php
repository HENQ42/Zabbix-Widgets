<?php declare(strict_types = 0);

namespace Modules\HostGroupGridAuto\Includes;

use Zabbix\Widgets\CWidgetField;

/**
 * Campo do editor: a relação "tipo de site → sites". Cada linha é um tipo de site definido pelo usuário
 * (ex.: "Pórtico", "Posto fiscal") com uma cor e a lista dos números de site atrelados (ex.: "03, 07, 11").
 *
 * Esta é a CÓPIA DE TRABALHO: vive no widget_field e some quando o widget é apagado. A persistência de
 * longo prazo é feita pelas predefinições (PresetStore), salvas/carregadas a partir deste grid via AJAX.
 */
class CWidgetFieldSiteTypeRows extends CWidgetField {

	public const DEFAULT_VIEW = CWidgetFieldSiteTypeRowsView::class;
	public const DEFAULT_VALUE = [];

	public const DEFAULT_ROW = [
		'name' => '',
		'color' => '',
		'sites' => ''
	];

	public function __construct(string $name, ?string $label = null) {
		parent::__construct($name, $label);

		$this
			->setDefault(self::DEFAULT_VALUE)
			->setValidationRules(['type' => API_OBJECTS, 'fields' => [
				'name' => ['type' => API_STRING_UTF8, 'length' => 255],
				'color' => ['type' => API_COLOR, 'flags' => API_ALLOW_NULL],
				// Lista de números de site separados por vírgula/espaço (ex.: "03, 07, 11"). Mantida como
				// texto cru no widget_field; a normalização para array acontece ao montar a predefinição.
				'sites' => ['type' => API_STRING_UTF8, 'length' => 2048]
			]]);
	}

	public function setValue($value): self {
		$rows = [];

		foreach ((array) $value as $row) {
			if (!is_array($row)) {
				continue;
			}

			$name = trim((string) ($row['name'] ?? ''));
			$sites = trim((string) ($row['sites'] ?? ''));

			// Descarta linhas totalmente vazias (sem nome e sem sites).
			if ($name === '' && $sites === '') {
				continue;
			}

			$rows[] = [
				'name' => $name,
				'color' => (string) ($row['color'] ?? ''),
				'sites' => $sites
			];
		}

		return parent::setValue($rows);
	}

	public function toApi(array &$widget_fields = []): void {
		foreach ($this->getValue() as $i => $row) {
			$widget_fields[] = [
				'type' => ZBX_WIDGET_FIELD_TYPE_STR,
				'name' => $this->name.'.'.$i.'.name',
				'value' => $row['name']
			];
			$widget_fields[] = [
				'type' => ZBX_WIDGET_FIELD_TYPE_STR,
				'name' => $this->name.'.'.$i.'.color',
				'value' => $row['color']
			];
			$widget_fields[] = [
				'type' => ZBX_WIDGET_FIELD_TYPE_STR,
				'name' => $this->name.'.'.$i.'.sites',
				'value' => $row['sites']
			];
		}
	}
}
