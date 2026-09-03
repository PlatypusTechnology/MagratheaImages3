<?php

namespace MagratheaImages3\Images;

use Magrathea2\Exceptions\MagratheaConfigException;
use Magrathea2\MagratheaHelper;
use MagratheaImages3\Apikey\Apikey;

class FileManager {
	public ?string $path;
	private array $extensions;
	public function SetPath(string $path): FileManager {
		if(!file_exists($path)) throw new MagratheaConfigException("Folder does not exist: ", $path);
		$this->path = MagratheaHelper::EnsureTrailingSlash($path);
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
			throw new \Magrathea2\Exceptions\MagratheaException("file does not exists: [".$dFile."]");
		}
		$this->assertWithinBasePath($dFile);
		try {
			return unlink($dFile);
		} catch(\Exception $ex) {
			throw $ex;
		}
	}

	public function DeleteGeneratedPattern($pattern): array {
		// {id-or-uuid}_ prefix (matches Images::BuildGenFileName()), followed by any
		// combination of the addon shapes it can produce ("thumb", "200x300", "200x300-s",
		// "..._placeholder", etc.) and/or glob wildcards -- but nothing that could reach
		// a shell or a path separator.
		if (!preg_match('/^(\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})_[A-Za-z0-9_.*-]+$/', $pattern)) {
			throw new \Magrathea2\Exceptions\MagratheaException("invalid pattern: [".$pattern."]");
		}

		$generatedDir = realpath($this->path."generated");
		$deleted = [];
		if ($generatedDir !== false) {
			foreach (glob($this->path."generated/".$pattern) ?: [] as $match) {
				$real = realpath($match);
				if ($real === false || !is_file($real) || strpos($real, $generatedDir.DIRECTORY_SEPARATOR) !== 0) {
					continue;
				}
				$deleted[$match] = unlink($real);
			}
		}

		return [
			"pattern" => $pattern,
			"deleted" => $deleted,
		];
	}

	/**
	 * Guards against path traversal / symlink escapes: rejects any resolved path
	 * that falls outside this manager's base path.
	 */
	private function assertWithinBasePath(string $resolvedPath): void {
		$base = realpath($this->path);
		$real = realpath($resolvedPath);
		if ($base === false || $real === false || strpos($real, $base.DIRECTORY_SEPARATOR) !== 0) {
			throw new \Magrathea2\Exceptions\MagratheaException("path outside allowed folder: [".$resolvedPath."]");
		}
	}


}
