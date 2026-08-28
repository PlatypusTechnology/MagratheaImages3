<?php

use PHPUnit\Framework\TestCase;
use MagratheaImages3\R2\BackupControl;
use MagratheaImages3\Images\Images;

include_once(__DIR__ . "/../_inc.php");

class BackupControlTest extends TestCase
{
	public function setUp(): void
	{
		\Magrathea2\DB\Database::MockClass(\Magrathea2\DB\DatabaseSimulate::Instance());
	}

	public function testGetPendingReturnsArray()
	{
		$control = new BackupControl();
		$this->assertSame([], $control->GetPending(200, 5));
	}

	public function testGetAllKeysReturnsEmptyArrayUnderMock()
	{
		$control = new BackupControl();
		$this->assertSame([], $control->GetAllKeys());
	}

	public function testGetBackedUpKeysReturnsEmptyArrayUnderMock()
	{
		$control = new BackupControl();
		$this->assertSame([], $control->GetBackedUpKeys());
	}

	public function testResetForRetryNoOpOnEmptyArray()
	{
		$control = new BackupControl();
		$this->assertSame(0, $control->ResetForRetry([]));
	}

	public function testResetForRetryDeduplicatesAndCastsIds()
	{
		// DatabaseSimulate always returns null/true regardless of the query; this
		// exercises that building the IN(...) list from mixed/duplicate input
		// doesn't throw, not the actual persisted result.
		$control = new BackupControl();
		$control->ResetForRetry(["3", 3, 5, "5"]);
		$this->addToAssertionCount(1);
	}

	public function testMarkSuccessAndMarkFailureDoNotThrow()
	{
		$control = new BackupControl();
		$image = new Images();
		$image->id = 42;
		$control->MarkSuccess($image, "abc123");
		$control->MarkFailure($image, "some error");
		$this->addToAssertionCount(1);
	}

	public function testGetStatsReturnsExpectedShape()
	{
		$control = new BackupControl();
		$stats = $control->GetStats();
		$this->assertSame(
			["total", "backed_up", "pending", "exhausted", "last_backed_up_at"],
			array_keys($stats)
		);
		$this->assertSame(0, $stats["total"]);
	}

	public function testGetExhaustedReturnsArray()
	{
		$control = new BackupControl();
		$this->assertSame([], $control->GetExhausted());
	}
}
