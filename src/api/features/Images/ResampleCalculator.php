<?php

namespace MagratheaImages3\Images;

use Magrathea2\Exceptions\MagratheaException;

class ResampleCalculator {

	public bool $keepAspectRatio = true;
	public int $original_image_width;
	public int $original_image_height;
	public int $final_image_width;
	public int $final_image_height;
	public array $src_aspect;
	public array $dst_aspect;

	public bool $shouldResize = false;
	public float $resizeRatio;
	public int $resize_width;
	public int $resize_height;

	public int $src_width;
	public int $src_height;
	public int $dst_width;
	public int $dst_height;
	public int $src_x;
	public int $src_y;
	public int $dst_x;
	public int $dst_y;

	public function __construct(
		int $src_width, int $src_height,
		int $dest_width, int $dest_height
	) {
		$this->original_image_width = $src_width;
		$this->original_image_height = $src_height;
		$this->final_image_width = $dest_width;
		$this->final_image_height = $dest_height;
		$this->Initialize();
	}

	public function Initialize() {
		$this->src_aspect = $this->BuildAspectRatio($this->original_image_width, $this->original_image_height);
		$this->AddDebug("original image ".$this->src_aspect["format"]." aspect: ".$this->src_aspect["ratio"]);
		$this->dst_aspect = $this->BuildAspectRatio($this->final_image_width, $this->final_image_height);
		$this->AddDebug("new image ".$this->dst_aspect["format"]." aspect: ".$this->dst_aspect["ratio"]);
		$this->src_width = $this->original_image_width;
		$this->src_height = $this->original_image_height;
		$this->dst_width = $this->final_image_width;
		$this->dst_height = $this->final_image_height;
	}

	public function BuildAspectRatio($w, $h): array {
		$aspectRatio = $w / $h;
		$format = ($aspectRatio == 1 ? "square" : (
			$aspectRatio > 1 ? "landscape" : "portrait"
		));
		return [
			"ratio" => $aspectRatio,
			"format" => $format,
		];
	}

	public function ZeroPoints() {
		$this->dst_x = $this->dst_y = $this->src_x = $this->src_y = 0;
	}

	public function Calculate(): array {
		if( !$this->keepAspectRatio || $this->dst_aspect["ratio"] == $this->src_aspect["ratio"] ) {
			return $this->SameAspectRatio();
		}
		if( $this->dst_aspect["ratio"] > $this->src_aspect["ratio"] ) {
			return $this->CutHorizontal();
		}
		if( $this->dst_aspect["ratio"] < $this->src_aspect["ratio"] ) {
			return $this->CutVertical();
		}
		throw new MagratheaException("invalid aspect ratios", 500);
	}

	private function DebugResize(): void {
		$this->AddDebug("resizing image to ".$this->resize_width."x".$this->resize_height);
	}

	public function SameAspectRatio(): array {
		$this->AddDebug("Same Aspect Ratio");
		$this->ZeroPoints();
		return $this->returnData();
	}
	public function CutHorizontal(): array {
		$this->AddDebug("Cut horizontal");
		$this->ZeroPoints();
		$this->resizeRatio = $this->final_image_width / $this->original_image_width;
		$this->AddDebug("resize ratio ".$this->resizeRatio);

		$this->dst_width = $this->final_image_width;
		$this->dst_height = $this->final_image_height;
		$this->src_height = $this->final_image_height / $this->resizeRatio;
		return $this->returnData();
	}
	public function CutVertical(): array {
		$this->AddDebug("Cut vertical");
		$this->ZeroPoints();
		$width = $this->original_image_width;
		$this->resizeRatio = $this->final_image_height / $this->original_image_height;
		$this->AddDebug("resize ratio ".$this->resizeRatio);
		if($this->resizeRatio != $this->src_aspect["ratio"]) {
			$this->resize_height = $this->final_image_height;
			$this->resize_width = $this->original_image_width * $this->resizeRatio;
			if(
				$this->resize_width != $this->original_image_width &&
				$this->resize_height != $this->original_image_height
			) {
				$this->shouldResize = true;
				$this->DebugResize();
				$width = $this->resize_width;
			} else {
				$this->src_width = $this->final_image_width;
			}
		}

		// reposition initial point:
		$middle = $width / 2;
		$this->src_x = $middle-($this->final_image_width/2);
		$this->dst_width = $this->final_image_width;
		$this->dst_height = $this->final_image_height;
		return $this->returnData();
	}

	public function returnData(): array {
		$rs = [
			"dst_x" => $this->dst_x,
			"dst_y" => $this->dst_y,
			"src_x" => $this->src_x,
			"src_y" => $this->src_y,
			"dst_width" => $this->dst_width,
			"dst_height" => $this->dst_height,
			"src_width" => $this->src_width,
			"src_height" => $this->src_height,
		];
		if($this->shouldResize) {
			$rs["resize"] = true;
			$rs["resize_ration"] = $this->resizeRatio;
			$rs["resize_w"] = $this->resize_width;
			$rs["resize_h"] = $this->resize_height;
		} else $rs["resize"] = false;
		return $rs;
	}

	private array $debug = [];
	private function AddDebug(string $d): void {
		array_push($this->debug, $d);
	}
	public function GetDebug(): array { return $this->debug; }

}
