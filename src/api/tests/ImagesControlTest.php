<?php

use PHPUnit\Framework\TestCase;
use MagratheaImages3\Images\ImagesControl;
use MagratheaImages3\Images\Images;
use MagratheaImages3\Apikey\ApikeyControl;

include_once(__DIR__ . "/../_inc.php");

class ImagesControlTest extends TestCase
{
	public function setUp(): void
	{
		// Mock the database to use DatabaseSimulate
		\Magrathea2\DB\Database::Instance()->Mock();
	}

	public function testGetLastReturnsArray()
	{
		$control = new ImagesControl();
		$result = $control->GetLast('testkey', 0, 1);
		$this->assertIsArray($result);
	}

	public function testRemoveThrowsExceptionOnInvalidKey()
	{
		$this->expectException(\Magrathea2\Exceptions\MagratheaApiException::class);
		$mockApi = $this->getMockBuilder(ApikeyControl::class)
			->disableOriginalConstructor()
			->onlyMethods(['GetByKey'])
			->getMock();
		$mockApi->method('GetByKey')->willReturn((object)['id' => 999]);
		$mockImage = $this->getMockBuilder(Images::class)
			->disableOriginalConstructor()
			->getMock();
		$mockImage->upload_key = 123;
		$control = $this->getMockBuilder(ImagesControl::class)
			->disableOriginalConstructor()
			->onlyMethods(['RemoveImage'])
			->getMock();
		// inject mocks
		$control->method('RemoveImage')->willReturn([]);
		// simulate Remove logic
		$control->Remove('privateKey', 1);
	}

	// Add more tests for RemoveImage and RemoveRawFile as needed
}
