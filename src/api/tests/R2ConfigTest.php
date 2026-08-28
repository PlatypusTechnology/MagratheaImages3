<?php

use PHPUnit\Framework\TestCase;
use MagratheaImages3\R2\R2Config;

include_once(__DIR__ . "/../_inc.php");

class R2ConfigTest extends TestCase
{
	private string $scratchDir;

	public function setUp(): void
	{
		$this->scratchDir = sys_get_temp_dir()."/r2conf-test-".uniqid();
		mkdir($this->scratchDir, 0755, true);
		R2Config::SetConfigRootForTests($this->scratchDir);
	}

	public function tearDown(): void
	{
		R2Config::SetConfigRootForTests(null);
		array_map("unlink", glob($this->scratchDir."/*"));
		rmdir($this->scratchDir);
	}

	private function writeConf(string $contents): void
	{
		file_put_contents($this->scratchDir."/r2.conf", $contents);
	}

	// The test suite runs under the "scripttest" environment (`general/use_environment`
	// in `magrathea.conf`), not "dev" -- Config::Instance()->GetEnvironment() resolves
	// to whatever that says, so the scratch r2.conf must use the same section name.
	private const ENV = "scripttest";

	public function testNoFileMeansDisabled()
	{
		$this->assertFalse(R2Config::IsEnabled());
		$this->assertSame([], R2Config::Get());
	}

	public function testEnabledFalseMeansDisabled()
	{
		$this->writeConf("[".self::ENV."]\n\tenabled = false\n\taccount_id = \"acc\"\n\tbucket = \"buck\"\n\taccess_key = \"ak\"\n\tsecret_key = \"sk\"\n");
		$this->assertFalse(R2Config::IsEnabled());
	}

	public function testHalfFilledConfigIsDisabled()
	{
		$this->writeConf("[".self::ENV."]\n\tenabled = true\n\taccount_id = \"\"\n\tbucket = \"\"\n\taccess_key = \"\"\n\tsecret_key = \"\"\n");
		$this->assertFalse(R2Config::IsEnabled());
	}

	public function testFullyConfiguredIsEnabled()
	{
		$this->writeConf("[".self::ENV."]\n\tenabled = true\n\taccount_id = \"acc\"\n\tbucket = \"buck\"\n\taccess_key = \"ak\"\n\tsecret_key = \"sk\"\n");
		$this->assertTrue(R2Config::IsEnabled());
		$this->assertSame("acc", R2Config::GetAccountId());
		$this->assertSame("buck", R2Config::GetBucket());
		$this->assertSame("https://acc.r2.cloudflarestorage.com", R2Config::GetEndpoint());
	}

	public function testDefaultsForTunables()
	{
		$this->writeConf("[".self::ENV."]\n\tenabled = true\n\taccount_id = \"acc\"\n\tbucket = \"buck\"\n\taccess_key = \"ak\"\n\tsecret_key = \"sk\"\n");
		$this->assertSame(200, R2Config::GetBatchSize());
		$this->assertSame(5, R2Config::GetMaxAttempts());
		$this->assertSame(100, R2Config::GetDeleteCap());
	}

	public function testTunablesCanBeOverridden()
	{
		$this->writeConf("[".self::ENV."]\n\tenabled = true\n\taccount_id = \"acc\"\n\tbucket = \"buck\"\n\taccess_key = \"ak\"\n\tsecret_key = \"sk\"\n\tbatch_size = 50\n\tmax_attempts = 3\n\tdelete_cap = 10\n");
		$this->assertSame(50, R2Config::GetBatchSize());
		$this->assertSame(3, R2Config::GetMaxAttempts());
		$this->assertSame(10, R2Config::GetDeleteCap());
	}
}
