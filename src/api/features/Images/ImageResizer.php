<?php

namespace MagratheaImages3\Images;

use Exception;
use GdImage;
use Magrathea2\ConfigApp;
use Magrathea2\Exceptions\MagratheaApiException;
use MagratheaImages3\Helper;
use MagratheaImages3\Images\Images;

class ImageResizer {

	public bool $debug = false;
	public array $debugSteps = [];
	public Images $image;
	public string $extension;
	public bool $keepAspectRatio = true;
	public string $rawFile;
	public string $newFile;
	public int $width;
	public int $height;
	public ?GdImage $newGdImage = null;
	public int $quality = 100;

	public function __construct(Images $img) {
		$this->image = $img;
		$this->extension = $this->image->extension;
		$this->rawFile = $img->GetRawFile();
	}

	public function GetThumbSize(): int {
		return ConfigApp::Instance()->GetInt("thumb_size", 100);
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
		$this->PrintDebug("Original dimensions: ".$this->image->width."x".$this->image->height);
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

	public function CreateBlank(int $width=0, int $height=0) {
		$w = $width == 0 ? $this->width : $width;
		$h = $height == 0 ? $this->height : $height;
		$this->PrintDebug("Creating blank image... of size (".$w."x".$h.")");
		$this->newGdImage = imagecreatetruecolor($w, $h);
		$this->PrintDebug("OK!");
		return $this->newGdImage;
	}

	public function Generate(): bool {
		if (!$this->Resize()) return false;
		return $this->Save();
	}

	public function SimpleResize(int $w=0, int $h=0): bool {
		if($w == 0) $w = $this->width;
		if($h == 0) $h = $this->height;
		if(empty($this->newGdImage)) $this->CreateBlank($w, $h);
		$gd = $this->GetRawGD();
		$created = imagecopyresampled(
			$this->newGdImage, $gd,
			0,0,0,0,
			$w, $h,
			$this->image->width, $this->image->height
		);
		if(!$created) {
			throw new MagratheaApiException("Could not generate image", 500);
		}
		$this->PrintDebug("Simply Resizing image from ".$this->image->width."x".$this->image->height." to ".$w."x".$h);
		return true;
	}

	public function ComplexResize($points) {
		if(empty($this->newGdImage)) $this->CreateBlank();
		$gd = $this->GetRawGD();
		$created = imagecopyresampled(
			$this->newGdImage, $gd,
			$points["dst_x"], $points["dst_y"],
			$points["src_x"], $points["src_y"],
			$this->width, $this->height,
			$this->image->width, $this->image->height
		);
		if(!$created) {
			throw new MagratheaApiException("Could not generate image", 500);
		}
		$debug = "Complex Resizing image";
		$debug .= " from ".$this->image->width."x".$this->image->height;
		$debug .= " to ".$this->width."x".$this->height;
		$debug .= " / from point ".$points["src_x"].",".$points["src_y"];
		$debug .= " to point ".$points["dst_x"].",".$points["dst_y"];
		$this->PrintDebug($debug);
		return true;
	}

	public function SimpleCrop(int $x=0, int $y=0, int $w=0, int $h=0) {
		if(empty($this->newGdImage)) $this->CreateBlank();
		$points = [
			'x' => $x,
			'y' => $y,
			'width' => $w == 0 ? $this->width : $w,
			'height' => $h == 0 ? $this->height : $h,
		];
		try {
			$this->newGdImage = imageCrop($this->newGdImage, $points);
		} catch(Exception $ex) {
			throw new MagratheaApiException("Could not crop image: ".$ex->getMessage(), 500, $points);
		}
		$debug = "Cutting image";
		$debug .= " to size ".$points["width"]."x".$points["height"];
		$debug .= " from point ".$points["x"].",".$points["y"];
		$this->PrintDebug($debug);
		return true;
	}

	public function Resize(): bool {
		$originalAspect = $this->GetAspectRatio($this->image->width, $this->image->height);
		$this->PrintDebug("Original Aspect Ratio: ".$originalAspect['ratio']." > ".$originalAspect["format"]);
		$targetAspect = $this->GetAspectRatio($this->width, $this->height);
		$this->PrintDebug("Next Aspect Ratio: ".$targetAspect['ratio']." > ".$targetAspect["format"]);

		if(
			!$this->keepAspectRatio ||
			$originalAspect["ratio"] == $targetAspect["ratio"]
		) {
			$this->PrintDebug("Same Aspect Ratio");
			return $this->SimpleResize();
		}
		if($targetAspect["ratio"] > $originalAspect["ratio"]) {
			$width = $this->width;
			$height = ceil($width / $originalAspect["ratio"]);
			$this->PrintDebug("Cut horizontal");
			$this->SimpleResize($width, $height);
			$this->SimpleCrop();
//			$this->cropAlign('center', 'top');
		} else {
			$this->PrintDebug("Cut vertical");
			$height = $this->height;
			$width = ceil($height * $originalAspect["ratio"]);
			$this->SimpleResize($width, $height);
			$x = $this->width / 4;
			$this->SimpleCrop($x, 0);
//			$this->cropAlign('center', 'top');
		}

		return true;
	}
	public function GetAspectRatio($w, $h): array {
		$aspectRatio = $w / $h;
		$format = ($aspectRatio == 1 ? "square" : (
			$aspectRatio > 1 ? "landscape" : "portrait"
		));
		return [
			"ratio" => $aspectRatio,
			"format" => $format,
		];
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
			$quality = $this->quality;
			$gd = $this->newGdImage;
			$fileName = $this->newFile;
			switch($this->extension) {
				case "png":
					return imagepng($gd, $fileName, $quality);
				case "jpg":
				case "jpeg":
				default:
					return imagejpeg($gd, $fileName, $quality);
				case "webp":
					return imagewebp($gd, $fileName, $quality);
				case "wbmp":
					return imagewbmp($gd, $fileName);
				case "bmp":
					return imagebmp($gd, $fileName);
			}
		} catch(Exception $ex) {
			throw new MagratheaApiException("Error generating Image: ".$ex->getMessage(), true, 500, $ex);
		}
	}

	public function GetRawGD(): GdImage {
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

	public function GetGD(): GdImage {
		if($this->newGdImage == null) return $this->GetRawGD();
		return $this->newGdImage;
	}

}
