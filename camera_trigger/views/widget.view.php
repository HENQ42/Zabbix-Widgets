<?php declare(strict_types = 0);
/**
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

$config = $data['config'];

if ($data['error'] !== '' || $config['base'] === '') {
	$view->addItem(
		(new CDiv($data['error'] !== '' ? $data['error'] : _('Camera URL macro is empty.')))
			->addStyle('display:flex;align-items:center;justify-content:center;height:100%;color:#768d99;')
	);
	$view->show();
	return;
}

$payload = json_encode($config, JSON_UNESCAPED_SLASHES);
$asset_path = __DIR__.'/../viewer.html';
$asset_version = file_exists($asset_path) ? filemtime($asset_path) : 0;
$iframe_url = 'modules/camera_trigger/viewer.html?v='.$asset_version.'#'.urlencode($payload);

$iframe = (new CTag('iframe', true, ''))
	->setAttribute('src', $iframe_url)
	->setAttribute('style', 'width: 100%; height: 100%; border: none; display: block;')
	->setAttribute('sandbox', 'allow-scripts allow-same-origin');

$view->addItem(
	(new CDiv($iframe))->addStyle('width:100%;height:100%;position:relative;')
);

$view->show();
