<?php

use MagratheaImages3\Images\MetadataStripper;

include_once(__DIR__."/../_inc.php");

class MetadataStripperTest extends \PHPUnit\Framework\TestCase {

	private array $tempFiles = [];

	protected function tearDown(): void {
		foreach ($this->tempFiles as $file) {
			if (file_exists($file)) unlink($file);
		}
		$this->tempFiles = [];
	}

	private function writeTemp(string $suffix, string $content): string {
		$path = tempnam(sys_get_temp_dir(), "mds").$suffix;
		file_put_contents($path, $content);
		$this->tempFiles[] = $path;
		return $path;
	}

	private function pngChunk(string $type, string $data): string {
		return pack("N", strlen($data)).$type.$data.pack("N", crc32($type.$data));
	}

	private function riffChunk(string $fourCc, string $data): string {
		$pad = (strlen($data) % 2 === 1) ? "\0" : "";
		return $fourCc.pack("V", strlen($data)).$data.$pad;
	}

	private function gifSubBlocks(string $str): string {
		$out = "";
		foreach (str_split($str, 250) as $chunk) $out .= chr(strlen($chunk)).$chunk;
		return $out."\x00";
	}

	private function buildExifApp1(int $orientation, bool $withGps): string {
		$tiff = "II".pack("v", 42).pack("V", 8);
		$numEntries = $withGps ? 2 : 1;
		$ifd = pack("v", $numEntries);
		$ifd .= pack("v", 0x0112).pack("v", 3).pack("V", 1).pack("v", $orientation).pack("v", 0);
		if ($withGps) {
			$gpsOffset = 8 + 2 + ($numEntries * 12) + 4;
			$ifd .= pack("v", 0x8825).pack("v", 4).pack("V", 1).pack("V", $gpsOffset);
		}
		$ifd .= pack("V", 0);
		$tiff .= $ifd;
		if ($withGps) {
			$gps = pack("v", 1);
			$gps .= pack("v", 0x0001).pack("v", 2).pack("V", 2)."N\0\0\0";
			$gps .= pack("V", 0);
			$tiff .= $gps;
		}
		$data = "Exif\0\0".$tiff;
		return "\xFF\xE1".pack("n", strlen($data) + 2).$data;
	}

	private function buildJpeg(?string $app1 = null): string {
		$soi = "\xFF\xD8";
		$app0 = "\xFF\xE0".pack("n", 16)."JFIF\0"."\x01\x01\x00\x00\x01\x00\x01\x00\x00";
		$com = "\xFF\xFE".pack("n", 2 + 11)."hello world";
		$sos = "\xFF\xDA"."\x00\x08\x01\x01\x00\x00\x3f\x00";
		$scan = "\x00\x01\x02\x03\xFF\x00\x04";
		$eoi = "\xFF\xD9";
		return $soi.$app0.($app1 ?? "").$com.$sos.$scan.$eoi;
	}

	public function testStripJpegRemovesExifGpsAndComment(): void {
		$app1 = $this->buildExifApp1(1, true);
		$path = $this->writeTemp(".jpg", $this->buildJpeg($app1));

		$this->assertTrue(MetadataStripper::Strip($path, "jpg"));

		$after = file_get_contents($path);
		$this->assertStringNotContainsString("hello world", $after);
		$exif = @exif_read_data($path);
		$this->assertTrue($exif === false || !isset($exif["GPSLatitudeRef"]));
	}

	public function testStripJpegPreservesOrientationTag(): void {
		$app1 = $this->buildExifApp1(6, true);
		$path = $this->writeTemp(".jpg", $this->buildJpeg($app1));

		$this->assertTrue(MetadataStripper::Strip($path, "jpg"));

		$exif = @exif_read_data($path);
		$this->assertNotFalse($exif);
		$this->assertEquals(6, $exif["Orientation"]);
		$this->assertTrue(!isset($exif["GPSLatitudeRef"]));
	}

	public function testStripJpegKeepsScanDataByteIdentical(): void {
		$before = $this->buildJpeg($this->buildExifApp1(1, false));
		$path = $this->writeTemp(".jpg", $before);
		$sosPos = strpos($before, "\xFF\xDA");
		$scanBefore = substr($before, $sosPos);

		$this->assertTrue(MetadataStripper::Strip($path, "jpg"));

		$after = file_get_contents($path);
		$scanAfter = substr($after, strpos($after, "\xFF\xDA"));
		$this->assertEquals($scanBefore, $scanAfter);
	}

	public function testStripJpegWithoutExifLeavesImageUsable(): void {
		$path = $this->writeTemp(".jpg", $this->buildJpeg(null));

		$this->assertTrue(MetadataStripper::Strip($path, "jpg"));

		$after = file_get_contents($path);
		$this->assertEquals("\xFF\xD8", substr($after, 0, 2));
		$this->assertEquals("\xFF\xD9", substr($after, -2));
	}

	public function testStripJpegRejectsMalformedFile(): void {
		$path = $this->writeTemp(".jpg", "not a jpeg at all");
		$this->assertFalse(MetadataStripper::Strip($path, "jpg"));
	}

