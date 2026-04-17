<?php declare(strict_types = 0);

namespace Modules\HostItemGrid\Includes;

use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\{
	CWidgetFieldColor,
	CWidgetFieldIntegerBox,
	CWidgetFieldMultiSelectGroup,
	CWidgetFieldMultiSelectHost
};

class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				new CWidgetFieldMultiSelectGroup('groupids', _('Host groups'))
			)
			->addField(
				new CWidgetFieldMultiSelectHost('hostids', _('Hosts'))
			)
			->addField(
				(new CWidgetFieldIntegerBox('columns', _('Columns'), 1, 12))
					->setDefault(3)
					->setFlags(CWidgetField::FLAG_NOT_EMPTY)
			)
			->addField(
				(new CWidgetFieldColor('color_stable', _('Stable color')))
					->setDefault('4CAF50')
			)
			->addField(
				(new CWidgetFieldColor('color_critical', _('Critical color')))
					->setDefault('E53935')
			)
			->addField(
				(new CWidgetFieldColor('color_warning', _('Warning color')))
					->setDefault('FFA726')
			)
			->addField(
				(new CWidgetFieldItemRows('items', _('Items')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			);
	}
}
