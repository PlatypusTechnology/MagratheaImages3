<?php

use Magrathea2\Admin\AdminElements;
use MagratheaImages3\Apikey\ApikeyControl;

$elements = AdminElements::Instance();

$elements->Header("Medias");
$control = new ApikeyControl();
$apiKeys = $control->GetAll();

$folders = array_map(function($k) {
	return [
		"id" => $k->id,
		"name" => "ID#".$k->id." public key:[".$k->public_key."] / folder: ".$k->folder,
	];
}, $apiKeys);

?>

<script src="https://unpkg.com/dropzone@6.0.0-beta.1/dist/dropzone-min.js"></script>
<link href="https://unpkg.com/dropzone@6.0.0-beta.1/dist/dropzone.css" rel="stylesheet" type="text/css" />

<div class="container">
	<div class="row mb-2">
		<div class="col-12">
			<div class="card">
				<div class="card-header">
					Api key:
				</div>
				<div class="card-body">
					<div class="row">
						<div class="col-8">
			<?
			$elements->Select(
				"apikey",
				"ApiKey",
				$folders,
				'', [],
				"Select folder",
				[
					"onchange" => "selectMediaApiKey(this);"
				]
			);
			?>
						</div>
						<div class="col-2 hide-w-api" style="display: none;">
			<?
			$elements->Button(
				"Medias",
				"refreshMedias();",
				["btn-primary", "w-100"]
			);
			?>
						</div>
						<div class="col-2 hide-w-api" style="display: none;">
						<?
			$elements->Button(
				"Upload",
				"loadUploader();",
				["btn-primary", "w-100"]
			);
			?>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row">
		<div class="col-12" id="media-response">

		</div>
	</div>
</div>



