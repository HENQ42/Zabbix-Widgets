<?php declare(strict_types = 0);

namespace Modules\CameraMap\Includes;

use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldMultiSelectHost,
	CWidgetFieldMultiSelectItem,
	CWidgetFieldMultiSelectOverrideHost,
	CWidgetFieldRangeControl,
	CWidgetFieldTextBox
};

class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				new CWidgetFieldMultiSelectHost('hostids', _('Hosts'))
			)
			->addField(
				new CWidgetFieldMultiSelectOverrideHost()
			)
			->addField(
				(new CWidgetFieldMultiSelectItem('lat_itemid', _('Latitude item')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
					->setMultiple(false)
			)
			->addField(
				(new CWidgetFieldMultiSelectItem('lon_itemid', _('Longitude item')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
					->setMultiple(false)
			)
			->addField(
				(new CWidgetFieldRangeControl('default_zoom', _('Initial zoom (1-18)'), 1, 18, 1))
					->setDefault(13)
			)
			->addField(
				(new CWidgetFieldTextBox('center_lat', _('Initial center latitude (optional)')))
					->setDefault('')
					->setMaxLength(20)
			)
			->addField(
				(new CWidgetFieldTextBox('center_lon', _('Initial center longitude (optional)')))
					->setDefault('')
					->setMaxLength(20)
			)
			->addField(
				(new CWidgetFieldCheckBox('show_labels', _('Show host name beside pin')))
					->setDefault(1)
			);
	}
}
