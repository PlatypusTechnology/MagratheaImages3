<div class="card">
	<div class="card-header">
		View Image [<span id="image-name"><?=$name?></span>]
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
		<?php if(@$showDelete) { ?>
			<span class="actions">
				<a onclick="deleteGeneratedImage(this, '<?=$apikey?>', '<?=$name?>')" class="action">delete</a>
			</span>
		<?php } ?>
	</div>
	<div class="card-body img-preview-box">
		<img id="preview-img" src="<?=$imgUrl?>" />
	</div>
</div>