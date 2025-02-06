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
		$this->PrintDebug("saving image");
		try {
			$gd = $this->newGdImage;
			$fileName = $this->newFile.".webp";
			$quality = $this->placeholder ? 10: $this->quality;
			return imagewebp($gd, $fileName, $quality);
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
