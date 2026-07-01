<?php

use Magrathea2\Admin\AdminElements;

$elements = AdminElements::Instance();
$imageCount = count($images);

?>

<div class="card border-danger">
	<div class="card-header bg-danger text-white">
		Confirm Delete Api Key
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
	</div>
	<div class="card-body">
		<p>This action is <strong>irreversible</strong>. The following will be permanently deleted:</p>
		<ul>
			<li>Api key row #<?=$apikey->id?> (<?=$apikey->public_key?>)</li>
			<li><?=$imageCount?> image record<?=$imageCount == 1 ? "" : "s"?></li>
			<li>Folder: <code><?=$baseFolder?></code>, including its <code>raw/</code> and <code>generated/</code> subfolders, and everything in them</li>
		</ul>
		<div class="row">
			<div class="col-6">
				<?
				$elements->Input("text", "delete-challenge-".$apikey->id, "delete-challenge-".$apikey->id, "", "", "", "Type DELETE to confirm");
				?>
			</div>
			<div class="col-6">
				<?
				$elements->Button("Confirm Delete", "deleteKey(".$apikey->id.");", ["btn-danger", "w-100"]);
				?>
			</div>
		</div>
	</div>
</div>
