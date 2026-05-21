<?php declare(strict_types = 0);

namespace Modules\DynamicChart\Includes;

use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldCheckBoxList,
	CWidgetFieldMultiSelectHost,
	CWidgetFieldMultiSelectItem,
	CWidgetFieldRangeControl,
	CWidgetFieldTextBox
};

class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				(new CWidgetFieldMultiSelectHost('hostids', _('Hosts')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldMultiSelectItem('itemid', _('Item')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
					->setMultiple(false)
			)
			->addField(
				(new CWidgetFieldTextBox('time_from', _('From')))
					->setDefault('now-1d')
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
				(new CWidgetFieldRangeControl('line_thickness', _('Line thickness (0-10)'), 0, 10, 1))
					->setDefault(3)
			)
			->addField(
				(new CWidgetFieldRangeControl('fill_intensity', _('Fill intensity (0-10)'), 0, 10, 1))
					->setDefault(2)
			)
			->addField(
				(new CWidgetFieldCheckBox('business_enabled', _('Highlight business hours')))
					->setDefault(0)
			)
			->addField(
				(new CWidgetFieldTextBox('business_start', _('Business start (HH:MM)')))
					->setDefault('08:00')
					->setMaxLength(5)
			)
			->addField(
				(new CWidgetFieldTextBox('business_end', _('Business end (HH:MM)')))
					->setDefault('18:00')
					->setMaxLength(5)
			)
			->addField(
				(new CWidgetFieldCheckBoxList('business_days', _('Business days'), [
					1 => _('Mon'),
					2 => _('Tue'),
					3 => _('Wed'),
					4 => _('Thu'),
					5 => _('Fri'),
					6 => _('Sat'),
					7 => _('Sun')
				]))->setDefault([1, 2, 3, 4, 5])
			)
			->addField(
				(new CWidgetFieldCheckBox('show_extremes',
					_('Show only top-average and bottom-average hosts')))
					->setDefault(0)
			);
	}
}
