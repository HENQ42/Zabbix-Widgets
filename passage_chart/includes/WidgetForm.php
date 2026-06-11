<?php declare(strict_types = 0);

namespace Modules\PassageChart\Includes;

use CWidgetsData;
use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldMultiSelectHost,
	CWidgetFieldMultiSelectOverrideHost,
	CWidgetFieldRadioButtonList,
	CWidgetFieldTextBox
};

class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				new CWidgetFieldMultiSelectHost('hostids', _('Hosts'))
			)
			->addField(
				// default = host do seletor do dashboard; usado quando "Hosts" fica vazio
				(new CWidgetFieldMultiSelectOverrideHost())
					->setDefault([
						CWidgetField::FOREIGN_REFERENCE_KEY => CWidgetField::createTypedReference(
							CWidgetField::REFERENCE_DASHBOARD, CWidgetsData::DATA_TYPE_HOST_ID
						)
					])
			)
			->addField(
				(new CWidgetFieldTextBox('item_key', _('Item key (counter)')))
					->setDefault('pumatronix.detection.count')
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
					->setMaxLength(255)
			)
			->addField(
				(new CWidgetFieldTextBox('time_from', _('From')))
					->setDefault('now-24h')
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
					->setMaxLength(32)
			)
			->addField(
				(new CWidgetFieldTextBox('time_to', _('To')))
					->setDefault('now')
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
					->setMaxLength(32)
			)
			->addField(
				(new CWidgetFieldRadioButtonList('interval', _('Interval'), [
					0 => _('Auto'),
					900 => _('15m'),
					3600 => _('1h'),
					86400 => _('1d')
				]))->setDefault(0)
			)
			->addField(
				(new CWidgetFieldRadioButtonList('grouping', _('Group by'), [
					0 => _('Total'),
					1 => _('Per host (stacked)')
				]))->setDefault(0)
			)
			->addField(
				(new CWidgetFieldCheckBox('show_values', _('Show totals above bars')))
					->setDefault(1)
			);
	}
}
