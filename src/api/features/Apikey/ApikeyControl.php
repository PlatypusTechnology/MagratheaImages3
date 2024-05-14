<?php
namespace MagratheaImages3\Apikey;

use Exception;
use Magrathea2\DB\Database;
use Magrathea2\DB\Query;
use Magrathea2\Errors\ErrorManager;
use Magrathea2\Exceptions\MagratheaException;
use Magrathea2\Exceptions\MagratheaModelException;
use MagratheaImages3\Images\PathManager;

class ApikeyControl extends \MagratheaImages3\Apikey\Base\ApikeyControlBase {

	public function initializeKeys(Apikey &$key) {
		if(empty($key->private_key)) {
			$key->private_key = $this->createKey(true);
		}
		if(empty($key->public)) {
			$key->public_key = $this->createKey(false);
		}
	}

	public function createKey(bool $private, int $tries=0): string {
		$length = $private ? 25 : 12;
		$key = $this->createRandomStr($length);
		if(!$this->assertKeyNotInUse($private, $key)) {
			$tries = $tries + 1;
			if($tries > 5) throw new MagratheaModelException("incorrect key creation (after ".$tries." tries)");
			return $this->createKey($private, $tries);
		}
		return $key;
	}

	public function createRandomStr($length): string {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyz';
		$randomKey = "";
		for ($i = 0; $i < $length; $i++) {
				$randomKey .= $characters[random_int(0, strlen($characters) - 1)];
		}
		return $randomKey;
	}

	public function assertKeyNotInUse($key, bool $private = true): bool {
		$field = $private ? "private_key" : "public_key";
		$q = Query::Select("COUNT(1) as ok")
			->Table("apikey")
			->Where([$field => $key]);
		$rs = Database::Instance()->QueryOne($q);
		return ($rs == 0);
	}

	public function GetByKey(string $key, bool $private=true): Apikey|null {
		$field = $private ? "private_key" : "public_key";
		$q = Query::Select()
			->Obj(new Apikey())
			->Where([$field => $key]);
		return $this->RunRow($q);
	}

	public function GetCached($id): string {
		$cache = @include(__DIR__."/cache/ApikeyCache.php");
		if(!$cache) {
			$message = "Apikey cache not generated";
			ErrorManager::Instance()->DisplayMesage($message);
			throw new MagratheaException($message);
		}
		return GetApiKeyCached($id);
	}

	public function Create($data): array {
		$k = new Apikey();
		/** @var Apikey $k */
		$k = $k->Assign($data);
		$this->initializeKeys($k);
		$k->active = true;
		try {
			$k->Normalize();
			if(empty($k->folder)) {
				throw new MagratheaException("folder cannot be empty!");
			}
			$k->Insert();
			$cacheGen = new CacheClassCreator();
			$cacheGen->Generate();
			$paths = $this->CreateFolders($k->folder);
		} catch(Exception $ex) {
			throw $ex;
		}
		return [
			"apikey" => $k,
			"paths" => $paths,
		];
	}

	public function Update($id, $data): Apikey {
		$k = new Apikey();
		$k = $k->Assign($data);
		try {
			$k->Update();
		} catch(Exception $ex) {
			throw $ex;
		}
		return $k;
	}

	public function CreateFolders($folder) {
		$dir = PathManager::GetMediaFolder($folder);
		$rawDir = PathManager::GetRawFolder($folder);
		$genDir = PathManager::GetGeneratedFolder($folder);
		return [
			"folder" => $folder,
			"create_base" => $this->CreateDir($dir),
			"create_raw" => $this->CreateDir($rawDir),
			"create_gen" => $this->CreateDir($genDir),
		];
	}
	public function CreateDir($dir) {
		$rs = PathManager::CheckDestinationFolder($dir);
		if($rs["success"]) return ["success" => true];
		return $rs;
	}

}
