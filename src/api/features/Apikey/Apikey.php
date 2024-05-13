<?php
namespace MagratheaImages3\Apikey;

use MagratheaImages3\Helper;
use MagratheaImages3\Images\PathManager;

use function Magrathea2\now;

class Apikey extends \MagratheaImages3\Apikey\Base\ApikeyBase {

	public function GetKey(bool $private = true): string {
		return $private ? $this->GetPrivateKey() : $this->GetPublicKey();
	}
	public function GetPublicKey(): string { return $this->public_key; }
	public function GetPrivateKey(): string { return $this->private_key; }

	public function Normalize(): Apikey {
		if(!$this->uses) $this->uses = 0;
		if(!$this->usage_limit) $this->usage_limit = 0;
		if(empty($this->expiration)) $this->expiration = null;
		$this->folder = Helper::Clean($this->folder);
		return $this;
	}

	public function ValidateKey(): array {
		$error = null;
		if($this->usage_limit > 0 && $this->uses == $this->usage_limit) $error = "usage limit reached";
		if($this->expiration != null && $this->expiration > now()) $error = "key expired";
		if(!$this->active) $error = "key not active";
		return [
			"ok" => ($error == null),
			"data" => $error
		];
	}

	public function GetDestinationFolder(): string {
		return PathManager::GetMediaFolder($this->folder);
	}

}
