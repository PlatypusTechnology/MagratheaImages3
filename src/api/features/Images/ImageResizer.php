<?php

namespace MagratheaImages3\Images;

use Exception;
use GdImage;
use Magrathea2\Admin\Features\AppConfig\AppConfig;
use Magrathea2\Config;
use Magrathea2\ConfigApp;
use Magrathea2\Exceptions\MagratheaApiException;
use Magrathea2\Exceptions\MagratheaException;
use MagratheaImages3\Helper;
use MagratheaImages3\Images\Images;

use function Magrathea2\p_r;

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

	public bool $placeholder = false;

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
	public function AddDebugArray(array $ds): void {
		array_push($this->debugSteps, ...$ds);
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

	public function SetPlaceholder(): ImageResizer {
		$this->placeholder = true;
		return $this;
	}

	public function CreateBlank(int $width=0, int $height=0) {
		$w = $width == 0 ? $this->width : $width;
		$h = $height == 0 ? $this->height : $height;
		$this->PrintDebug("Creating blank image... of size (".$w."x".$h.")");
		$this->newGdImage = imagecreatetruecolor($w, $h);
		if($this->extension == "png") {
			$this->PrintDebug("adding alpha transparency for png");
			imagealphablending($this->newGdImage, false);
			imagesavealpha($this->newGdImage, true);
			$transparent = imagecolorallocatealpha($this->newGdImage, 255, 255, 255, 127);
			imagefilledrectangle($this->newGdImage, 0, 0, $w, $h, $transparent);
		}
		$this->PrintDebug("OK!");
		return $this->newGdImage;
	}

	public function Generate(): bool {
		if (!$this->Resize()) return false;
		return $this->Save();
	}

	public function AdjustPlaceholder(): void {
		if($this->placeholder) {
			$placeholderProp = ConfigApp::Instance()->GetFloat("placeholder_size", 0.5);
			$this->width = floor($this->width * $placeholderProp);
			$this->height = floor($this->height * $placeholderProp);
		}
	}

	public function SimpleResize(int $w=0, int $h=0): bool {
		if($w == 0) $w = $this->width;
		if($h == 0) $h = $this->height;
		if(empty($this->newGdImage)) $this->CreateBlank($w, $h);
		try {
			$gd = @$this->GetRawGD();
		} catch(\Exception $ex) {
			throw new MagratheaException($ex->getMessage(), 500);
		}
		if(!$gd) {
			$error = error_get_last();
			throw new MagratheaException($error["message"], 500);
		}
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

	public function ComplexResize(
		string $direction,
		int $aspectW = 0,
		int $aspectH = 0,
		int $width = 0,
		int $height = 0
	) {
		$this->PrintDebug("Complex Resize function: ".$direction);
		if(empty($this->newGdImage)) $this->CreateBlank();
		$gd = $this->GetRawGD();

		if(!$aspectW) $aspectW = $this->image->width;
		if(!$aspectH) $aspectH = $this->image->height;
		if(!$width) $width = $this->width;
		if(!$height) $height = $this->height;
		$src_width = $this->image->width;
		$src_height = $this->image->height;
		$x = 0;
		$y = 0;
		$dest_x = 0; $dest_y = 0;
		$this->PrintDebug("consider aspect : ".$aspectW."x".$aspectH);
		if($direction == "horizontal") {
			$middle = $aspectW / 2;
			$this->PrintDebug("middle: ".$middle);
			$x = floor($middle - ($width / 2));
			$dest_x = $this->width;
		} else {
			$middle = $src_width / 2;
			$x = floor($middle - ($width / 2));
			$src_width = $this->width / 2;
		}
		$created = imagecopyresampled(
			$this->newGdImage, $gd,
			$dest_x, $dest_y,
			$x, $y,
			$width, $height,
			$src_width, $src_height
		);
		if(!$created) {
			throw new MagratheaApiException("Could not generate image", 500);
		}

		$debug = "Complex Resizing image";
		$debug .= " from ".$this->image->width."x".$this->image->height;
		$debug .= " to ".$this->width."x".$this->height;
		$debug .= " / destination x[".$dest_x."]|y[".$dest_y."]";
		$debug .= " / origin x[".$x."]|y[".$y."]";
		$debug .= " / dest size w[".$width."]|h[".$height."]";
		$debug .= " / origin size w[".$src_width."]|h[".$src_height."]";
		$this->PrintDebug($debug);
	}

	public function ComplexResizeOld(int $x=0, int $y=0, int $w=0, int $h=0) {
		if(empty($this->newGdImage)) $this->CreateBlank();
		$gd = $this->GetRawGD();
		$points = [
			'x' => $x,
			'y' => $y,
			'width' => $w == 0 ? $this->width : $w,
			'height' => $h == 0 ? $this->height : $h,
		];
		$w = $w == 0 ? $this->width : $w;
		$h = $h == 0 ? $this->height : $h;
		$created = imagecopyresampled(
			$this->newGdImage, $gd,
			0, 0,
			$x, $y,
			$w, $h,
			$this->width, $this->height
		);
		if(!$created) {
			throw new MagratheaApiException("Could not generate image", 500);
		}
		$debug = "Complex Resizing image";
		$debug .= " from ".$this->image->width."x".$this->image->height;
		$debug .= " to ".$this->width."x".$this->height;
		$debug .= " / from point ".$x.",".$y;
		$debug .= " to point 0, 0";
		$this->PrintDebug($debug);
		return true;
	}

	public function SimpleCrop(int $x=0, int $y=0, int $w=0, int $h=0) {
		// if($this->extension == "png") {
		// 	$this->PrintDebug("we don't crop pngs to preserve transparency");
		// 	return true;
		// }
		if(empty($this->newGdImage)) $this->CreateBlank();
		$points = [
			'x' => $x,
			'y' => $y,
			'width' => $w == 0 ? $this->width : $w,
			'height' => $h == 0 ? $this->height : $h,
		];
		try {
			if($this->extension == "png") {
				imagealphablending($this->newGdImage, false);
				imagesavealpha($this->newGdImage, true);	
			}
			$this->newGdImage = imagecrop($this->newGdImage, $points);
		} catch(Exception $ex) {
			throw new MagratheaApiException("Could not crop image: ".$ex->getMessage(), 500, $points);
		}
		$debug = "Cutting image";
		$debug .= " to size ".$points["width"]."x".$points["height"];
		$debug .= " from point ".$points["x"].",".$points["y"];
		$this->PrintDebug($debug);
		return true;
	}

	public function CopyResampled(
		$gd,
		int $dst_x, int $dst_y,
		int $src_x, int $src_y,
		int $dst_width, int $dst_height,
		int $src_width, int $src_height,
	) {
		$debugResampled = " IMAGECOPYRESAMPLED: ";
		$debugResampled .= " [dst_x => ".$dst_x."][dst_y => ".$dst_y."] /";
		$debugResampled .= " [src_x => ".$src_x."][src_y => ".$src_y."] /";
		$debugResampled .= " [dst_width => ".$dst_width."][dst_height => ".$dst_height."] /";
		$debugResampled .= " [src_width => ".$src_width."][src_height => ".$src_height."]";
		$this->PrintDebug($debugResampled);
		$newGD = $this->CreateBlank($dst_width, $dst_height);
		$createResized = imagecopyresampled(
			$newGD, $gd,
			$dst_x, $dst_y,
			$src_x, $src_y,
			$dst_width, $dst_height,
			$src_width, $src_height
		);
		if(!$createResized) {
			throw new MagratheaApiException("Could not generate image", 500);
		}
		return $newGD;
	}

	public function Resize(): bool {
		$this->AdjustPlaceholder();
		$calculator = new ResampleCalculator(
			$this->image->width, $this->image->height,
			$this->width, $this->height
		);
		$gd = $this->GetRawGD();

		$calculator->keepAspectRatio = $this->keepAspectRatio;
		$data = $calculator->Calculate();
		$this->AddDebugArray($calculator->GetDebug());

		$debugCalc = "Resizing Calculator:";
		$debugCalc .= " [resize => ".$data["resize"]."]";
		if($data["resize"]) {
			$debugCalc .= " [resize_w => ".$data["resize_w"]."][resize_y => ".$data["resize_h"]."] /";
		}
		$debugCalc .= " [dst_x => ".$data["dst_x"]."][dst_y => ".$data["dst_y"]."] /";
		$debugCalc .= " [src_x => ".$data["src_x"]."][src_y => ".$data["src_y"]."] /";
		$debugCalc .= " [dst_width => ".$data["dst_width"]."][dst_height => ".$data["dst_height"]."] /";
		$debugCalc .= " [src_width => ".$data["src_width"]."][src_height => ".$data["src_height"]."]";
		$this->PrintDebug($debugCalc);

		if($data["resize"]) {
			$resized = $this->CopyResampled(
				$gd, 0, 0, 0, 0, 
				$data["resize_w"], $data["resize_h"],
				$data["src_width"], $data["src_height"]
			);
			$data["src_width"] = $data["resize_w"];
			$data["src_height"] = $data["resize_h"];
			$data["src_width"] = $data["dst_width"];
			$gd = $resized;
		}

		$this->CopyResampled(
			$gd,
			$data["dst_x"], $data["dst_y"],
			$data["src_x"], $data["src_y"],
			$data["dst_width"], $data["dst_height"],
			$data["src_width"], $data["src_height"]
		);



		// if(empty($this->newGdImage)) $this->CreateBlank();
		// $created = imagecopyresampled(
		// 	$this->newGdImage, $gd, 
		// 	$data["dst_x"], $data["dst_y"],
		// 	$data["src_x"], $data["src_y"],
		// 	$data["dst_width"], $data["dst_height"],
		// 	$data["src_width"], $data["src_height"]
		// );
		// if(!$created) {
		// 	throw new MagratheaApiException("Could not generate image", 500);
		// }
		return true;
	}

	// $debug = "Complex Processing image...";
	// $debug .= " from ".$this->image->width."x".$this->image->height;
	// $debug .= " to ".$this->width."x".$this->height;
	// $this->PrintDebug($debug);
	// $debug = " - Resizing: ".$data["resize"];
	// if($data["resize"]) {
	// 	$debug .= " resizing to ".$data["resize_w"]."x".$data["resize_h"];
	// }
	// $this->PrintDebug($debug);
	// $debug = " - imagecopyresampled...";
	// $debug .= " / destination x[".$data["dst_x"]."]|y[".$data["dst_y"]."]";
	// $debug .= " / origin x[".$data["src_x"]."]|y[".$data["src_y"]."]";
	// $debug .= " / dest size w[".$data["dst_width"]."]|h[".$data["dst_height"]."]";
	// $debug .= " / origin size w[".$data["src_width"]."]|h[".$data["src_height"]."]";
	// $this->PrintDebug($debug);


	// 	// $originalAspect = $this->GetAspectRatio($this->image->width, $this->image->height);
	// 	// $this->PrintDebug("Original Aspect Ratio: ".$originalAspect['ratio']." > ".$originalAspect["format"]);
	// 	// $targetAspect = $this->GetAspectRatio($this->width, $this->height);
	// 	// $this->PrintDebug("Next Aspect Ratio: ".$targetAspect['ratio']." > ".$targetAspect["format"]);

	// 	if(
	// 		!$this->keepAspectRatio ||
	// 		$originalAspect["ratio"] == $targetAspect["ratio"]
	// 	) {
	// 		$this->PrintDebug("Same Aspect Ratio");
	// 		return $this->SimpleResize();
	// 	}
	// 	if($targetAspect["ratio"] > $originalAspect["ratio"]) {
	// 		$this->PrintDebug("Cut horizontal");
	// 		$this->ComplexResize("horizontal");
	// 		// $width = $this->width;
	// 		// $height = ceil($width / $originalAspect["ratio"]);
	// 		// $this->PrintDebug("Cut horizontal");
	// 		// $this->SimpleResize($width, $height);
	// 		// $this->SimpleCrop();
	// 	} else {
	// 		$this->PrintDebug("Cut vertical");
	// 		$aspectH = ceil($this->image->height * $originalAspect["ratio"]);
	// 		$aspectW = ceil($this->image->width * $originalAspect["ratio"]);
	// 		$this->PrintDebug("ratio : ".$originalAspect["ratio"]. " resize width ".$aspectW);
	// 		$this->ComplexResize("vertical", $aspectW, $aspectH);

	// 		// $this->ComplexResize("vertical", 0, $height);
	// 		// $this->SimpleResize($width, $height);
	// 		// $middle = $width / 2;
	// 		// $x = floor($middle - ($this->width / 2));
	// 		// $this->SimpleCrop($x);
	// 		// $this->ComplexResize($x, 0, $width);
	// 	}
	// 	return true;
	// }
	// public function GetAspectRatio_deprecated($w, $h): array {
	// 	$aspectRatio = $w / $h;
	// 	$format = ($aspectRatio == 1 ? "square" : (
	// 		$aspectRatio > 1 ? "landscape" : "portrait"
	// 	));
	// 	return [
	// 		"ratio" => $aspectRatio,
	// 		"format" => $format,
	// 	];
	// }

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
			$webp = boolval(Config::Instance()->Get("webp_quick_access"));
			if($webp) return $this->SaveWebp();
			$gd = $this->newGdImage;
			$fileName = $this->newFile.".".$this->extension;
			switch($this->extension) {
				case "png":
					$quality = $this->placeholder ? 1 : floor($this->quality/10) - 1;
					return imagepng($gd, $fileName, $quality);
				case "jpg":
				case "jpeg":
				default:
					$quality = $this->placeholder ? 10: $this->quality;
					return imagejpeg($gd, $fileName, $quality);
				case "webp":
					$quality = $this->placeholder ? 10: $this->quality;
					return imagewebp($gd, $fileName, $quality);
				case "wbmp":
					if($this->placeholder) return null;
					return imagewbmp($gd, $fileName);
				case "bmp":
					if($this->placeholder) return null;
					return imagebmp($gd, $fileName);
			}
		} catch(Exception $ex) {
			throw new MagratheaApiException("Error generating Image: ".$ex->getMessage(), true, 500, $ex);
		}
	}

	public function SaveWebp(): bool {
		// $this->extension = "webp";
		$this->PrintDebug("saving webp");
		try {
			$gd = $this->newGdImage;
			$fileName = $this->newFile.".".$this->extension;
			$quality = $this->placeholder ? 10: $this->quality;
			switch($this->extension) {
				case "png":
				case "gif":
					imagepalettetotruecolor($gd);
					imagealphablending($gd, true);
					imagesavealpha($gd, true);
					return imagepng($gd, $fileName, $quality);
				case "jpg":
				case "jpeg":
				case "webp":
				case "wbmp":
				case "bmp":
				default:
					return imagewebp($gd, $fileName, $quality);
			}
		} catch(Exception $ex) {
			throw new MagratheaApiException("Error generating Image: ".$ex->getMessage(), true, 500, $ex);
		}
	}

	public function GetRawGD(): GdImage|bool {
		$this->PrintDebug("Getting gd for ".$this->extension." image; raw file: ".$this->rawFile);
		if(!Helper::IsGDWorking()) {
			throw new MagratheaApiException("GD lib is not installed", true, 500);
		}
		$rawF = $this->rawFile;
		switch($this->extension) {
			case "bmp":
				return imagecreatefromwbmp($rawF);
			case "png":
				$rawF = imagecreatefrompng($rawF);
				return $rawF;
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
