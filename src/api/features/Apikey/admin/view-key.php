<?php

use Magrathea2\Admin\AdminElements;

$elements = AdminElements::Instance();

?>

<div class="card">
	<div class="card-header">
		Api Key [<?=$apikey->folder?>]
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
	</div>
	<div class="card-body">
		<div class="row mb-2">
			<div class="col-4"><strong>Limit:</strong> <?=$apikey->usage_limit ?: "unlimited"?></div>
			<div class="col-4"><strong>Uses:</strong> <?=$apikey->uses?></div>
			<div class="col-4"><strong>Expiration:</strong> <?=$apikey->expiration ?: "never"?></div>
		</div>
		<div class="row mb-2">
			<div class="col-12">
				<?
				$elements->Button("Delete Api Key", "confirmDeleteKey(".$apikey->id.");", ["btn-danger"]);
				?>
			</div>
		</div>
		<div class="row" id="key-delete-confirm-<?=$apikey->id?>"></div>
		<div class="row mt-2" id="key-media-<?=$apikey->id?>"></div>
	</div>
</div>
