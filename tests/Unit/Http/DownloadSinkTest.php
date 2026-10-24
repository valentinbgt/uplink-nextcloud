<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Uplink\Tests\Unit\Http;

use OCA\Uplink\Http\DownloadSink;
use PHPUnit\Framework\TestCase;

class DownloadSinkTest extends TestCase {
	private string $path;

	protected function setUp(): void {
		parent::setUp();
		$this->path = tempnam(sys_get_temp_dir(), 'uplink-test');
	}

	protected function tearDown(): void {
		@unlink($this->path);
		parent::tearDown();
	}

	public function testOnlyTheBodyOfSuccessfulResponsesIsKept(): void {
		$sink = new DownloadSink($this->path);

		$sink->beginResponse(false);
		$this->assertSame(13, $sink->write('<html>Moved</'));
		$sink->beginResponse(true);
		$this->assertSame(5, $sink->write('hello'));
		$this->assertSame(6, $sink->write(' world'));
		$sink->close();

		$this->assertSame('hello world', file_get_contents($this->path));
		$this->assertSame(11, $sink->getBytesWritten());
	}

	public function testANewSuccessfulResponseReplacesThePreviousBody(): void {
		$sink = new DownloadSink($this->path, 10);
		$sink->beginResponse(true);
		$sink->write('first body');
		$sink->beginResponse(true);
		$sink->write('second');
		$sink->close();

		$this->assertSame('second', file_get_contents($this->path));
		$this->assertFalse($sink->isLimitReached());
	}

	public function testWritingBeyondTheLimitAbortsTheTransfer(): void {
		$sink = new DownloadSink($this->path, 5);
		$sink->beginResponse(true);

		$this->assertSame(3, $sink->write('abc'));
		$this->assertSame(2, $sink->write('de'));
		$this->assertFalse($sink->isLimitReached());
		$this->assertSame(0, $sink->write('f'));
		$this->assertTrue($sink->isLimitReached());
		$this->assertFalse($sink->hasWriteFailed());
		$sink->close();

		$this->assertSame('abcde', file_get_contents($this->path));
	}

	public function testNoLimitByDefault(): void {
		$sink = new DownloadSink($this->path);
		$sink->beginResponse(true);
		$data = str_repeat('x', 1024 * 1024);
		for ($i = 0; $i < 4; $i++) {
			$this->assertSame(strlen($data), $sink->write($data));
		}
		$sink->close();

		$this->assertSame(4 * 1024 * 1024, filesize($this->path));
	}

	public function testWritingAfterCloseFails(): void {
		$sink = new DownloadSink($this->path);
		$sink->beginResponse(true);
		$sink->close();

		$this->assertSame(0, $sink->write('late'));
		$this->assertTrue($sink->hasWriteFailed());
		$this->assertFalse($sink->isWritable());
	}

	public function testItIsAWriteOnlyStream(): void {
		$sink = new DownloadSink($this->path);

		$this->assertTrue($sink->isWritable());
		$this->assertFalse($sink->isReadable());
		$this->assertFalse($sink->isSeekable());
		$this->assertTrue($sink->eof());
		$this->assertNull($sink->getSize());
		$this->assertSame('', (string)$sink);
		$this->assertSame([], $sink->getMetadata());
		$this->assertNull($sink->getMetadata('uri'));
		$this->assertIsResource($sink->detach());
		$this->assertNull($sink->detach());

		$this->expectException(\RuntimeException::class);
		$sink->read(1);
	}

	public function testUnwritablePathsAreRejected(): void {
		$this->expectException(\RuntimeException::class);
		new DownloadSink(sys_get_temp_dir() . '/uplink-missing-directory/file');
	}
}
