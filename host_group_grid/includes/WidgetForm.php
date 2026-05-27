<?php declare(strict_types = 0);

namespace Modules\HostGroupGrid\Includes;

use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\{
	CWidgetFieldColor,
	CWidgetFieldIntegerBox,
	CWidgetFieldMultiSelectGroup,
	CWidgetFieldMultiSelectItem
};
use Modules\HostGroupGrid\Includes\CWidgetFieldItemRows;

class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				(new CWidgetFieldMultiSelectGroup('switch_groupids', _('Switch host groups')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldMultiSelectGroup('camera_groupids', _('Camera host groups')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldMultiSelectItem('switch_online_itemid', _('Switch online item')))
					->setMultiple(false)
			)
			->addField(
				(new CWidgetFieldMultiSelectItem('camera_online_itemid', _('Camera online item')))
					->setMultiple(false)
			)
			->addField(
				(new CWidgetFieldIntegerBox('columns', _('Columns'), 1, 12))
					->setDefault(3)
					->setFlags(CWidgetField::FLAG_NOT_EMPTY)
			)
			->addField(
				(new CWidgetFieldColor('color_stable', _('Stable color')))->allowInherited()
			)
			->addField(
				(new CWidgetFieldColor('color_critical', _('Critical color')))->allowInherited()
			)
			->addField(
				(new CWidgetFieldColor('color_warning', _('Warning color')))->allowInherited()
			)
			->addField(
				new CWidgetFieldItemRows('items', _('Items (drill-down)'))
			);
	}
}
