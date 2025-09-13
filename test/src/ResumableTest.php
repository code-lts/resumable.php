<?php

declare(strict_types = 1);

namespace ResumableJs\Test;

use ResumableJs\Resumable;
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;

class ResumableTest extends TestCase
{
    /**
     * @var Resumable
     */
    protected $resumable;

    /**
     * @var \Psr\Http\Message\ServerRequestInterface
     */
    protected $request;

    /**
     * @var \Psr\Http\Message\ResponseInterface
     */
    protected $response;

    /**
     * @var Psr17Factory
     */
    protected $psr17Factory;

    protected function setUp(): void
    {
        $this->psr17Factory = new Psr17Factory();

        $this->request  = $this->psr17Factory->createServerRequest('GET', 'http://example.com');
        $this->response = $this->psr17Factory->createResponse(200);
        $this->refreshResumable();
    }

    private function refreshResumable(): void
    {
        $this->resumable               = new Resumable($this->request, $this->response);
        $this->resumable->tempFolder   = 'test/tmp';
        $this->resumable->uploadFolder = 'test/uploads';
    }

    public function tearDown(): void
    {
        unset($this->request);
        unset($this->response);
        parent::tearDown();
    }

    public function testProcessHandleChunk(): void
    {
        $resumableParams = [
            'resumableChunkNumber' => 3,
            'resumableTotalChunks' => 600,
            'resumableChunkSize' => 200,
            'resumableIdentifier' => random_int(1, 7894) . '-identifier',
            'resumableFilename' => 'example-file.png',
            'resumableRelativePath' => 'upload',
        ];

        $uploadedFile = tempnam(sys_get_temp_dir(), 'resumable.js_example-file.png');

        $this->request = $this->psr17Factory->createServerRequest(
            'POST',
            'http://example.com'
        )
            ->withParsedBody($resumableParams)
            ->withUploadedFiles(
                [
                new UploadedFile(
                    $uploadedFile,
                    27000, // Size
                    0 // Error status
                ),
                ]
            );

        $tempFolder = $this->resumable->tempFolder . '/' . $resumableParams['resumableIdentifier'] . '/';
        $this->refreshResumable();
        $this->assertNotNull($this->resumable->process());
        $this->assertFileDoesNotExist($uploadedFile);// It got moved
        $this->assertFileExists($tempFolder . 'example-file.png.0003');// It got moved
        $this->assertTrue(unlink($tempFolder . 'example-file.png.0003'));
        $this->assertTrue(rmdir($tempFolder . '/'));// It should be empty
    }

    public function testProcessHandleTestChunk(): void
    {
        $resumableParams = [
            'resumableChunkNumber' => 3,
            'resumableTotalChunks' => 600,
            'resumableChunkSize' => 200,
            'resumableIdentifier' => random_int(1, 7894) . '-identifier',
            'resumableFilename' => 'example-file.png',
            'resumableRelativePath' => 'upload',
        ];

        $this->request = $this->psr17Factory->createServerRequest(
            'GET',
            'http://example.com'
        )->withQueryParams($resumableParams);
        $this->refreshResumable();

        $this->assertNotNull($this->resumable->process());
    }

    public function testHandleTestChunk(): void
    {
        $this->request = $this->psr17Factory->createServerRequest(
            'GET',
            'http://example.com'
        )->withQueryParams(
            [
            'resumableChunkNumber' => 1,
            'resumableTotalChunks' => 600,
            'resumableChunkSize' => 200,
            'resumableIdentifier' => random_int(1, 7894) . '-identifier',
            'resumableFilename' => 'example-file.png',
            'resumableRelativePath' => 'upload',
            ]
        );
        $this->refreshResumable();
        $this->assertNotNull($this->resumable->handleTestChunk());
    }

