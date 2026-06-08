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
use Modules\HostGroupGrid\Includes\CWidgetFieldCameraRows;

class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				(new CWidgetFieldMultiSelectGroup('switch_groupids', _('Edge Router host groups')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldMultiSelectGroup('camera_groupids', _('Camera host groups')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldMultiSelectItem('switch_online_itemid', _('Edge Router online item')))
					->setMultiple(false)
			)
			->addField(
				(new CWidgetFieldMultiSelectItem('camera_online_itemid', _('Camera online item (default)')))
					->setMultiple(false)
			)
			->addField(
				new CWidgetFieldCameraRows('camera_online_items', _('Camera online items (by type)'))
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
