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

        $this->request = $this->psr17Factory->createServerRequest('GET', 'http://example.com');

        $this->response = $this->psr17Factory->createResponse(200);
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
            'resumableIdentifier' => 'identifier',
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

        $this->resumable             = new Resumable($this->request, $this->response);
        $this->resumable->tempFolder = 'test/tmp';

        $this->assertNotNull($this->resumable->process());
        unlink($uploadedFile);
    }

    public function testProcessHandleTestChunk(): void
    {
        $resumableParams = [
            'resumableChunkNumber' => 3,
            'resumableTotalChunks' => 600,
            'resumableChunkSize' => 200,
            'resumableIdentifier' => 'identifier',
            'resumableFilename' => 'example-file.png',
            'resumableRelativePath' => 'upload',
        ];

        $this->request = $this->psr17Factory->createServerRequest(
            'GET',
            'http://example.com'
        )->withQueryParams($resumableParams);

        $this->resumable             = new Resumable($this->request, $this->response);
        $this->resumable->tempFolder = 'test/tmp';

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
            'resumableIdentifier' => 'identifier',
            'resumableFilename' => 'example-file.png',
            'resumableRelativePath' => 'upload',
            ]
        );

        $this->resumable             = new Resumable($this->request, $this->response);
        $this->resumable->tempFolder = 'test/tmp';
        $this->assertNotNull($this->resumable->handleTestChunk());
    }

    public function testHandleChunk(): void
    {
        $uploadedFile = strtolower(tempnam(sys_get_temp_dir(), 'resumable.js-handle-test-chunk')) . '.png';
        touch($uploadedFile);// Create the uploaded file
        $uploadedFileName = basename($uploadedFile);
        $resumableParams  = [
            'resumableChunkNumber' => 3,
            'resumableTotalChunks' => 600,
            'resumableChunkSize' => 200,
            'resumableIdentifier' => 'identifier',
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

        $this->resumable                  = new Resumable($this->request, $this->response);
        $this->resumable->tempFolder      = 'test/tmp';
        $this->resumable->uploadFolder    = 'test/uploads';
        $this->assertFalse(
            $this->resumable->isChunkUploaded(
                $resumableParams['resumableIdentifier'],
                $resumableParams['resumableFilename'],
                $resumableParams['resumableChunkNumber']
            ),
            'The file should not exist'
        );
        $this->resumable->handleChunk();
        $this->assertTrue(
            $this->resumable->isChunkUploaded(
                $resumableParams['resumableIdentifier'],
                $resumableParams['resumableFilename'],
                $resumableParams['resumableChunkNumber']
            ),
            'The file should exist'
        );
        $this->assertFileDoesNotExist($uploadedFile);// It was moved
        $this->assertTrue(unlink($this->resumable->tempFolder . '/identifier/' . $uploadedFileName . '.0003'));
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

        $this->resumable = new Resumable($this->request, $this->response);
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
    public function testResumableSanitizeFileName(string $filename, string $filenameSanitized): void
    {
        $resumableParams = [
            'resumableChunkNumber' => 1,
            'resumableTotalChunks' => 1,
            'resumableChunkSize' => 200,
            'resumableIdentifier' => 'identifier',
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

        $this->resumable               = new Resumable($this->request, $this->response);
        $this->resumable->uploadFolder = 'upld';

        $this->assertNotNull($this->resumable->handleChunk());
        unlink($uploadedFile);
        $this->assertTrue($this->resumable->isUploadComplete());
        $this->assertSame($filename, $this->resumable->getOriginalFilename());
        $this->assertSame($filenameSanitized, $this->resumable->getFilename());
        $this->assertSame('upld/' . $filenameSanitized, $this->resumable->getFilepath());
    }

    public static function isFileUploadCompleteProvider(): array
    {
        return [
            ['example-file.png', 'files', 20, 60, true],
            ['example-file.png','files', 25, 60, true],
            ['example-file.png','files', 10, 60, false],
        ];
    }

    /**
     *
     * @dataProvider isFileUploadCompleteProvider
     */
    public function testIsFileUploadComplete($filename, $identifier, $chunkSize, $totalSize, $expected): void
    {
        $this->resumable             = new Resumable($this->request, $this->response);
        $this->resumable->tempFolder = 'test';
        $this->assertEquals(
            $expected,
            $this->resumable->isFileUploadComplete($filename, $identifier, $chunkSize, $totalSize)
        );
    }

    public function testIsChunkUploaded(): void
    {
        $this->resumable             = new Resumable($this->request, $this->response);
        $this->resumable->tempFolder = 'test';
        $identifier                  = 'files';
        $filename                    = 'example-file.png';
        $this->assertTrue($this->resumable->isChunkUploaded($identifier, $filename, 1));
        $this->assertFalse($this->resumable->isChunkUploaded($identifier, $filename, 10));
    }

    public function testTmpChunkDir(): void
    {
        $this->resumable             = new Resumable($this->request, $this->response);
        $this->resumable->tempFolder = 'test';
        $identifier                  = 'test-identifier';
        $expected                    = $this->resumable->tempFolder . DIRECTORY_SEPARATOR . $identifier;
        $this->assertEquals($expected, $this->resumable->tmpChunkDir($identifier));
    }

    public function testTmpChunkFile(): void
    {
        $this->resumable = new Resumable($this->request, $this->response);
        $filename        = 'example-file.png';
        $chunkNumber     = str_pad('1', 4, '0', STR_PAD_LEFT);
        $expected        = $filename . '.' . $chunkNumber;
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

        $this->resumable = new Resumable($this->request, $this->response);
        $this->assertTrue($this->resumable->createFileFromChunks($files, $destFile), 'The file was not created');
        $this->assertFileExists($destFile);
        $this->assertEquals($totalFileSize, filesize($destFile));
        unlink('test/files/5.png');
    }

}
