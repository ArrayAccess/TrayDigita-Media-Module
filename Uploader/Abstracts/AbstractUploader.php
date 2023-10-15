<?php
declare(strict_types=1);

namespace ArrayAccess\TrayDigita\App\Modules\Media\Uploader\Abstracts;

use ArrayAccess\TrayDigita\App\Modules\Users\Entities\Admin;
use ArrayAccess\TrayDigita\App\Modules\Users\Entities\Attachment;
use ArrayAccess\TrayDigita\App\Modules\Users\Entities\User;
use ArrayAccess\TrayDigita\App\Modules\Users\Entities\UserAttachment;
use ArrayAccess\TrayDigita\App\Modules\Media\Media;
use ArrayAccess\TrayDigita\App\Modules\Media\Uploader\UploadedFileMetadata;
use ArrayAccess\TrayDigita\Database\Connection;
use ArrayAccess\TrayDigita\Database\Entities\Abstracts\AbstractAttachment;
use ArrayAccess\TrayDigita\Database\Entities\Abstracts\AbstractUser;
use ArrayAccess\TrayDigita\Exceptions\Runtime\RuntimeException;
use ArrayAccess\TrayDigita\Http\UploadedFile;
use ArrayAccess\TrayDigita\Traits\Service\TranslatorTrait;
use ArrayAccess\TrayDigita\Uploader\Exceptions\UploadedFileExtensionException;
use ArrayAccess\TrayDigita\Uploader\Exceptions\UploadedFileNameException;
use ArrayAccess\TrayDigita\Util\Filter\Consolidation;
use ArrayAccess\TrayDigita\Util\Filter\ContainerHelper;
use ArrayAccess\TrayDigita\Util\Filter\MimeType;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;
use function file_exists;
use function function_exists;
use function mime_content_type;
use function pathinfo;
use function reset;
use function sprintf;
use function unlink;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

class AbstractUploader
{
    final const TYPE_UPLOAD = AbstractAttachment::TYPE_UPLOAD;
    final const TYPE_DATA = AbstractAttachment::TYPE_DATA;
    final const TYPE_AVATAR = AbstractAttachment::TYPE_AVATAR;

    use TranslatorTrait;

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
        AbstractUser $user
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
                $this->translate('File does not have file name')
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
                    $this->translate('Could not determine file type from mimetype %s'),
                    $uploadedFile->getClientMediaType()
                )
            );
        }

        $em = ContainerHelper::service(
            Connection::class,
            $this->getContainer()
        )->getEntityManager();
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
        if (!$progress->isDone()) {
            return new UploadedFileMetadata(
                $this,
                $type,
                $request,
                $uploadedFile,
                $progress,
                false
            );
        }

        $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
        $newMimeType = MimeType::mime($extension);
        if ($progress->getSize() > $uploadedFile->getSize()
            && $newMimeType !== $uploadedFile->getClientMediaType()
            // check the mime type
            && (
                function_exists('mime_content_type')
                && ($newMime = mime_content_type($progress->targetCacheFile))
                && $newMime !== $uploadedFile->getClientMediaType()
                || ! ($newMime??null)
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
                $this->translate('Could not save uploaded file')
            );
        }

        $basePath = $this->media->getAttachmentFileToBasePath($fullPath);
        if (!$basePath) {
            Consolidation::callbackReduceError(fn () => unlink($fullPath));
            throw new RuntimeException(
                $this->translate(
                    'Could not save uploaded file & determine target file.'
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
                $attachment->setName($name?:$originalFileName);
                $attachment->setStatus($attachment::PUBLISHED);
            }

            if ($user instanceof Admin || $user instanceof User) {
                $attachment->setUser($user);
                $attachment->setUserId($user->getId());
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
            $attachment
        );
        if ($type === self::TYPE_AVATAR) {
            $result = $this->dispatchAvatarUpload($result);
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
