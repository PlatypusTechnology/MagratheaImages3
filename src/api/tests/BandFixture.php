<?php

use MagratheaImages3\Images\ImageResizer;
use MagratheaImages3\Images\Images;

/**
 * GD fixtures for resize tests: images split into vertical color bands (red left quarter, green
 * middle half, blue right quarter). A resize that crops loses the outer bands and one that keeps
 * the whole source keeps them, so the color of an edge pixel tells the two apart.
 *
 * Not a test file (PHPUnit only collects `*Test.php`): test classes `require_once` it, use the
 * trait and call `removeBandFiles()` in their `tearDown()`.
 */
trait BandFixture {

	private array $bandFiles = [];

	private function requireGD(): void {
		if (!function_exists("imagecreatetruecolor")) {
			$this->markTestSkipped("GD extension is not available in this environment");
		}
	}

	/** Writes a w×h band image ("png" or "jpg") to a temporary file and returns its path. */
	private function bandFile(int $w, int $h, string $ext): string {
		$gd = imagecreatetruecolor($w, $h);
		$quarter = intdiv($w, 4);
		imagefilledrectangle($gd, 0, 0, $quarter - 1, $h - 1, imagecolorallocate($gd, 255, 0, 0));
		imagefilledrectangle($gd, $quarter, 0, $w - $quarter - 1, $h - 1, imagecolorallocate($gd, 0, 255, 0));
		imagefilledrectangle($gd, $w - $quarter, 0, $w - 1, $h - 1, imagecolorallocate($gd, 0, 0, 255));
		$file = tempnam(sys_get_temp_dir(), "band");
		$this->bandFiles[] = $file;
		if ($ext == "png") imagepng($gd, $file);
		else imagejpeg($gd, $file, 100);
		return $file;
	}

	/** An image record as the database would hold it: its width/height are what resizing reads. */
	private function bandImage(int $w, int $h, string $ext): Images {
		$img = new Images();
		$img->id = 1;
		$img->name = "band";
		$img->extension = $ext;
		$img->folder = "myfolder";
		$img->width = $w;
		$img->height = $h;
		return $img;
	}

	/** A resizer whose raw file is a w×h band fixture (`$rawFile` is public: medias_path isn't touched). */
	private function bandResizer(int $w, int $h, string $ext = "png"): ImageResizer {
		$resizer = new ImageResizer($this->bandImage($w, $h, $ext));
		$resizer->rawFile = $this->bandFile($w, $h, $ext);
		return $resizer;
	}

	/** "red", "green" or "blue" when the pixel is clearly that band; otherwise its values, for the failure message. */
	private function colorAt(\GdImage $gd, int $x, int $y): string {
		$rgb = imagecolorat($gd, $x, $y);
		$r = ($rgb >> 16) & 0xFF;
		$g = ($rgb >> 8) & 0xFF;
		$b = $rgb & 0xFF;
		if ($r > 200 && $g < 60 && $b < 60) return "red";
		if ($g > 200 && $r < 60 && $b < 60) return "green";
		if ($b > 200 && $r < 60 && $g < 60) return "blue";
		return "rgb($r,$g,$b)";
	}

	private function sizeOf(\GdImage $gd): array {
		return [imagesx($gd), imagesy($gd)];
	}

	private function removeBandFiles(): void {
		foreach ($this->bandFiles as $file) @unlink($file);
		$this->bandFiles = [];
	}

}
