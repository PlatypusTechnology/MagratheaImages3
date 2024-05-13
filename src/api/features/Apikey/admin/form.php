<?php
$form = new \Magrathea2\Admin\AdminForm();
$formData = [
	[
		"name" => "#ID:",
		"key" => "id",
		"type" => "disabled",
		"size" => "col-1",
	],
	[
		"name" => "Private Key:",
		"key" => "private_key",
		"type" => "disabled",
		"size" => "col-2",
	],
	[
		"name" => "Public Key:",
		"key" => "public_key",
		"type" => "disabled",
		"size" => "col-2",
	],
	[
		"size" => "col-2",
		"type" => "text",
		"key" => "folder",
		"name" => "Folder:",
	],
	[
		"size" => "col-2",
		"type" => "date",
		"key" => "expiration",
		"name" => "Expiration:",
	],
	[
		"size" => "col-1",
		"type" => "text",
		"key" => "usage_limit",
		"name" => "Limit:",
	],
	[
		"name" => "Save",
		"type" => "button",
		"class" => ["w-100", "btn-success"],
		"size" => "col-2",
		"action" => "createKey(this)",
	]
];
print_r($key->folder);
?>
<div class="card">
	<div class="card-header">
		<?=($key->id ? "Edit Key" : "New Key")?>
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
	</div>
	<div class="card-body">
		<?
		$form->Build($formData, $key)->Print();
		?>
	</div>
</div>
