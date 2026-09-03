<?php
namespace MagratheaImages3\R2;

use AsyncAws\S3\S3Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin wrapper over the async-aws S3 client, scoped to what the backup module
 * needs: put one file, list every key, delete a batch of keys. Bucket and
 * credentials come from `R2Config`; nothing outside this class talks to
 * async-aws directly.
 */
class R2Client {

	private S3Client $client;
	private string $bucket;

	/**
	 * @param HttpClientInterface|null $httpClient Injected in tests to replace the
	 *   real network transport (e.g. a `Symfony\Component\HttpClient\MockHttpClient`).
	 */
	public function __construct(?HttpClientInterface $httpClient = null) {
		$this->bucket = R2Config::GetBucket() ?? "";
		$this->client = new S3Client(
			[
				"endpoint" => R2Config::GetEndpoint(),
				"region" => "auto",
				"accessKeyId" => R2Config::GetAccessKey(),
				"accessKeySecret" => R2Config::GetSecretKey(),
				// R2's account-level endpoint doesn't resolve virtual-hosted-style
				// (`{bucket}.{account_id}.r2.cloudflarestorage.com`) via DNS; path-style
				// (`{endpoint}/{bucket}/{key}`) is what Cloudflare's own docs use.
				"pathStyleEndpoint" => true,
			],
			null,
			$httpClient
		);
	}

	/**
	 * The R2 object key for a raw image file: identical to the on-disk layout
	 * beneath `medias_path`, with no instance prefix -- the bucket is the instance.
	 */
	public static function ObjectKey(string $folder, string $filename): string {
		return trim($folder, "/")."/raw/".$filename;
	}

	/**
	 * Uploads a local file and verifies the returned ETag against its MD5 --
	 * single-PUT objects have ETag = MD5 hex, so a mismatch means a truncated
	 * or corrupted transfer.
	 *
	 * @throws R2Exception if the local file is missing or the ETag doesn't match.
	 */
	public function PutFile(string $localPath, string $key, string $contentType, string $imageUuid): string {
		if(!file_exists($localPath)) {
			throw new R2Exception("local file does not exist: ".$localPath);
		}
		$localMd5 = md5_file($localPath);
		$stream = fopen($localPath, "rb");
		try {
			$result = $this->client->putObject([
				"Bucket" => $this->bucket,
				"Key" => $key,
				"Body" => $stream,
				"ContentType" => $contentType,
				"Metadata" => ["image-uuid" => $imageUuid],
			]);
			$etag = trim($result->getEtag() ?? "", "\"");
		} finally {
			if(is_resource($stream)) fclose($stream);
		}
		if($etag === "" || !hash_equals($localMd5, $etag)) {
			throw new R2Exception("ETag mismatch for ".$key." (expected ".$localMd5.", got ".($etag ?: "none").")");
		}
		return $etag;
	}

	/**
	 * Every object key currently in the bucket. `getContents()` (no args) pages
	 * through the whole listing on its own.
	 *
	 * @return string[]
	 */
	public function ListAllKeys(): array {
		$keys = [];
		$result = $this->client->listObjectsV2(["Bucket" => $this->bucket]);
		foreach($result->getContents() as $object) {
			$keys[] = $object->getKey();
		}
		return $keys;
	}

	/**
	 * Deletes a batch of keys, chunked to R2's 1000-key-per-request limit.
	 * Deletes are recoverable for 60 days via the bucket's noncurrent-version
	 * expiry lifecycle rule.
	 *
	 * @param string[] $keys
	 */
	public function DeleteKeys(array $keys): void {
		foreach(array_chunk($keys, 1000) as $chunk) {
			$this->client->deleteObjects([
				"Bucket" => $this->bucket,
				"Delete" => [
					"Objects" => array_map(fn($key) => ["Key" => $key], $chunk),
					"Quiet" => true,
				],
			]);
		}
	}

}
