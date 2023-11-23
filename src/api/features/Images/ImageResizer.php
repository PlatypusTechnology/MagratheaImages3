<?php

namespace MagratheaImages3\Images;

use Exception;
use GdImage;
use Magrathea2\Exceptions\MagratheaApiException;
use MagratheaImages3\Helper;
use MagratheaImages3\Images\Images;

class ImageResizer {

	public bool $debug = false;
	public array $debugSteps = [];
	public Images $image;
	public string $extension;
	public string $rawFile;
	public string $newFile;
	public int $width;
	public int $height;
	public GdImage $newGdImage;

	public function __construct(Images $img) {
		$this->image = $img;
		$this->extension = $this->image->extension;
		$this->rawFile = $img->GetRawFile();
	}

	public function DebugOn(): ImageResizer {
		$this->debug = true;
		return $this;
	}
	public function PrintDebug(string $d): void {
		if(!$this->debug) return;
		array_push($this->debugSteps, $d);
	}
	public function GetDebug(): array { return $this->debugSteps; }

	public function SetDimensions(int $width, int $height): ImageResizer {
		$this->PrintDebug("Setting dimensions: ".$width."x".$height);
		$this->width = $width;
		$this->height = $height;
		return $this;
	}

	public function SetNewFile(string $name): ImageResizer {
		$this->PrintDebug("Setting new file: ".$name);
		$this->newFile = $name;
		return $this;
	}

	public function CreateBlank() {
		$this->PrintDebug("Creating blank image...");
		$this->newGdImage = imagecreatetruecolor($this->width, $this->height);
		$this->PrintDebug("OK!");
		return $this->newGdImage;
	}

	public function Generate(): bool {
		if (!$this->Resize()) return false;
		return $this->Save();
	}

	public function Resize(): bool {
		$gd = $this->GetGD();
		if(empty($this->newGdImage)) $this->CreateBlank();

		$this->PrintDebug("Resizing image from ".$this->image->width."x".$this->image->height." to ".$this->width."x".$this->height);
		$created = imagecopyresampled(
			$this->newGdImage, $gd,
			0,0,0,0,
			$this->width, $this->height,
			$this->image->width, $this->image->height
		);
		if(!$created) {
			throw new MagratheaApiException("Could not generate image", true, 500);
		}
		return true;
	}

	public function CheckFolder(): bool {
		$folder = PathManager::GetGeneratedFolder($this->image->folder);
		$isFolderOk = PathManager::CheckDestinationFolder($folder);
		if($isFolderOk["success"]) return true;
		else throw new MagratheaApiException($isFolderOk["error"], true, 500);
	}

	public function Save(): bool {
		if(!$this->CheckFolder()) {
			throw new MagratheaApiException("Could not save generated image; destination folder is invalid", true, 500, $this->newFile);
		}
		try {
			switch($this->extension) {
				case "png":
					return imagepng($this->newGdImage, $this->newFile);
				case "jpg":
				case "jpeg":
				default:
					return imagejpeg($this->newGdImage, $this->newFile, 100);
			}
		} catch(Exception $ex) {
			throw new MagratheaApiException("Error generating Image: ".$ex->getMessage(), true, 500, $ex);
		}
	}

	public function GetGD(): GdImage {
		$this->PrintDebug("Getting gd for ".$this->extension." image; raw file: ".$this->rawFile);
		if(!Helper::IsGDWorking()) {
			throw new MagratheaApiException("GD lib is not installed", true, 500);
		}
		$rawF = $this->rawFile;
		switch($this->extension) {
			case "bmp":
				return imagecreatefromwbmp($rawF);
			case "png":
				return imagecreatefrompng($rawF);
			case "webp":
				return imagecreatefromwebp($rawF);
			case "wbmp":
				return imagecreatefromwbmp($rawF);
			case "jpg":
			case "jpeg":
			default:
				return imagecreatefromjpeg($rawF);
		}
	}

}
