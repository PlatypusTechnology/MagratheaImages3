<?php
namespace MagratheaImages3\Images;

class ImagesAdmin extends \Magrathea\Admin\Features\CrudObject\AdminCrudObject {
	public string $featureName = "Images CRUD";

	public function Initialize() {
		$this->SetObject(new Images());
	}
}
