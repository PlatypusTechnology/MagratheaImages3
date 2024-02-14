<div class="card">
	<div class="card-header">
		Upload to
		<?= $api ?>
	</div>
	<div class="card-body">
		<div class="row">
			<div class="col-8">
				<form action="<?= $uploadApi ?>" class="dropzone" id="dropzone-upload"></form>
			</div>
			<div class="col-4">
				<pre id="upload-response" class="code-light"></pre>
			</div>
		</div>
	</div>
</div>

