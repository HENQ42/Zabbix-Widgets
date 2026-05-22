<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

$payload = json_encode($data['map'], JSON_UNESCAPED_SLASHES);
$asset_path = __DIR__.'/../map.html';
$asset_version = file_exists($asset_path) ? filemtime($asset_path) : 0;
$iframe_url = 'modules/camera_map/map.html?v='.$asset_version.'#'.urlencode($payload);

$iframe = (new CTag('iframe', true, ''))
	->setAttribute('src', $iframe_url)
	->setAttribute('style', 'width: 100%; height: 100%; border: none; display: block;')
	->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-top-navigation');

$view->addItem(
	(new CDiv($iframe))->addStyle('width:100%;height:100%;position:relative;')
);

$view->show();
