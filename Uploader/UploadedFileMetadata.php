<?php
declare(strict_types=1);

namespace ArrayAccess\TrayDigita\App\Modules\Media\Uploader;

use ArrayAccess\TrayDigita\App\Modules\Media\Uploader\Abstracts\AbstractUploader;
use ArrayAccess\TrayDigita\Database\Entities\Abstracts\AbstractAttachment;
use ArrayAccess\TrayDigita\Http\UploadedFile;
use ArrayAccess\TrayDigita\Uploader\StartProgress;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use function basename;
use function file_exists;
use function filesize;
use function pathinfo;
use const PATHINFO_FILENAME;

// phpcs:disable PSR1.Files.SideEffects
readonly class UploadedFileMetadata
{
    public int $size;

    public function __construct(
        public AbstractUploader $uploader,
        public string $type,
        public ServerRequestInterface $request,
        public UploadedFile $uploadedFile,
        public StartProgress $progress,
        public bool $finished,
        public ?string $fullPath = null,
        public ?AbstractAttachment $attachment = null
    ) {
        $this->size = $this->fullPath && file_exists($this->fullPath)
            ? filesize($this->fullPath)
            : $this->progress->getSize();
    }

    /**
     * @param bool $pathOnly
     * @return array{
     *     id: ?string,
     *     uuid: string,
     *     file_name: string,
     *     mime_type: string,
     *     saved_name: string,
     *     uri: ?UriInterface|?string,
     *     size: int
     * }
     */
    public function toArray(bool $pathOnly = false): array
    {
        $uploadedFile = $this->uploadedFile;
        $fileName = $uploadedFile->getClientFilename();
        $uri = $this->finished
            ? $this->uploader->media->getUploadFileToURI($this->fullPath, $this->request)
            : null;
        if ($pathOnly) {
            $uri = $uri?->getPath();
        }
        return [
            'id' => $this->attachment?->getId(),
            'request_id' => $this->progress->processor->requestIdHeader->header,
            'name' => pathinfo($fileName, PATHINFO_FILENAME),
            'file_name' => $fileName,
            'mime_type' => $uploadedFile->getClientMediaType(),
            'saved_name' => $this->finished ? basename($this->fullPath) : null,
            'uri' => $uri,
            'size' => $this->size,
        ];
    }
}
