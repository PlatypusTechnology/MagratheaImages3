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
	public $extensions = ["jpg", "jpeg", "png", "bmp", "webp", "wbmp"];

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

	public function CreateImage(): Images {
		$image = new Images();
		$image->folder = $this->key->folder;
		$image->upload_key = $this->key->GetID();
		return $image;
	}

	public function returnSuccess($image): array {
		return [
			"success" => true,
			"image" => $image,
		];
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
		$url = strtok($url, '?');
		try {
			$path = $this->GetDestination();
			$this->ValidateDestination($path);
			$image = $this->CreateImage()->FromUrl($url);
			$this->ValidateExtension($image->extension);

			$finalName = MagratheaHelper::EnsureTrailingSlash($path).$image->filename;
//			return " ... uploading ".$url." to ".$finalName;
			file_put_contents($finalName, $this->GetExternalContent($url));
			if(file_exists($finalName)){
				list($width, $height, $mime) = getimagesize($finalName);
				$image->width = $width;
				$image->height = $height;
				$image->file_type = $mime;
				$image->size = filesize($finalName);
				$image->Insert();
			} else {
				return $this->returnImageNotUploaded($image);
			}
			return $image;
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
