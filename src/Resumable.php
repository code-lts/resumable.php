<?php

declare(strict_types = 1);

namespace ResumableJs;

use Gaufrette\Filesystem;
use Gaufrette\Adapter\Local as LocalFilesystemAdapter;
use Gaufrette\StreamMode;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use OndrejVrto\FilenameSanitize\FilenameSanitize;

class Resumable
{
    /**
     * Debug mode is enabled
     */
    protected bool $debug = false;

    public string $tempFolder = 'tmp';

    public string $uploadFolder = 'uploads';

    protected ServerRequestInterface $request;
    protected ResponseInterface $response;

    protected $params;

    protected string|null $chunkFile;

    protected LoggerInterface|null $logger;

    protected Filesystem|null $fileSystem;

    /**
     * Override the filename that will be given by setting this to a value
     * Before handing is done
     */
    protected string|null $filename = null;

    protected string $filepath = '';

    protected string $originalFilename = '';

    protected bool $isUploadComplete = false;

    protected $resumableOption = [
        'identifier' => 'identifier',
        'filename' => 'filename',
        'chunkNumber' => 'chunkNumber',
        'chunkSize' => 'chunkSize',
        'totalSize' => 'totalSize',
        'totalChunks' => 'totalChunks',
    ];

    public function __construct(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?LoggerInterface $logger = null,
        ?Filesystem $fileSystem = null
    ) {
        $this->request  = $request;
        $this->response = $response;
        if ($fileSystem === null) {
            $cwd = getcwd();
            $cwd === false ? __DIR__ : $cwd;
            $this->fileSystem = self::getLocalFileSystem($cwd);
        } else {
            $this->fileSystem = $fileSystem;
        }

        $this->logger = $logger;
    }

    public static function getLocalFileSystem(string $baseDir): Filesystem
    {
        $adapter = new LocalFilesystemAdapter(
            $baseDir
        );

        return new Filesystem($adapter);
    }

    public function setResumableOption(array $resumableOption): void
    {
        $this->resumableOption = array_merge($this->resumableOption, $resumableOption);
    }

    public function process(): ResponseInterface|null
    {
        if (! empty($this->resumableParams())) {
            if (! empty($this->request->getUploadedFiles())) {
                $this->log('Handling upload chunk');
                return $this->handleChunk();
            }

            $this->log('Handling test chunk');
            return $this->handleTestChunk();
        }
        $this->log('Missing resumable params');
        return null;
    }

    /**
     * Get isUploadComplete
     */
    public function isUploadComplete(): bool
    {
        return $this->isUploadComplete;
    }

    /**
     * Set final filename.
     *
     * @param string Final filename
     */
    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    /**
     * Get final filename.
     *
     * @return string Final filename
     */
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * Get final filename.
     *
     * @return string Final filename
     */
    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    /**
     * Get final filapath.
     *
     * @return string Final filename
     */
    public function getFilepath(): string
    {
        return $this->filepath;
    }

    /**
     * Creates a safe name
     *
     * @param string $name Original name
     * @return string A safer name
     */
    private function createSafeName(string $name): string
    {
        return FilenameSanitize::of($name)->get();
    }

    /**
     * @see https://github.com/23/resumable.js/blob/v1.1.0/README.md#handling-get-or-test-requests
     * - If this request returns a 200 or 201 HTTP code, the chunks is assumed to have been completed.
     * - If the request returns anything else, the chunk will be uploaded in the standard fashion.
     * (It is recommended to return 204 No Content in these cases if possible
     *                       to avoid unwarranted notices in browser consoles.)
     * - If this request returns a 422 HTTP code, one of the parameters is missing
     */
    public function handleTestChunk(): ResponseInterface
    {
        $identifier  = $this->resumableParam($this->resumableOption['identifier']);
        $filename    = $this->resumableParam($this->resumableOption['filename']);
        $chunkNumber = $this->resumableParam($this->resumableOption['chunkNumber']);

        // A parameter is missing
        if ($identifier === null || $filename === null || $chunkNumber === null) {
            return $this->response->withStatus(422);
        }

        if ($this->isChunkUploaded($identifier, $filename, (int) $chunkNumber)) {
            // We have the chunk, do not send it
            return $this->response->withStatus(200);
        }

        // We do not have the chunk, please send it
        return $this->response->withStatus(204);
    }

