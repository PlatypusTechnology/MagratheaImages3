<?php

use PHPUnit\Framework\TestCase;
use MagratheaImages3\R2\R2Client;
use MagratheaImages3\R2\R2Config;
use MagratheaImages3\R2\R2Exception;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

include_once(__DIR__ . "/../_inc.php");

class R2ClientTest extends TestCase
{
	private string $scratchDir;
	private string $tmpFile;

	public function setUp(): void
	{
		$this->scratchDir = sys_get_temp_dir()."/r2client-test-".uniqid();
		mkdir($this->scratchDir, 0755, true);
		R2Config::SetConfigRootForTests($this->scratchDir);
		file_put_contents(
			$this->scratchDir."/r2.conf",
			"[scripttest]\n\tenabled = true\n\taccount_id = \"acc\"\n\tbucket = \"test-bucket\"\n\taccess_key = \"ak\"\n\tsecret_key = \"sk\"\n"
		);
		$this->tmpFile = $this->scratchDir."/local.jpg";
		file_put_contents($this->tmpFile, "hello world");
	}

	public function tearDown(): void
	{
		R2Config::SetConfigRootForTests(null);
		array_map("unlink", glob($this->scratchDir."/*"));
		rmdir($this->scratchDir);
	}

	public function testObjectKeyLayout()
	{
		$this->assertSame("folder1/raw/1_file.jpg", R2Client::ObjectKey("folder1", "1_file.jpg"));
		$this->assertSame("folder1/raw/1_file.jpg", R2Client::ObjectKey("/folder1/", "1_file.jpg"));
	}

	public function testPutFileThrowsWhenLocalFileMissing()
	{
		$client = new R2Client(new MockHttpClient());
		$this->expectException(R2Exception::class);
		$client->PutFile($this->scratchDir."/does-not-exist.jpg", "k", "image/jpeg", "uuid-1");
	}

	public function testPutFileReturnsEtagOnMatch()
	{
		$md5 = md5_file($this->tmpFile);
		$captured = null;
		$http = new MockHttpClient(function($method, $url, $options) use (&$captured, $md5) {
			$captured = ["method" => $method, "url" => $url, "options" => $options];
			return new MockResponse("", ["response_headers" => ["etag" => "\"".$md5."\""]]);
		});
		$client = new R2Client($http);
		$etag = $client->PutFile($this->tmpFile, "folder1/raw/1_file.jpg", "image/jpeg", "uuid-1");

		$this->assertSame($md5, $etag);
		$this->assertSame("PUT", $captured["method"]);
		$this->assertStringContainsString("test-bucket/folder1/raw/1_file.jpg", $captured["url"]);
		$this->assertStringContainsString("acc.r2.cloudflarestorage.com", $captured["url"]);
		$sentHeaders = strtolower(implode("\n", $captured["options"]["headers"]));
		$this->assertStringContainsString("content-type: image/jpeg", $sentHeaders);
		$this->assertStringContainsString("x-amz-meta-image-uuid: uuid-1", $sentHeaders);
	}

	public function testPutFileThrowsOnEtagMismatch()
	{
		$http = new MockHttpClient(function($method, $url, $options) {
			return new MockResponse("", ["response_headers" => ["etag" => "\"deadbeef\""]]);
		});
		$client = new R2Client($http);
		$this->expectException(R2Exception::class);
		$client->PutFile($this->tmpFile, "folder1/raw/1_file.jpg", "image/jpeg", "uuid-1");
	}

	public function testListAllKeysParsesContents()
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?>
<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
	<Name>test-bucket</Name>
	<KeyCount>2</KeyCount>
	<MaxKeys>1000</MaxKeys>
	<IsTruncated>false</IsTruncated>
	<Contents><Key>folder1/raw/1_file.jpg</Key><LastModified>2024-01-01T00:00:00.000Z</LastModified><ETag>"abc"</ETag><Size>100</Size><StorageClass>STANDARD</StorageClass></Contents>
	<Contents><Key>folder1/raw/2_file.jpg</Key><LastModified>2024-01-01T00:00:00.000Z</LastModified><ETag>"def"</ETag><Size>200</Size><StorageClass>STANDARD</StorageClass></Contents>
</ListBucketResult>';
		$http = new MockHttpClient(new MockResponse($xml, ["response_headers" => ["content-type" => "application/xml"]]));
		$client = new R2Client($http);
		$keys = $client->ListAllKeys();
		$this->assertSame(["folder1/raw/1_file.jpg", "folder1/raw/2_file.jpg"], $keys);
	}

	public function testDeleteKeysChunksAtOneThousand()
	{
		$calls = 0;
		$xml = '<?xml version="1.0" encoding="UTF-8"?><DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"></DeleteResult>';
		$http = new MockHttpClient(function($method, $url, $options) use (&$calls, $xml) {
			$calls++;
			return new MockResponse($xml, ["response_headers" => ["content-type" => "application/xml"]]);
		});
		$client = new R2Client($http);
		$keys = array_map(fn($i) => "folder1/raw/$i.jpg", range(1, 1500));
		$client->DeleteKeys($keys);
		$this->assertSame(2, $calls);
	}

	public function testDeleteKeysNoOpOnEmptyArray()
	{
		$calls = 0;
		$http = new MockHttpClient(function($method, $url, $options) use (&$calls) {
			$calls++;
			return new MockResponse("");
		});
		$client = new R2Client($http);
		$client->DeleteKeys([]);
		$this->assertSame(0, $calls);
	}
}