	public function testStripPngRemovesTextChunksKeepsPixelData(): void {
		$sig = "\x89PNG\r\n\x1a\n";
		$ihdr = $this->pngChunk("IHDR", pack("N", 1).pack("N", 1)."\x08\x02\x00\x00\x00");
		$text = $this->pngChunk("tEXt", "Author\0Someone Private");
		$itxt = $this->pngChunk("iTXt", "XML:com.adobe.xmp\0\x00\x00\x00\x00secretxmpdata");
		$idat = $this->pngChunk("IDAT", "fake-idat-bytes-not-real-zlib");
		$iend = $this->pngChunk("IEND", "");
		$path = $this->writeTemp(".png", $sig.$ihdr.$text.$itxt.$idat.$iend);

		$this->assertTrue(MetadataStripper::Strip($path, "png"));

		$after = file_get_contents($path);
		$this->assertStringNotContainsString("Someone Private", $after);
		$this->assertStringNotContainsString("secretxmpdata", $after);
		$this->assertStringContainsString("fake-idat-bytes-not-real-zlib", $after);
		$this->assertEquals("IEND", substr($after, -8, 4));
	}

	public function testStripPngRejectsMalformedFile(): void {
		$path = $this->writeTemp(".png", "not a png at all");
		$this->assertFalse(MetadataStripper::Strip($path, "png"));
	}

	public function testStripWebpRemovesExifAndXmpKeepsPixelPayload(): void {
		$vp8 = $this->riffChunk("VP8 ", "fake-vp8-pixel-payload");
		$exifChunk = $this->riffChunk("EXIF", "fake-exif-private-data");
		$xmpChunk = $this->riffChunk("XMP ", "fake-xmp-private-data");
		$body = $vp8.$exifChunk.$xmpChunk;
		$webp = "RIFF".pack("V", 4 + strlen($body))."WEBP".$body;
		$path = $this->writeTemp(".webp", $webp);

		$this->assertTrue(MetadataStripper::Strip($path, "webp"));

		$after = file_get_contents($path);
		$this->assertStringNotContainsString("fake-exif-private-data", $after);
		$this->assertStringNotContainsString("fake-xmp-private-data", $after);
		$this->assertStringContainsString("fake-vp8-pixel-payload", $after);
		$declaredSize = unpack("V", substr($after, 4, 4))[1];
		$this->assertEquals(strlen($after) - 8, $declaredSize);
	}

	public function testStripGifRemovesCommentKeepsAnimationFrames(): void {
		$header = "GIF89a";
		$lsd = pack("v", 10).pack("v", 10)."\x00\x00\x00";
		$appExt = "\x21\xFF"."\x0B"."NETSCAPE2.0"."\x03\x01\x00\x00"."\x00";
		$commentExt = "\x21\xFE".$this->gifSubBlocks("this is a private comment, should be removed");
		$gce = "\x21\xF9"."\x04"."\x00\x0a\x00\x00"."\x00";
		$imgDesc1 = "\x2C".pack("v", 0).pack("v", 0).pack("v", 10).pack("v", 10)."\x00";
		$imgData1 = "\x02".$this->gifSubBlocks("frame1pixeldata");
		$imgDesc2 = "\x2C".pack("v", 0).pack("v", 0).pack("v", 10).pack("v", 10)."\x00";
		$imgData2 = "\x02".$this->gifSubBlocks("frame2pixeldata");
		$trailer = "\x3B";
		$gif = $header.$lsd.$appExt.$commentExt.$gce.$imgDesc1.$imgData1.$gce.$imgDesc2.$imgData2.$trailer;
		$path = $this->writeTemp(".gif", $gif);

		$this->assertTrue(MetadataStripper::Strip($path, "gif"));

		$after = file_get_contents($path);
		$this->assertStringNotContainsString("private comment", $after);
		$this->assertStringContainsString("NETSCAPE2.0", $after);
		$this->assertStringContainsString("frame1pixeldata", $after);
		$this->assertStringContainsString("frame2pixeldata", $after);
		$this->assertEquals("\x3B", substr($after, -1));
	}

	public function testStripSvgRemovesCommentsMetadataAndEditorNodesKeepsRest(): void {
		$svg = '<?xml version="1.0"?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.0.dtd" width="10" height="10">
  <!-- created by SecretEditor v1.0 -->
  <metadata><rdf:RDF>author info leaked here</rdf:RDF></metadata>
  <sodipodi:namedview id="base"/>
  <title>Accessible Title</title>
  <rect inkscape:label="leaked-layer-name" width="10" height="10" fill="red"/>
</svg>';
		$path = $this->writeTemp(".svg", $svg);

		$this->assertTrue(MetadataStripper::Strip($path, "svg"));

		$after = file_get_contents($path);
		$this->assertStringNotContainsString("SecretEditor", $after);
		$this->assertStringNotContainsString("author info leaked", $after);
		$this->assertStringNotContainsString("namedview", $after);
		$this->assertStringNotContainsString("leaked-layer-name", $after);
		$this->assertStringContainsString("Accessible Title", $after);
		$this->assertStringContainsString('fill="red"', $after);
	}

	public function testStripSvgRejectsMalformedFile(): void {
		$path = $this->writeTemp(".svg", "<svg><unclosed>");
		$this->assertFalse(MetadataStripper::Strip($path, "svg"));
	}

	public function testStripReturnsTrueForFormatsWithoutMetadataContainer(): void {
		$path = $this->writeTemp(".bmp", "arbitrary bmp bytes");
		$this->assertTrue(MetadataStripper::Strip($path, "bmp"));
		$this->assertEquals("arbitrary bmp bytes", file_get_contents($path));
	}

}