    /**
     * - 200: The chunk was accepted and correct. No need to re-upload.
     * - 404, 415. 500, 501: The file for which the chunk was uploaded is not supported, cancel the entire upload.
     * - Anything else: Something went wrong, but try reuploading the file.
     */
    public function handleChunk(): ResponseInterface
    {
        /** @var UploadedFileInterface[] $files */
        $files       = $this->request->getUploadedFiles();
        $identifier  = $this->resumableParam($this->resumableOption['identifier']);
        $filename    = $this->resumableParam($this->resumableOption['filename']);
        $chunkNumber = (int) $this->resumableParam($this->resumableOption['chunkNumber']);
        $chunkSize   = (int) $this->resumableParam($this->resumableOption['chunkSize']);
        $totalChunks = (int) $this->resumableParam($this->resumableOption['totalChunks']);

        if ($chunkSize <= 0) {
            $this->log('The chunk size is <= 0');
            return $this->response->withStatus(422);
        }

        if (! $this->isChunkUploaded($identifier, $filename, $chunkNumber)) {
            if (count($files) > 0) {
                $firstFile = array_shift($files);
                if ($firstFile instanceof UploadedFileInterface) {
                    $chunkDir        = $this->tmpChunkDir($identifier) . DIRECTORY_SEPARATOR;
                    $this->chunkFile = $chunkDir . $this->tmpChunkFilename($filename, $chunkNumber);
                    $this->log('Moving chunk', ['identifier' => $identifier, 'chunkNumber' => $chunkNumber]);
                    // On the server that received the upload
                    $localTempFile = $firstFile->getStream()->getMetadata('uri');
                    $ressource     = fopen($localTempFile, 'r');
                    if ($ressource === false) {
                        $this->log('Unable to open the stream', ['localTempFile' => $localTempFile]);
                        return $this->response->withStatus(500);
                    }

                    $this->fileSystem->write(
                        $this->chunkFile,
                        $ressource
                    );
                    fclose($ressource);
                    unlink($localTempFile);
                } else {
                    $this->log('The file does not implement UploadedFileInterface');
                    return $this->response->withStatus(422);
                }
            }
        }

        if ($this->isFileUploadComplete($filename, $identifier, $totalChunks)) {
            $this->isUploadComplete = true;
            $this->log('Upload is complete', ['identifier' => $identifier]);
            $this->createFileAndDeleteTmp($identifier, $filename);
        }

        return $this->response->withStatus(201);
    }

    /**
     * Create the final file from chunks
     */
    private function createFileAndDeleteTmp(string $identifier, ?string $filename): void
    {
        $chunkDir   = $this->tmpChunkDir($identifier) . DIRECTORY_SEPARATOR;
        $chunkFiles = $this->fileSystem->listKeys(
            $chunkDir
        )['keys'];

        $this->originalFilename = $filename;

        // if the user has set a custom filename
        if (null === $this->filename) {
            $this->filename = $this->createSafeName($this->originalFilename);
            $this->log('Created safe filename', ['finalFilename' => $this->filename]);
        }

        // replace filename reference by the final file
        $this->filepath = $this->uploadFolder . DIRECTORY_SEPARATOR . $this->filename;

        $finalFileCreated = $this->createFileFromChunks($chunkFiles, $this->filepath);

        if ($finalFileCreated) {
            $this->log('File re-assembly is done', ['identifier' => $identifier]);
        }

        if ($finalFileCreated === false) {
            // Stop here upload is not complete
            return;
        }

        foreach ($chunkFiles as $chunkFile) {
            $this->log('Removing chunk file', ['chunkFile' => $chunkFile]);
            $this->fileSystem->delete($chunkFile);
        }

        $this->log('Removing chunk dir', ['chunkDir' => $chunkDir]);

        // See: https://github.com/KnpLabs/Gaufrette/issues/524
        if (method_exists($this->fileSystem, 'getAdapter')) {
            $this->fileSystem->getAdapter()->delete($chunkDir);
            return;
        }

        $this->fileSystem->delete($chunkDir);
    }

    /**
     * @return mixed|null
     */
    private function resumableParam(string $shortName)
    {
        $resumableParams = $this->resumableParams();
        if (!isset($resumableParams['resumable' . ucfirst($shortName)])) {
            return null;
        }
        return $resumableParams['resumable' . ucfirst($shortName)];
    }

    public function resumableParams(): array
    {
        $method = strtoupper($this->request->getMethod());
        if ($method === 'GET') {
            return $this->request->getQueryParams();
        } elseif ($method === 'POST') {
            return $this->request->getParsedBody();
        }
        return [];
    }

    public function isFileUploadComplete(string $filename, string $identifier, int $totalChunks): bool
    {
        for ($i = 1; $i <= $totalChunks; $i++) {
            if (!$this->isChunkUploaded($identifier, $filename, $i)) {
                return false;
            }
        }
        return true;
    }

    public function isChunkUploaded(string $identifier, string $filename, int $chunkNumber): bool
    {
        $chunkDir = $this->tmpChunkDir($identifier) . DIRECTORY_SEPARATOR;
        return $this->fileSystem->has(
            $chunkDir . $this->tmpChunkFilename($filename, $chunkNumber)
        );
    }

    public function tmpChunkDir(string $identifier): string
    {
        return $this->tempFolder . DIRECTORY_SEPARATOR . $this->createSafeName($identifier);
    }

    /**
     * @example mock-file.png.0001 For a filename "mock-file.png"
     */
    public function tmpChunkFilename(string $filename, int $chunkNumber): string
    {
        return $this->createSafeName($filename) . '.' . str_pad((string) $chunkNumber, 4, '0', STR_PAD_LEFT);
    }

    public function createFileFromChunks(array $chunkFiles, string $destFile): bool
    {
        if ($this->fileSystem->has($destFile)) {
            $this->log('The final file already exists', ['finalFile' => $destFile]);
            return false;
        }

        $this->log('Beginning of create files from chunks');

        natsort($chunkFiles);

        $stream = $this->fileSystem->createFile($destFile)->createStream();
        $stream->open(new StreamMode('x'));

        foreach ($chunkFiles as $chunkFile) {
            $stream->write($this->fileSystem->read($chunkFile));
            $this->log('Appending to file', ['chunkFile' => $chunkFile]);
        }

        $stream->flush();
        $stream->close();

        $this->log('End of create files from chunks');
        return $this->fileSystem->has($destFile);
    }

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function setResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    private function log(string $msg, array $ctx = []): void
    {
        if ($this->debug && $this->logger !== null) {
            $this->logger->debug($msg, $ctx);
        }
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

}
