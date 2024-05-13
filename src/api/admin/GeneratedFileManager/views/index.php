<?php

use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminManager;
use MagratheaImages3\Apikey\ApikeyControl;

$elements = AdminElements::Instance();
$elements->Header("Generated files");

$feature = AdminManager::Instance()->GetActiveFeature();
$control = new ApikeyControl();
$apiKeys = $control->GetAll();

$folders = array_map(function($k) {
	return [
		"id" => $k->id,
		"name" => $k->folder." [".$k->private_key."]",
	];
}, $apiKeys);

?>
<div class="container p-4">
	<div class="row mb-2">
		<div class="col-12">
			<div class="card">
				<div class="card-header">
					Folder to view
				</div>
				<div class="card-body">
			<?
			$elements->Select(
				"apikey",
				"ApiKey",
				$folders,
				'', [],
				"Select folder",
				[
					"onchange" => "selectApiKey(this);"
				]
			);
			?>
				</div>
			</div>
		</div>
	</div>
	<div class="row">
		<div class="col-6" id="folder-response"></div>
		<div class="col-6" id="folder-image-viewer"></div>
	</div>
</div>