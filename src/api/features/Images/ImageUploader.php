<?php

namespace MagratheaImages3\Images;

use MagratheaImages3\Apikey\Apikey;
use Magrathea2\Exceptions\MagratheaException;
use Magrathea2\Helper;
use Magrathea2\Logger;

class ImageUploader {

	public $file = [];
	public Apikey|null $key = null;

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

	public function ValidateExtension($ext) {
		$extensions = ["jpg", "jpeg", "png", "bmp", "webp", "wbmp", "bmp"];
		return in_array($ext, $extensions);
	}

	public function Upload(): array {
		$path = $this->GetDestination();
		$destinationOk = PathManager::CheckDestinationFolder($path);

		if(!$destinationOk["success"]) {
			return [
				"success" => false,
				"error" => $destinationOk["error"],
				"data" => $destinationOk["path"],
			];
		}
		try {
			$image = new Images();
			$image->folder = $this->key->GetDestinationFolder();
			$image->upload_key = $this->key->GetKey();
			$image->FromUploadFile($this->file);

			if(!$this->ValidateExtension($image->extension)) {
				return [
					"success" => false,
					"error" => "invalid image extension: [".$image->extension."]",
					"data" => $image,
				];
			}
			
			$finalName = Helper::EnsureTrailingSlash($destinationOk["path"]).$image->filename;
			move_uploaded_file($_FILES["file"]["tmp_name"], $finalName);
			if(file_exists($finalName)){
				list($width, $height) = getimagesize($finalName);
				$image->width = $width;
				$image->height = $height;
				$image->Insert();
			} else {
				return [
					"success" => false,
					"error" => "image was not uploaded",
					"data" => $image,
				];
			}
			return [
				"success" => true,
				"image" => $image,
			];
		} catch(\Exception $e) {
			return [
				"success" => false,
				"error"=> $e->getMessage()
			];
		}
	}

	public function GetDestination(): string {
		return PathManager::GetRawFolder($this->key->GetDestinationFolder());
	}

	public function ValidateUpload(): bool {
		if(empty($this->key)) return false;
		if(empty($this->file)) return false;
		return true;
	}

}
