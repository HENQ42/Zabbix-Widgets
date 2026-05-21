<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

(new CWidgetFormView($data))
	->addField(
		new CWidgetFieldMultiSelectHostView($data['fields']['hostids'])
	)
	->addField(
		(new CWidgetFieldMultiSelectItemView($data['fields']['itemid']))
			->setPopupParameter('numeric', true)
	)
	->addField(
		(new CWidgetFieldTextBoxView($data['fields']['time_from']))
			->setPlaceholder(_('now-1d, now-24h, now-7d, now/d, ...'))
	)
	->addField(
		(new CWidgetFieldTextBoxView($data['fields']['time_to']))
			->setPlaceholder(_('now, now/d, now-1h, ...'))
	)
	->addField(
		new CWidgetFieldRangeControlView($data['fields']['line_thickness'])
	)
	->addField(
		new CWidgetFieldRangeControlView($data['fields']['fill_intensity'])
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['business_enabled'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['business_start'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['business_end'])
	)
	->addField(
		(new CWidgetFieldCheckBoxListView($data['fields']['business_days']))->setColumns(7)
	)
	->addField(
		new CWidgetFieldIntegerBoxView($data['fields']['bottom_count'])
	)
	->addField($data['templateid'] === null
		? new CWidgetFieldMultiSelectOverrideHostView($data['fields']['override_hostid'])
		: null
	)
	->show();
