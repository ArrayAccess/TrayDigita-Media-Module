<?php
declare(strict_types=1);

namespace ArrayAccess\TrayDigita\App\Modules\Media\Uploader\Abstracts;

use ArrayAccess\TrayDigita\App\Modules\Media\Entities\Attachment;
use ArrayAccess\TrayDigita\App\Modules\Media\Entities\UserAttachment;
use ArrayAccess\TrayDigita\App\Modules\Media\Media;
use ArrayAccess\TrayDigita\App\Modules\Media\Uploader\UploadedFileMetadata;
use ArrayAccess\TrayDigita\App\Modules\Users\Entities\Admin;
use ArrayAccess\TrayDigita\App\Modules\Users\Entities\User;
use ArrayAccess\TrayDigita\Database\Entities\Abstracts\AbstractAttachment;
use ArrayAccess\TrayDigita\Database\Entities\Abstracts\AbstractUser;
use ArrayAccess\TrayDigita\Exceptions\Runtime\RuntimeException;
use ArrayAccess\TrayDigita\Http\UploadedFile;
use ArrayAccess\TrayDigita\Traits\Database\ConnectionTrait;
use ArrayAccess\TrayDigita\Traits\Service\TranslatorTrait;
use ArrayAccess\TrayDigita\Uploader\Exceptions\UploadedFileExtensionException;
use ArrayAccess\TrayDigita\Uploader\Exceptions\UploadedFileNameException;
use ArrayAccess\TrayDigita\Util\Filter\Consolidation;
use ArrayAccess\TrayDigita\Util\Filter\MimeType;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;
use function file_exists;
use function function_exists;
use function is_file;
use function mime_content_type;
use function pathinfo;
use function reset;
use function sprintf;
use function unlink;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;
use const PHP_INT_MAX;

class AbstractUploader
{
    final public const TYPE_UPLOAD = AbstractAttachment::TYPE_UPLOAD;

    final public const TYPE_DATA = AbstractAttachment::TYPE_DATA;

    final public const TYPE_AVATAR = AbstractAttachment::TYPE_AVATAR;

    use TranslatorTrait,
        ConnectionTrait;

    public function __construct(public readonly Media $media)
    {
    }

