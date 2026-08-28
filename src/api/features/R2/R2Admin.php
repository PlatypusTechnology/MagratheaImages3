<?php

namespace MagratheaImages3\R2;

use Magrathea2\Admin\AdminFeature;
use Magrathea2\Admin\iAdminFeature;

/**
 * Read-only admin status view for the R2 backup module. Renders "not
 * configured" whenever R2Config::IsEnabled() is false, rather than
 * disappearing -- this is the only place core names an R2 class, so the
 * module is optional to configure, not optional to delete. Never renders
 * the secret key, and there is no "backup now" button.
 */
class R2Admin extends AdminFeature implements iAdminFeature {

	public string $featureName = "R2 Backup";
	public string $featureId = "AdminR2Backup";

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
	}

	public function Index() {
		$enabled = R2Config::IsEnabled();
		$stats = null;
		$exhausted = [];
		if($enabled) {
			$control = new BackupControl();
			$stats = $control->GetStats();
			$exhausted = $control->GetExhausted();
		}
		$bucket = R2Config::GetBucket();
		$endpoint = R2Config::GetEndpoint();
		include("admin/index.php");
	}

}
