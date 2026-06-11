<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

(new CWidgetFormView($data))
	->addField(
		new CWidgetFieldRadioButtonListView($data['fields']['sample_type'])
	)
	->addField(
		(new CWidgetFieldMultiSelectItemView($data['fields']['main_item']))
			->setPopupParameter('numeric', true)
	)
	->addField(
		(new CWidgetFieldTextBoxView($data['fields']['main_label']))
			->setPlaceholder(_('Uso total, Temperatura, ...'))
	)
	->addField(
		(new CWidgetFieldMultiSelectItemView($data['fields']['used_item']))
			->setPopupParameter('numeric', true)
	)
	->addField(
		(new CWidgetFieldMultiSelectItemView($data['fields']['total_item']))
			->setPopupParameter('numeric', true)
	)
	->addField(
		(new CWidgetFieldMultiSelectItemView($data['fields']['core_items']))
			->setPopupParameter('numeric', true)
	)
	->addField(
		(new CWidgetFieldTextBoxView($data['fields']['time_from']))
			->setPlaceholder(_('now-1h, now-24h, now/d, ...'))
	)
	->addField(
		(new CWidgetFieldTextBoxView($data['fields']['time_to']))
			->setPlaceholder(_('now, now/d, now-1h, ...'))
	)
	->addField(
		(new CWidgetFieldTextBoxView($data['fields']['accent_color']))
			->setPlaceholder('#1f7faa')
	)
	->addField(
		new CWidgetFieldRangeControlView($data['fields']['line_thickness'])
	)
	->addField(
		new CWidgetFieldRangeControlView($data['fields']['fill_intensity'])
	)
	->addField($data['templateid'] === null
		? new CWidgetFieldMultiSelectOverrideHostView($data['fields']['override_hostid'])
		: null
	)
	->show();
