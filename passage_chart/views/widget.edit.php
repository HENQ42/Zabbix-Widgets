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
		new CWidgetFieldTextBoxView($data['fields']['item_key'])
	)
	->addField(
		(new CWidgetFieldTextBoxView($data['fields']['time_from']))
			->setPlaceholder(_('now-24h, now-7d, now/d, ...'))
	)
	->addField(
		(new CWidgetFieldTextBoxView($data['fields']['time_to']))
			->setPlaceholder(_('now, now/d, now-1h, ...'))
	)
	->addField(
		new CWidgetFieldRadioButtonListView($data['fields']['interval'])
	)
	->addField(
		new CWidgetFieldRadioButtonListView($data['fields']['grouping'])
	)
	->addField(
		new CWidgetFieldCheckBoxView($data['fields']['show_values'])
	)
	->addField($data['templateid'] === null
		? new CWidgetFieldMultiSelectOverrideHostView($data['fields']['override_hostid'])
		: null
	)
	->show();
