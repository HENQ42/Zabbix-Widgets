<?php declare(strict_types = 0);

namespace Modules\HostGroupGridAuto\Includes;

use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\CWidgetFieldMultiSelectGroup;
use Modules\HostGroupGridAuto\Includes\CWidgetFieldItemRows;
use Modules\HostGroupGridAuto\Includes\CWidgetFieldSiteTypeRows;

class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			// Single parent group (e.g. EMPRESA/CONTRATO). The child groups
			// (EMPRESA/CONTRATO/TIPO/MODELO) are auto-discovered, the host TYPE is derived from the
			// group name, and "online" is resolved from a fixed dependent item key on every host
			// (self::ONLINE_KEY in WidgetView) — so there is no per-type / per-item config here.
			// Status colours are fixed in WidgetView (green / orange / red) and the card grid
			// auto-fits its column count to the widget width — neither is user-configurable.
			->addField(
				(new CWidgetFieldMultiSelectGroup('parent_group', _('Grupo de hosts pai')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				new CWidgetFieldItemRows('items', _('Itens (drill-down)'))
			)
			// Relação "tipo de site → sites" (no fim do formulário). Cópia de trabalho no widget_field;
			// a persistência durável é feita pelas predefinições (PresetStore), salvas/carregadas neste
			// grid via AJAX e atreladas a um grupo de usuários dono.
			->addField(
				new CWidgetFieldSiteTypeRows('site_types', _('Tipos de site (predefinições)'))
			);
	}
}