    public function getContainer(): ?ContainerInterface
    {
        return $this->getMedia()->getContainer();
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    /**
     * @throws Throwable
     * @noinspection PhpUnused
     */
    public function uploadAvatar(
        ServerRequestInterface $request,
        UploadedFileInterface $uploadedFile,
        AbstractUser $user
    ): ?UploadedFileMetadata {
        return $this->uploadAttachment(
            $request,
            $uploadedFile,
            $user,
            self::TYPE_AVATAR
        );
    }

    /**
     * @throws Throwable
     * @noinspection PhpUnused
     */
    public function uploadData(
        ServerRequestInterface $request,
        UploadedFileInterface $uploadedFile,
        AbstractUser $user
    ): UploadedFileMetadata {
        return $this->uploadAttachment(
            $request,
            $uploadedFile,
            $user,
            self::TYPE_DATA
        );
    }

    /**
     * @throws Throwable
     */
    public function uploadPublic(
        ServerRequestInterface $request,
        UploadedFileInterface $uploadedFile,
        ?AbstractUser $user
    ): UploadedFileMetadata {
        return $this->uploadAttachment(
            $request,
            $uploadedFile,
            $user,
            self::TYPE_UPLOAD
        );
    }

    /**
     * @throws Throwable
     */
    public function uploadAttachment(
        ServerRequestInterface $request,
        UploadedFileInterface $uploadedFile,
        ?AbstractUser $user,
        string $type
    ) : UploadedFileMetadata {
        $type = match ($type) {
            self::TYPE_AVATAR => self::TYPE_AVATAR,
            self::TYPE_UPLOAD => self::TYPE_UPLOAD,
            default => self::TYPE_DATA
        };
        if (!$uploadedFile->getClientFilename()) {
            throw new UploadedFileNameException(
                $this->translateContext(
                    'File does not have file name',
                    'media-module',
                    'module'
                )
            );
        }
        $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
        if (!$extension) {
            $extensions = MimeType::fromMimeType($uploadedFile->getClientMediaType());
            $extension = $extensions ? reset($extensions) : null;
        }
        if (!$extension) {
            throw new UploadedFileExtensionException(
                sprintf(
                    $this->translateContext(
                        'Could not determine file type from mimetype %s',
                        'media-module',
                        'module'
                    ),
                    $uploadedFile->getClientMediaType()
                )
            );
        }

        $em = $this->getEntityManager();
        /**
         * @var class-string<AbstractAttachment> $className
         */
        $className = $user instanceof Admin
            ? Attachment::class
            : UserAttachment::class;
        $repository = $em->getRepository($className);
        $uploadedFile = MimeType::resolveMediaTypeUploadedFiles($uploadedFile);
        $originalFileName = $uploadedFile->getClientFilename();
        $progress = $this->media->upload($uploadedFile, $request);
        $metafile = $progress->targetCacheFile
            . '.meta.'
            . $progress->handler->processor->chunk->partialExtension;
        $metadata = $progress->getMetadata();
        $newMimeType = $metadata['mimetype']??null;
        $requestTime = $metadata['first_time']??null;
        $count = $metadata['count']??null;
        $timing = $metadata['timing']??[];
        unset($metadata);
        if ($progress->handler->processor->isNewRequestId) {
            $count = 1;
            $this->getMedia()->getManager()->attach(
                'jsonResponder.format',
                function ($e) use ($newMimeType) {
                    $e['mime'] = $newMimeType;
                    return $e;
                },
                priority: PHP_INT_MAX - 5
            );
        }

        if ($newMimeType && $newMimeType !== $uploadedFile->getClientMediaType()) {
            $uploadedFile = new UploadedFile(
                $uploadedFile->getStream(),
                $uploadedFile->getSize(),
                $uploadedFile->getError(),
                $uploadedFile->getClientFilename(),
                $newMimeType
            );
        }
        if (!$progress->isDone()) {
            return new UploadedFileMetadata(
                $this,
                $type,
                $request,
                $uploadedFile,
                $progress,
                false,
                firstMicrotime: $requestTime,
                chunkCount: $count,
                timing: $timing
            );
        }

        try {
            $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
            if (!$newMimeType) {
                $newMimeType = MimeType::mime($extension);
                if ($progress->getSize() > $uploadedFile->getSize()
                    && $newMimeType !== $uploadedFile->getClientMediaType()
                    // check the mime type
                    && (
                        function_exists('mime_content_type')
                        && ($newMime = mime_content_type($progress->targetCacheFile))
                        && $newMime !== $uploadedFile->getClientMediaType()
                        || !($newMime ?? null)
                    )
                ) {
                    $newMime ??= $newMimeType;
                    $uploadedFile = new UploadedFile(
                        $uploadedFile->getStream(),
                        $uploadedFile->getSize(),
                        $uploadedFile->getError(),
                        $uploadedFile->getClientFilename(),
                        $newMime
                    );
                }
            }

            if ($type === self::TYPE_AVATAR) {
                $filePath = $this->media->getAvatarUploadFullPathByUser($uploadedFile, $user, true);
                $uploadedFile = $this->getUploadedFileAvatar(
                    $uploadedFile,
                    $filePath
                );
            } else {
                $uploadDirectory = match ($type) {
                    self::TYPE_UPLOAD => $this->media->determineUploadDirectory($uploadedFile, $user, true),
                    default => $this->media->determineDataDirectory($uploadedFile, $user, true),
                };
                $clientFileName = $this->media->filterFileNameUseLower($uploadedFile, true);
                $filePath = $uploadDirectory . '/' . $clientFileName;
            }

            $fullPath = $progress->put(
                $filePath,
                $type === self::TYPE_AVATAR
            );

            if (!$fullPath) {
                $progress->deletePartial();
                throw new RuntimeException(
                    $this->translateContext(
                        'Could not save uploaded file',
                        'media-module',
                        'module'
                    )
                );
            }

            $basePath = $this->media->getAttachmentFileToBasePath($fullPath);
            if (!$basePath) {
                Consolidation::callbackReduceError(fn() => unlink($fullPath));
                throw new RuntimeException(
                    $this->translateContext(
                        'Could not save uploaded file & determine target file.',
                        'media-module',
                        'module'
                    )
                );
            }

            try {
                $attachment = $repository
                    ->findOneBy([
                        'path' => $basePath,
                        'storage_type' => $type
                    ]);
                if (!$attachment) {
                    $name = pathinfo($originalFileName, PATHINFO_FILENAME);
                    $attachment = new $className();
                    $attachment->setEntityManager($em);
                    $attachment->setPath($basePath);
                    $attachment->setName($name ?: $originalFileName);
                    $attachment->setStatus($attachment::PUBLISHED);
                }

                if ($user instanceof Admin || $user instanceof User) {
                    $attachment->setUser($user);
                    $attachment->setUserId($user->getId());
                    $attachment->setSite($user->getSite());
                } else {
                    $attachment->setUser(null);
                    $attachment->setUserId(null);
                    $attachment->setSite(null);
                }

                $attachment->setFileName($originalFileName);
                $attachment->setStorageType($attachment::TYPE_UPLOAD);
                $attachment->setSize($progress->getSize());
                $attachment->setDeletedAt(null);
                $attachment->setMimeType($uploadedFile->getClientMediaType());
                $em->persist($attachment);
                $em->flush();
            } catch (Throwable $e) {
                if (file_exists($fullPath)) {
                    Consolidation::callbackReduceError(fn() => unlink($fullPath));
                }
                throw $e;
            }

            $result = new UploadedFileMetadata(
                $this,
                $type,
                $request,
                $uploadedFile,
                $progress,
                true,
                $fullPath,
                $attachment,
                firstMicrotime: $requestTime,
                chunkCount: $count,
                timing: $timing
            );
            if ($type === self::TYPE_AVATAR) {
                $result = $this->dispatchAvatarUpload($result);
            }
        } finally {
            if (is_file($metafile)) {
                Consolidation::callbackReduceError(fn() => unlink($metafile));
            }
        }
        return $result;
    }

    /**
     * This for event change dispatch
     *
     * @param UploadedFileInterface $uploadedFile
     * @param string $filePath
     * @return UploadedFileInterface
     * @noinspection PhpUnusedParameterInspection
     */
    protected function getUploadedFileAvatar(
        UploadedFileInterface $uploadedFile,
        string $filePath
    ) : UploadedFileInterface {
        return $uploadedFile;
    }

    protected function dispatchAvatarUpload(UploadedFileMetadata $metadata) : UploadedFileMetadata
    {
        return $metadata;
    }
}
