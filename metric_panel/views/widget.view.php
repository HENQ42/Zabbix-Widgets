<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

$payload = json_encode($data['metric'], JSON_UNESCAPED_SLASHES);
$asset_path = __DIR__.'/../panel.html';
$asset_version = file_exists($asset_path) ? filemtime($asset_path) : 0;
$iframe_url = 'modules/metric_panel/panel.html?v='.$asset_version.'#'.rawurlencode($payload);

$iframe = (new CTag('iframe', true, ''))
	->setAttribute('src', $iframe_url)
	->setAttribute('style', 'width: 100%; height: 100%; border: none; display: block;')
	->setAttribute('sandbox', 'allow-scripts allow-same-origin');

$view->addItem(
	(new CDiv($iframe))->addStyle('width:100%;height:100%;position:relative;')
);

$view->show();
