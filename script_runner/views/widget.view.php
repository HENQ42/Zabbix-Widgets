<?php declare(strict_types = 0);

/**
 * Script Runner widget view.
 *
 * Renderiza apenas o "casco": um container com o catalogo (base64 JSON) e o token
 * CSRF embutidos. Toda a interface (catalogo, formularios por script, resultado) e
 * construida pela classe JS CWidgetScriptRunner a partir desses dados, uma unica vez.
 *
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

$catalog_json = json_encode($data['catalog'] ?? ['scripts' => [], 'errors' => []], JSON_UNESCAPED_UNICODE);

$root = (new CDiv())
	->addClass('script-runner')
	->setAttribute('data-catalog', base64_encode($catalog_json))
	->setAttribute('data-csrf', CCsrfTokenHelper::get('widget'));

$view->addItem($root);

$view->show();
