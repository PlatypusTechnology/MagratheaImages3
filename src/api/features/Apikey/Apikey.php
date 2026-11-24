<?php
namespace MagratheaImages3\Apikey;

use Magrathea\MagratheaModel;
use MagratheaImages3\Helper;
use MagratheaImages3\Images\ImagesControl;
use MagratheaImages3\Images\PathManager;

use function Magrathea\now;

class Apikey extends MagratheaModel {

	protected $dbTable = "apikey";
	protected $dbPk = "id";
	protected $dbValues = [
		"id" => "int",
		"private_key" => "string",
		"public_key" => "string",
		"folder" => "string",
		"uses" => "int",
		"usage_limit" => "int",
		"expiration" => "datetime",
		"active" => "boolean",
	];
	// MagratheaPHP 3 defaults to strict types (int/bool fields as native JSON types); pinned off so
	// the API keeps 2.x's string values. Removing it is an API change: see docs/migration.md.
	protected $strictTypes = false;

	// created_at/updated_at are declared by MagratheaModel and owned by the database
	public $id, $private_key, $public_key, $folder, $uses, $usage_limit, $expiration, $active;

	/**
	 * Images uploaded with this key (one query per call)
	 * @return Images[]
	 */
	public function GetImages(): array {
		return ImagesControl::GetWhere(["upload_key" => $this->id]);
	}

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
		$code = null;
		if($this->usage_limit > 0 && $this->uses == $this->usage_limit) { $error = "usage limit reached"; $code = 4033; }
		if($this->expiration != null && $this->expiration < now()) { $error = "key expired"; $code = 4034; }
		if(!$this->active) { $error = "key not active"; $code = 4035; }
		return [
			"ok" => ($error == null),
			"data" => $error,
			"code" => $code,
		];
	}

	public function GetDestinationFolder(): string {
		return PathManager::GetMediaFolder($this->folder);
	}

	public function IncrementUses(): Apikey {
		$this->uses = ($this->uses ?? 0) + 1;
		$this->Update();
		return $this;
	}

}
