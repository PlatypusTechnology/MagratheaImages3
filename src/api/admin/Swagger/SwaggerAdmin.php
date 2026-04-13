<?php

namespace MagratheaImages3;

use Magrathea2\Admin\AdminFeature;
use Magrathea2\Admin\iAdminFeature;

class SwaggerAdmin extends AdminFeature implements iAdminFeature {
	public string $featureName = "Swagger";
	public string $featureId = "AdminSwagger";

	public function __construct() {
		parent::__construct();
		$this->AddJs(__DIR__."/views/scripts.js");
		$this->AddCSS(__DIR__."/views/styles.css");
	}

	public function Index() {
		include("views/index.php");
	}
}
