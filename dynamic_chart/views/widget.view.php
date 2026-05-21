<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

$payload = json_encode($data['chart'], JSON_UNESCAPED_SLASHES);
$iframe_url = 'modules/dynamic_chart/chart.html#' . urlencode($payload);

$iframe = (new CTag('iframe', true, ''))
	->setAttribute('src', $iframe_url)
	->setAttribute('style', 'width: 100%; height: 100%; border: none; display: block;')
	->setAttribute('sandbox', 'allow-scripts allow-same-origin');

$view->addItem(
	(new CDiv($iframe))->addStyle('width:100%;height:100%;position:relative;')
);

$view->show();
