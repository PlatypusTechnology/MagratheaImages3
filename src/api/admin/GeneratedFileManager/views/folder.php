<?php

use Magrathea2\Admin\AdminElements;
$elements = AdminElements::Instance();

if(!file_exists($folder)) {
	$elements->Alert("folder [".$folder."] does not exists", "danger", true);
	die;
}

$generated = $folder."/generated";
$raw = $folder."/raw";

?>

<div class="card">
	<div class="card-header">
		Images from folder [<?=$folder?>]
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
	</div>
	<div class="card-body">
		<div class="row">
			<div class="col-6">
				<?
				if(!file_exists($generated)) { 
					$elements->Alert("generate folder<br/>does not exists", "danger");
				} else {
					$elements->Button(
						"generated",
						"openFolder('generated')",
						["w-100", "btn-primary"]
					);
				}
				?>
			</div>
			<div class="col-6">
				<?
				if(!file_exists($raw)) { 
					$elements->Alert("raw folder<br/>does not exists", "danger");
				} else {
					$elements->Button(
						"raw",
						"openFolder('raw')",
						["w-100", "btn-primary"]
					);
				}
				?>
			</div>
			<div class="col-12 mt-2" id="files-response"></div>
		</div>
	</div>
</div>
