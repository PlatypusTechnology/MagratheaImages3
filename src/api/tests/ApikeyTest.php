<?php

use MagratheaImages3\Apikey\Apikey;

include_once(__DIR__."/../_inc.php");

class ApikeyTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		// IncrementUses() calls Update() against the DB.
		\Magrathea2\DB\Database::MockClass(\Magrathea2\DB\DatabaseSimulate::Instance());
		parent::setUp();
	}

	public function testGetPublicAndPrivateKey(): void {
		$k = new Apikey();
		$k->public_key = "pub123";
		$k->private_key = "priv456";
		$this->assertEquals("pub123", $k->GetPublicKey());
		$this->assertEquals("priv456", $k->GetPrivateKey());
	}

	public function testGetKeyReturnsPrivateByDefault(): void {
		$k = new Apikey();
		$k->public_key = "pub123";
		$k->private_key = "priv456";
		$this->assertEquals("priv456", $k->GetKey());
		$this->assertEquals("priv456", $k->GetKey(true));
		$this->assertEquals("pub123", $k->GetKey(false));
	}

	public function testNormalizeFillsInDefaults(): void {
		$k = new Apikey();
		$k->folder = "my folder";
		$rs = $k->Normalize();
		$this->assertSame($k, $rs);
		$this->assertEquals(0, $k->uses);
		$this->assertEquals(0, $k->usage_limit);
		$this->assertNull($k->expiration);
	}

	public function testNormalizeKeepsExistingUsesAndLimit(): void {
		$k = new Apikey();
		$k->uses = 3;
		$k->usage_limit = 10;
		$k->folder = "folder";
		$k->Normalize();
		$this->assertEquals(3, $k->uses);
		$this->assertEquals(10, $k->usage_limit);
	}

	public function testNormalizeCleansFolderName(): void {
		$k = new Apikey();
		$k->folder = "my folder!";
		$k->Normalize();
		$this->assertEquals("my_folder-", $k->folder);
	}

	public function testValidateKeyOkWhenActiveAndUnrestricted(): void {
		$k = new Apikey();
		$k->active = true;
		$k->usage_limit = 0;
		$k->uses = 0;
		$k->expiration = null;
		$rs = $k->ValidateKey();
		$this->assertTrue($rs["ok"]);
		$this->assertNull($rs["data"]);
	}

	public function testValidateKeyFailsWhenInactive(): void {
		$k = new Apikey();
		$k->active = false;
		$k->usage_limit = 0;
		$k->uses = 0;
		$k->expiration = null;
		$rs = $k->ValidateKey();
		$this->assertFalse($rs["ok"]);
		$this->assertEquals("key not active", $rs["data"]);
	}

	public function testValidateKeyFailsWhenUsageLimitReached(): void {
		$k = new Apikey();
		$k->active = true;
		$k->usage_limit = 5;
		$k->uses = 5;
		$k->expiration = null;
		$rs = $k->ValidateKey();
		$this->assertFalse($rs["ok"]);
		$this->assertEquals("usage limit reached", $rs["data"]);
	}

	public function testValidateKeyOkWhenUsageBelowLimit(): void {
		$k = new Apikey();
		$k->active = true;
		$k->usage_limit = 5;
		$k->uses = 4;
		$k->expiration = null;
		$rs = $k->ValidateKey();
		$this->assertTrue($rs["ok"]);
	}

	public function testValidateKeyOkWhenExpirationIsInTheFuture(): void {
		$k = new Apikey();
		$k->active = true;
		$k->usage_limit = 0;
		$k->uses = 0;
		$k->expiration = date("Y-m-d H:i:s", strtotime("+1 day"));
		$rs = $k->ValidateKey();
		$this->assertTrue($rs["ok"]);
	}

	public function testValidateKeyFailsWhenExpirationIsInThePast(): void {
		$k = new Apikey();
		$k->active = true;
		$k->usage_limit = 0;
		$k->uses = 0;
		$k->expiration = date("Y-m-d H:i:s", strtotime("-1 day"));
		$rs = $k->ValidateKey();
		$this->assertFalse($rs["ok"]);
		$this->assertEquals("key expired", $rs["data"]);
	}

	public function testGetDestinationFolderUsesFolderProperty(): void {
		$mediasPath = rtrim(\Magrathea2\Config::Instance()->Get("medias_path"), "/");
		$k = new Apikey();
		$k->folder = "my-folder";
		$this->assertEquals($mediasPath."/my-folder/", $k->GetDestinationFolder());
	}

	public function testIncrementUsesIncrementsFromZeroWhenUnset(): void {
		$k = new Apikey();
		$k->id = 1;
		$rs = $k->IncrementUses();
		$this->assertSame($k, $rs);
		$this->assertEquals(1, $k->uses);
	}

	public function testIncrementUsesIncrementsExistingCount(): void {
		$k = new Apikey();
		$k->id = 1;
		$k->uses = 7;
		$k->IncrementUses();
		$this->assertEquals(8, $k->uses);
	}

}
