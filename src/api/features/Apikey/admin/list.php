<?php

use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminManager;
use MagratheaContacts\Apikey\Apikey;

$elements = AdminElements::Instance();

?>

<div class="card">
	<div class="card-header">
		Private & Public Keys
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
	</div>
	<div class="card-body">
		Private Key is used for upload and for getting private informations, such as images from a key.<br/>
		Public Key is used for open calls, such as viewing an image.
	</div>
</div>

<div class="card">
	<div class="card-header">
		Api Keys
	</div>
	<div class="card-body">
	<?
	$elements->Table($list, [
		[
			"title" => "#ID",
			"key" => "id"
		],
		[
			"title" => "Private Key",
			"key" => "private_key"
		],
		[
			"title" => "Public Key",
			"key" => "public_key"
		],
		[
			"title" => "Folder",
			"key" => "folder"
		],
		[
			"title" => "Uses",
			"key" => "uses"
		],
		[
			"title" => "Limit",
			"key" => "usage_limit"
		],
	]);
	?>
	</div>
</div>