    public function testHandleChunk(): void
    {
        $uploadedFile = strtolower(tempnam(sys_get_temp_dir(), 'resumable.js-handle-test-chunk')) . '.txt';
        file_put_contents($uploadedFile, 'data3');// Create the uploaded file
        $uploadedFileName = basename($uploadedFile);
        $resumableParams  = [
            'resumableChunkNumber' => 3,
            'resumableTotalChunks' => 3,
            'resumableChunkSize' => 200,
            'resumableTotalSize' => 1200,
            'resumableIdentifier' => random_int(1, 7894) . '-identifier',
            'resumableFilename' => $uploadedFileName,
            'resumableRelativePath' => 'upload',
        ];

        $this->request = $this->psr17Factory->createServerRequest(
            'POST',
            'http://example.com'
        )
            ->withParsedBody($resumableParams)
            ->withUploadedFiles(
                [
                new UploadedFile(
                    $uploadedFile,
                    27000, // Size
                    0 // Error status
                ),
                ]
            );

        $this->refreshResumable();
        $tempFolder = $this->resumable->tempFolder . '/' . $resumableParams['resumableIdentifier'] . '/';

        $this->assertFalse(
            $this->resumable->isChunkUploaded(
                $resumableParams['resumableIdentifier'],
                $resumableParams['resumableFilename'],
                $resumableParams['resumableChunkNumber']
            ),
            'The file should not exist'
        );
        $this->assertFileDoesNotExist($this->resumable->uploadFolder . '/' . $uploadedFileName);
        $this->resumable->handleChunk();
        $this->assertFileDoesNotExist($this->resumable->uploadFolder . '/' . $uploadedFileName);
        $this->assertTrue(
            $this->resumable->isChunkUploaded(
                $resumableParams['resumableIdentifier'],
                $resumableParams['resumableFilename'],
                $resumableParams['resumableChunkNumber']
            ),
            'The file should exist'
        );

        // Make other chunks arrive
        file_put_contents($tempFolder . $uploadedFileName . '.0001', 'data1');
        file_put_contents($tempFolder . $uploadedFileName . '.0002', 'data2');

        $this->assertFileExists($tempFolder . $uploadedFileName . '.0001');// It was deleted
        $this->assertFileExists($tempFolder . $uploadedFileName . '.0002');// It was deleted
        $this->assertFileExists($tempFolder . $uploadedFileName . '.0003');// It was deleted
        // Re try the other chunk
        $this->resumable->handleChunk();
        $this->assertFileDoesNotExist($tempFolder . $uploadedFileName . '.0001');// It was deleted
        $this->assertFileDoesNotExist($tempFolder . $uploadedFileName . '.0002');// It was deleted
        $this->assertFileDoesNotExist($tempFolder . $uploadedFileName . '.0003');// It was deleted

        $this->assertFileExists($this->resumable->uploadFolder . '/' . $uploadedFileName);
        $this->assertFileDoesNotExist($uploadedFile);// It was moved

        $this->assertTrue(unlink($this->resumable->uploadFolder . '/' . $uploadedFileName));
    }

    public function testResumableParamsGetRequest(): void
    {
        $resumableParams = [
            'resumableChunkNumber' => 1,
            'resumableTotalChunks' => 100,
            'resumableChunkSize' => 1000,
            'resumableIdentifier' => 100,
            'resumableFilename' => 'example_file_name',
            'resumableRelativePath' => 'upload',
        ];

        $this->request = $this->psr17Factory->createServerRequest(
            'GET',
            'http://example.com'
        )->withQueryParams($resumableParams);
        $this->refreshResumable();

        $this->assertEquals('GET', $this->request->getMethod());
        $this->assertEquals($resumableParams, $this->request->getQueryParams());
        $this->assertEquals($resumableParams, $this->resumable->resumableParams());
    }

    public static function fileNameProvider(): array
    {
        return [
            ['example-file.png', 'example-file.png'],
            ['../unsafe-one-level.txt', 'unsafe-one-level.txt'],
        ];
    }

