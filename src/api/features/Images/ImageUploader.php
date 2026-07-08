<?php

namespace MagratheaImages3\Images;

use Magrathea2\Exceptions\MagratheaApiException;
use MagratheaImages3\Apikey\Apikey;
use Magrathea2\Exceptions\MagratheaException;
use Magrathea2\MagratheaHelper;
use Magrathea2\Logger;

class ImageUploader {

	public $file = [];
	public Apikey|null $key = null;
	public ?string $subFolder = null;
	public $extensions = ["jpg", "jpeg", "png", "bmp", "webp", "wbmp", "svg"];
	private static $allowedMimes = [
		'image/jpeg'   => 'jpg',
		'image/png'    => 'png',
		'image/webp'   => 'webp',
		'image/bmp'    => 'bmp',
		'image/x-bmp'  => 'bmp',
		'image/gif'    => 'gif',
		'image/svg+xml' => 'svg',
	];

	public function SetKey(Apikey $key): ImageUploader {
		$this->key = $key;
		return $this;
	}
	public function SetFile(array $file): ImageUploader {
		if(empty($file)) {
			throw new MagratheaException("Empty file");
		}
		$this->file = $file;
		return $this;
	}
	public function SetSubfolder(string $folder): ImageUploader {
		$this->subFolder = $folder;
		return $this;
	}

	public function CreateImage(): Images {
		$image = new Images();
		$image->folder = $this->key->folder;
		$image->upload_key = $this->key->GetID();
		if(@$this->subFolder) $image->subfolder = $this->subFolder;
		return $image;
	}

	public function getImageReturn($image): array {
		$rsData = [
			"image" => $image,
		];
		if($this->key) {
			$rsData["public_key"] = $this->key->GetPublicKey();
		}
		return $rsData;
	}

	public function returnSuccess($image): array {
		$result = [
			"success" => true,
			"data" => $this->getImageReturn($image),
		];
		return $result;
	}
	public function returnImageNotUploaded($image): array {
		return [
			"success" => false,
			"error" => "image was not uploaded",
			"data" => $image,
		];
}

	public function Upload(): array {
		try {
			$path = $this->GetDestination();
			$this->ValidateDestination($path);
			$image = $this->CreateImage()->FromUploadFile($this->file);
			$this->ValidateExtension($image->extension);

			$finalName = MagratheaHelper::EnsureTrailingSlash($path).$image->filename;
			move_uploaded_file($_FILES["file"]["tmp_name"], $finalName);
			if(file_exists($finalName)){
				list($width, $height) = getimagesize($finalName);
				$image->width = $width;
				$image->height = $height;
				$image->Insert();
				$this->key->IncrementUses();
			} else {
				return $this->returnImageNotUploaded($image);
			}
			return $this->returnSuccess($image);
		} catch(MagratheaApiException $ex) {
			return [
				"success" => false,
				"error" => $ex->getMessage(),
				"data" => $ex->GetData(),
			];
		} catch(\Exception $e) {
			return [
				"success" => false,
				"error"=> $e->getMessage()
			];
		}
	}

	public function GetExternalContent($url) {
		$context = stream_context_create([
			"http" => [ "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.2661.102 Safari/537.36" ]
		]);
		return file_get_contents($url, false, $context);
	}

	public function UploadUrl($url) {
		$cleanUrl = strtok($url, '?');
		try {
			$path = $this->GetDestination();
			$this->ValidateDestination($path);

			$content = $this->GetExternalContent($url);
			$ext = $this->ValidateMime($content);

			$image = $this->CreateImage()->FromUrl($cleanUrl, $ext);

			$finalName = MagratheaHelper::EnsureTrailingSlash($path).$image->filename;
			file_put_contents($finalName, $content);
			if(file_exists($finalName)){
				list($width, $height, $mime) = getimagesize($finalName);
				$image->width = $width;
				$image->height = $height;
				$image->file_type = $mime;
				$image->size = filesize($finalName);
				$image->Insert();
				$this->key->IncrementUses();
			} else {
				return $this->returnImageNotUploaded($image);
			}
			return $this->getImageReturn($image);
		} catch(MagratheaApiException $ex) {
			throw $ex;
		} catch(\Exception $e) {
			throw $e;
		}
	}

	public function GetDestination(): string {
		return PathManager::GetRawFolder($this->key->folder);
	}

	public function ValidateDestination(string $path): bool {
		$destinationOk = PathManager::CheckDestinationFolder($path);
		if(!$destinationOk["success"]) {
			throw new MagratheaApiException($destinationOk["error"], 500, $destinationOk["path"]);
		}
		return true;
	}

	public function ValidateExtension($ext): bool {
		if(!in_array($ext, $this->extensions)) {
			throw new MagratheaApiException("invalid image extension: [".$ext."]", 415, $ext);
		}
		return true;
	}

	public function ValidateMime(string $content): string {
		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$mime = $finfo->buffer($content);
		if(!isset(self::$allowedMimes[$mime])) {
			throw new MagratheaApiException("invalid image type: [".$mime."]", 415, $mime);
		}
		return self::$allowedMimes[$mime];
	}

	public function ValidateUpload(): bool {
		if(empty($this->key)) return false;
		if(empty($this->file)) return false;
		return true;
	}

	public static function getMaximumFileUploadSize() {  
		return min(self::convertPHPSizeToBytes(ini_get('post_max_size')), self::convertPHPSizeToBytes(ini_get('upload_max_filesize')));
	} 
	public static function convertPHPSizeToBytes($sSize): int {
		$sSuffix = strtoupper(substr($sSize, -1));
		if (!in_array($sSuffix,array('P','T','G','M','K'))){
			return (int)$sSize;  
		} 
		$iValue = substr($sSize, 0, -1);
		switch ($sSuffix) {
			case 'P': $iValue *= 1024;
			case 'T': $iValue *= 1024;
			case 'G': $iValue *= 1024;
			case 'M': $iValue *= 1024;
			case 'K': $iValue *= 1024;
			break;
		}
		return (int)$iValue;
	}  
}
