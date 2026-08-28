<?php

use Magrathea2\Admin\AdminElements;

$elements = AdminElements::Instance();
$elements->Header("R2 Backup");

?>

<div class="container">

<? if(!$enabled): ?>

	<div class="row">
		<div class="col-12">
			<? $elements->Alert("R2 backup is not configured on this instance.", "secondary", false); ?>
		</div>
	</div>

<? else: ?>

	<div class="row mb-2">
		<div class="col-12">
			<div class="card">
				<div class="card-header">
					Status
				</div>
				<div class="card-body">
					<div class="row">
						<div class="col-md-4"><strong>Bucket:</strong> <?=$bucket?></div>
						<div class="col-md-5"><strong>Endpoint:</strong> <?=$endpoint?></div>
						<div class="col-md-3"><strong>Last backed up:</strong> <?=$stats["last_backed_up_at"] ?: "never"?></div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row mb-2">
		<div class="col-md-3">
			<div class="card text-center">
				<div class="card-body">
					<div class="h3"><?=$stats["total"]?></div>
					<div>Total</div>
				</div>
			</div>
		</div>
		<div class="col-md-3">
			<div class="card text-center">
				<div class="card-body">
					<div class="h3"><?=$stats["backed_up"]?></div>
					<div>Backed up</div>
				</div>
			</div>
		</div>
		<div class="col-md-3">
			<div class="card text-center">
				<div class="card-body">
					<div class="h3"><?=$stats["pending"]?></div>
					<div>Pending</div>
				</div>
			</div>
		</div>
		<div class="col-md-3">
			<div class="card text-center">
				<div class="card-body">
					<div class="h3"><?=$stats["exhausted"]?></div>
					<div>Exhausted</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row">
		<div class="col-12">
			<div class="card">
				<div class="card-header">
					Exhausted rows (attempts &gt;= max, will not be retried automatically)
				</div>
				<div class="card-body">
				<? if(empty($exhausted)): ?>
					None.
				<? else: ?>
					<? $elements->Table($exhausted, [
						["title" => "#ID", "key" => "id"],
						["title" => "Folder", "key" => "folder"],
						["title" => "Filename", "key" => "filename"],
						["title" => "Attempts", "key" => "backup_attempts"],
						["title" => "Last error", "key" => "backup_error"],
					]); ?>
				<? endif; ?>
				</div>
			</div>
		</div>
	</div>

<? endif; ?>

</div>