    /**
     * @dataProvider fileNameProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fileNameProvider')]
    public function testResumableSanitizeFileName(string $filename, string $filenameSanitized): void
    {
        $resumableParams = [
            'resumableChunkNumber' => 1,
            'resumableTotalChunks' => 1,
            'resumableChunkSize' => 200,
            'resumableIdentifier' => random_int(1, 7894) . '-identifier',
            'resumableFilename' => $filename,
            'resumableRelativePath' => 'upload',
        ];

        $uploadedFile = tempnam(sys_get_temp_dir(), 'resumable.js_sanitize');

        $this->request = $this->psr17Factory->createServerRequest(
            'POST',
            'http://example.com'
        )
            ->withParsedBody($resumableParams)
            ->withUploadedFiles(
                [
                new UploadedFile(
                    $uploadedFile,
                    27000, // Size
                    0 // Error status
                ),
                ]
            );

        $this->refreshResumable();
        $this->resumable->uploadFolder = 'upld';

        $this->assertFileExists($uploadedFile);
        $this->assertNotNull($this->resumable->handleChunk());
        $this->assertFileDoesNotExist($uploadedFile);
        $this->assertTrue($this->resumable->isUploadComplete());
        $this->assertSame($filename, $this->resumable->getOriginalFilename());
        $this->assertSame($filenameSanitized, $this->resumable->getFilename());
        $this->assertFileExists($this->resumable->uploadFolder . '/' . $filenameSanitized);
        $this->assertSame($this->resumable->uploadFolder . '/' . $filenameSanitized, $this->resumable->getFilepath());
        $this->assertTrue(unlink($this->resumable->uploadFolder . '/' . $filenameSanitized));
    }

    public static function isFileUploadCompleteProvider(): array
    {
        return [
            ['example-file.png', 'files', 1, true],// test/files/0001-0003 exist
            ['example-file.png', 'files', 2, true],// test/files/0001-0003 exist
            ['example-file.png', 'files', 3, true],// test/files/0001-0003 exist
            ['example-file.png', 'files', 4, false],// no 0004 chunk
            ['example-file.png', 'files', 5, false],// no 0004-0005 chunks
            ['example-file.png', 'files', 15, false],// no 0004-00015 chunks
        ];
    }

    /**
     * @dataProvider isFileUploadCompleteProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('isFileUploadCompleteProvider')]
    public function testIsFileUploadComplete($filename, $identifier, $numOfChunks, $expected): void
    {
        $this->resumable->tempFolder = 'test';
        $this->assertEquals(
            $expected,
            $this->resumable->isFileUploadComplete($filename, $identifier, $numOfChunks)
        );
    }

    public function testIsChunkUploaded(): void
    {
        $this->resumable->tempFolder = 'test';
        $identifier                  = 'files';
        $filename                    = 'example-file.png';
        $this->assertTrue($this->resumable->isChunkUploaded($identifier, $filename, 1));
        $this->assertFalse($this->resumable->isChunkUploaded($identifier, $filename, 10));
    }

    public function testTmpChunkDir(): void
    {
        $this->resumable->tempFolder = 'test';
        $identifier                  = 'test-identifier';
        $expected                    = $this->resumable->tempFolder . DIRECTORY_SEPARATOR . $identifier;
        $this->assertEquals($expected, $this->resumable->tmpChunkDir($identifier));
    }

    public function testTmpChunkFile(): void
    {
        $filename    = 'example-file.png';
        $chunkNumber = 1;
        $expected    = $filename . '.' . str_pad((string) $chunkNumber, 4, '0', STR_PAD_LEFT);
        $this->assertEquals($expected, $this->resumable->tmpChunkFilename($filename, $chunkNumber));
    }

    public function testCreateFileFromChunks(): void
    {
        $files         = [
            'test/files/example-file.png.0001',
            'test/files/example-file.png.0002',
            'test/files/example-file.png.0003',
        ];
        $totalFileSize = array_sum(
            [
                filesize('test/files/example-file.png.0001'),
                filesize('test/files/example-file.png.0002'),
                filesize('test/files/example-file.png.0003'),
            ]
        );
        $destFile      = 'test/files/5.png';

        $this->assertTrue($this->resumable->createFileFromChunks($files, $destFile), 'The file was not created');
        $this->assertFileExists($destFile);
        $this->assertEquals($totalFileSize, filesize($destFile));
        unlink('test/files/5.png');
    }

}
