<?php

use MagratheaImages3\Images\ResampleCalculator;

include_once(__DIR__."/../_inc.php");

class resizeTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void { parent::setUp(); }
	protected function tearDown(): void { parent::tearDown(); }

	public function testFinalSize_Equals_OriginSize(): void {
		$w = rand(300, 2000);
		$h = rand(300, 2000);
		$calculator = new ResampleCalculator(
			$w, $h, $w, $h
		);
		$rs = $calculator->Calculate();
		$this->assertFalse($rs["resize"]);
		$this->assertEquals(0, $rs["src_x"]);
		$this->assertEquals(0, $rs["src_y"]);
		$this->assertEquals(0, $rs["dst_x"]);
		$this->assertEquals(0, $rs["dst_y"]);
		$this->assertEquals($w, $rs["dst_width"]);
		$this->assertEquals($h, $rs["dst_height"]);
		$this->assertEquals($w, $rs["src_width"]);
		$this->assertEquals($h, $rs["src_height"]);
	}

	public function testFinalSize_Top_OriginalSize_noResize(): void {
		$calculator = new ResampleCalculator(
			1000, 500, 1000, 200
		);
		$rs = $calculator->Calculate();
		$this->assertFalse($rs["resize"]);
		$this->assertEquals(0, $rs["src_x"]);
		$this->assertEquals(0, $rs["src_y"]);
		$this->assertEquals(0, $rs["dst_x"]);
		$this->assertEquals(0, $rs["dst_y"]);
		$this->assertEquals(1000, $rs["dst_width"]);
		$this->assertEquals(200, $rs["dst_height"]);
		$this->assertEquals(1000, $rs["src_width"]);
		$this->assertEquals(200, $rs["src_height"]);
	}

	public function testFinalSize_Top_OriginalSize_resizeBigger(): void {
		$calculator = new ResampleCalculator(
			1000, 500, 2000, 200
		);
		$rs = $calculator->Calculate();
		$this->assertFalse($rs["resize"]);
		$this->assertEquals(0, $rs["src_x"]);
		$this->assertEquals(0, $rs["src_y"]);
		$this->assertEquals(0, $rs["dst_x"]);
		$this->assertEquals(0, $rs["dst_y"]);
		$this->assertEquals(2000, $rs["dst_width"]);
		$this->assertEquals(200, $rs["dst_height"]);
		$this->assertEquals(1000, $rs["src_width"]);
		$this->assertEquals(100, $rs["src_height"]);
	}

	public function testFinalSize_Top_OriginalSize_resizeSmaller(): void {
		$calculator = new ResampleCalculator(
			1000, 500, 500, 125
		);
		$rs = $calculator->Calculate();
		$this->assertFalse($rs["resize"]);
		$this->assertEquals(0, $rs["src_x"]);
		$this->assertEquals(0, $rs["src_y"]);
		$this->assertEquals(0, $rs["dst_x"]);
		$this->assertEquals(0, $rs["dst_y"]);
		$this->assertEquals(500, $rs["dst_width"]);
		$this->assertEquals(125, $rs["dst_height"]);
		$this->assertEquals(1000, $rs["src_width"]);
		$this->assertEquals(250, $rs["src_height"]);
	}

	public function testFinalSize_Middle_OriginalSize_noResize(): void {
		$calculator = new ResampleCalculator(
			1000, 500, 500, 500
		);
		$rs = $calculator->Calculate();
		$this->assertFalse($rs["resize"]);
		$this->assertEquals(250, $rs["src_x"]);
		$this->assertEquals(0, $rs["src_y"]);
		$this->assertEquals(0, $rs["dst_x"]);
		$this->assertEquals(0, $rs["dst_y"]);
		$this->assertEquals(500, $rs["dst_width"]);
		$this->assertEquals(500, $rs["dst_height"]);
		$this->assertEquals(500, $rs["src_width"]);
		$this->assertEquals(500, $rs["src_height"]);
	}

	public function testFinalSize_Middle_OriginalSize_resizeBigger_simpleResize(): void {
		$calculator = new ResampleCalculator(
			1000, 500, 700, 700
		);
		$rs = $calculator->Calculate();
		$this->assertTrue($rs["resize"]);
		$this->assertEquals(1400, $rs["resize_w"]);
		$this->assertEquals(700, $rs["resize_h"]);
		$this->assertEquals(350, $rs["src_x"]);
		$this->assertEquals(0, $rs["src_y"]);
		$this->assertEquals(0, $rs["dst_x"]);
		$this->assertEquals(0, $rs["dst_y"]);
		$this->assertEquals(700, $rs["dst_width"]);
		$this->assertEquals(700, $rs["dst_height"]);
		$this->assertEquals(1000, $rs["src_width"]);
		$this->assertEquals(500, $rs["src_height"]);
	}

	public function testFinalSize_Middle_OriginalSize_noResize_complexCalculation(): void {
		$calculator = new ResampleCalculator(
			1000, 500, 750, 500
		);
		$rs = $calculator->Calculate();
		$this->assertFalse($rs["resize"]);
		$this->assertEquals(125, $rs["src_x"]);
		$this->assertEquals(0, $rs["src_y"]);
		$this->assertEquals(0, $rs["dst_x"]);
		$this->assertEquals(0, $rs["dst_y"]);
		$this->assertEquals(750, $rs["dst_width"]);
		$this->assertEquals(500, $rs["dst_height"]);
		$this->assertEquals(750, $rs["src_width"]);
		$this->assertEquals(500, $rs["src_height"]);
	}

}
