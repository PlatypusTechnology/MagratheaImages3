<?php

namespace MagratheaImages3\Images;

use Magrathea2\Exceptions\MagratheaConfigException;
use Magrathea2\Helper;
use MagratheaImages3\Apikey\Apikey;

class FileManager {
	public ?string $path;
	private array $extensions;
	public function SetPath(string $path): FileManager {
		if(!file_exists($path)) throw new MagratheaConfigException("Folder does not exist: ", $path);
		$this->path = Helper::EnsureTrailingSlash($path);
		return $this;
	}

	public function SetApiKeyId(int $apiKeyId): FileManager {
		$apikey = new Apikey($apiKeyId);
		return $this->SetPath($apikey->GetDestinationFolder());		
	}

	public function AllowedExtensions(array $ext): FileManager {
		$this->extensions = $ext;
		return $this;
	}

	public function GetFiles(): array {
		if(empty($this->extensions)) {
			$files = scandir($this->path);
		} else {
			$pattern = $this->path."*.{".implode(',',$this->extensions)."}";
			$files = glob($pattern, GLOB_BRACE);
		}
		return $files;
	}

	public function DeleteFile(string $file): bool {
		$dFile = $this->path.$file;
		if(!file_exists($dFile)) {
			throw new \Magrathea2\Exceptions\MagratheaException("filee does not exists: [".$dFile."]");
		}
		try {
			return unlink($dFile);
		} catch(\Exception $ex) {
			throw $ex;
		}
	}
}
