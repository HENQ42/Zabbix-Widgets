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
		(new CWidgetFieldMultiSelectItemView($data['fields']['lat_itemid']))
			->setPopupParameter('numeric', true)
	)
	->addField(
		(new CWidgetFieldMultiSelectItemView($data['fields']['lon_itemid']))
			->setPopupParameter('numeric', true)
	)
	->addField(
		new CWidgetFieldRangeControlView($data['fields']['default_zoom'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['center_lat'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['center_lon'])
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['show_labels'])
	)
	->addField($data['templateid'] === null
		? new CWidgetFieldMultiSelectOverrideHostView($data['fields']['override_hostid'])
		: null
	)
	->show();
