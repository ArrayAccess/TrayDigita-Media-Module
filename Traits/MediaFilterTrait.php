<?php
declare(strict_types=1);

namespace ArrayAccess\TrayDigita\App\Modules\Media\Traits;

use ArrayAccess\TrayDigita\Database\Entities\Abstracts\AbstractUser;
use ArrayAccess\TrayDigita\Exceptions\InvalidArgument\UnsupportedArgumentException;
use ArrayAccess\TrayDigita\Util\Filter\ContainerHelper;
use ArrayAccess\TrayDigita\Util\Filter\DataNormalizer;
use ArrayAccess\TrayDigita\Util\Filter\MimeType;
use ArrayAccess\TrayDigita\View\Interfaces\ViewInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use function array_map;
use function array_shift;
use function basename;
use function dirname;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function ltrim;
use function pathinfo;
use function preg_match;
use function preg_replace_callback;
use function reset;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

trait MediaFilterTrait
{
    const SCRIPT_REGEX = '~\.(?:
        py          # python
        |php[0-9]*|hh  # php
        |[xp]?html[0-9]* # html
        |m?jsx?  # js
        |inc    # php etc.
        |[hc]   # c / header
        |cpp    # cpp
        |go     # golang
        |java|jsp|gradle   # java
        |cs     # visual studio
        |vb     # visual basic
        |bat|cmd    # windows bat/cli
        |sh|bash # bash/sh
        |swift # swift
        |aspx? # microsoft IIS
        |arpa|zone # dns
        |dart   # dart
        |tsx?   # typescript
        |graphql # graphql
        |groovy|gsp # groovy
        |haml # haml
        |hs|hsc # haskell
        |jade # jade
        |zep  # zephir lang
    )$~xi';

    private array $allowedAvatarMimeTypes = [
        'image/svg+xml',
        'image/png',
        'image/webp',
        'image/jpeg',
        'image/gif',
    ];

    public function getAllowedAvatarMimeTypes(): array
    {
        return $this->allowedAvatarMimeTypes;
    }

    public function setAllowedAvatarMimeTypes(string ...$allowedAvatarMimeTypes): void
    {
        $this->allowedAvatarMimeTypes = array_map('strtolower', $allowedAvatarMimeTypes);
    }

    /**
     * @param string $path
     * @return ?string
     */
    public function getAttachmentFileToBasePath(
        string $path
    ): ?string {
        $path = DataNormalizer::normalizeUnixDirectorySeparator($path);
        $uploadDirectory = DataNormalizer::normalizeUnixDirectorySeparator(
            $this->getUploadDirectory()
        );
        $dataDirectory = DataNormalizer::normalizeUnixDirectorySeparator(
            $this->getUploadDirectory()
        );
        $avatarDirectory = DataNormalizer::normalizeUnixDirectorySeparator(
            $this->getUploadDirectory()
        );

        $currentDirectory = str_starts_with($path, $avatarDirectory) ? $avatarDirectory : null;
        $currentDirectory ??= str_starts_with($path, $uploadDirectory) ? $uploadDirectory : null;
        $currentDirectory ??= str_starts_with($path, $dataDirectory) ? $dataDirectory : null;
        if (!$currentDirectory) {
            return null;
        }

        $currentDirectory = ltrim(substr($path, strlen($currentDirectory)), '/');
        if (str_starts_with($currentDirectory, $this->getFrontendPath())
            || str_starts_with($currentDirectory, $this->getBackendPath())
        ) {
            $explode = explode('/', $currentDirectory, 2);
            array_shift($explode);
            return implode('/', $explode);
        }

        return $currentDirectory;
    }

    public function getUploadFileToBasePath(
        string $path
    ) : ?string {
        $path = DataNormalizer::normalizeUnixDirectorySeparator($path);
        $uploadDirectory = DataNormalizer::normalizeUnixDirectorySeparator(
            $this->getUploadDirectory()
        );
        /** @noinspection DuplicatedCode */
        if (!str_starts_with($path, $uploadDirectory)) {
            return null;
        }
        $uploadDirectory = ltrim(substr($path, strlen($uploadDirectory)), '/');
        if (str_starts_with($uploadDirectory, $this->getFrontendPath())
            || str_starts_with($uploadDirectory, $this->getBackendPath())
        ) {
            $explode = explode('/', $uploadDirectory, 2);
            array_shift($explode);
            return implode('/', $explode);
        }
        return $uploadDirectory;
    }

    public function getDataFileToBasePath(
        string $path
    ) : ?string {
        $path = DataNormalizer::normalizeUnixDirectorySeparator($path);
        $dataDirectory = DataNormalizer::normalizeUnixDirectorySeparator(
            $this->getDataDirectory()
        );
        /** @noinspection DuplicatedCode */
        if (!str_starts_with($path, $dataDirectory)) {
            return null;
        }
        $dataDirectory = ltrim(substr($path, strlen($dataDirectory)), '/');
        if (str_starts_with($dataDirectory, $this->getFrontendPath())
            || str_starts_with($dataDirectory, $this->getBackendPath())
        ) {
            $explode = explode('/', $dataDirectory, 2);
            array_shift($explode);
            return implode('/', $explode);
        }
        return $dataDirectory;
    }

    public function getUploadFileToURI(
        string $path,
        ServerRequestInterface|UriInterface $requestOrUri
    ) : ?UriInterface {
        $path = DataNormalizer::normalizeUnixDirectorySeparator($path);
        if (!str_starts_with($path, $this->getPublicDirectory())) {
            return null;
        }
        $path = substr($path, strlen($this->getPublicDirectory()));
        $view = ContainerHelper::use(ViewInterface::class, $this->getContainer());
        return $view->getBaseURI(
            $path,
            $requestOrUri
        );
    }

    public function normalizeFileNameExtension(
        UploadedFileInterface|string $uploadedFile,
        bool $noResolve = false
    ) : ?string {
        $clientFileName = $uploadedFile instanceof UploadedFileInterface
            ? $uploadedFile->getClientFilename()
            : trim($uploadedFile);
        if (!$clientFileName) {
            return null;
        }

        if ($uploadedFile instanceof UploadedFileInterface) {
            $mimeType = $noResolve
                ? $uploadedFile->getClientMediaType()
                : MimeType::mimeTypeUploadedFile($uploadedFile);
        } else {
            $extension = pathinfo($uploadedFile, PATHINFO_EXTENSION);
            if (!$extension) {
                return null;
            }
            $mimeType = MimeType::mime($extension);
        }
        if (!$mimeType) {
            return null;
        }

        $newExtensions = MimeType::fromMimeType($mimeType);
        $newExtension = $newExtensions ? reset($newExtensions) : null;
        if (($extension = pathinfo($clientFileName, PATHINFO_EXTENSION))) {
            $newMimeType = MimeType::mime($extension);
            if (is_string($newMimeType) && $newMimeType !== $mimeType) {
                $mimes = MimeType::fromMimeType($newMimeType);
                if (!empty($mimes) && in_array(strtolower($extension), $mimes)) {
                    return $clientFileName;
                }
                $newExtension = MimeType::extension($newMimeType);
                return $newExtension !== $extension
                    ? $clientFileName . '.' . $newExtension
                    : $clientFileName;
            }

            if ($newExtensions && in_array($extension, $newExtensions)) {
                return $clientFileName;
            }
            return $newExtension
                ? pathinfo($clientFileName, PATHINFO_FILENAME) . '.' . $newExtension
                : $clientFileName;
        }

        return $clientFileName . ($newExtension ? '.' . $newExtension : '');
    }

    /**
     * filter filename
     * @param UploadedFileInterface|string $uploadedFile
     * @param bool $noResolve
     * @return string|null
     */
    public function filterFileNameUseLower(
        UploadedFileInterface|string $uploadedFile,
        bool $noResolve = false
    ): ?string {
        $file = $this->normalizeFileNameExtension($uploadedFile, $noResolve);
        if (!$file) {
            return null;
        }
        $baseName = basename($file);
        $dirname = dirname($file);
        $baseName = DataNormalizer::normalizeFileName($baseName);
        return (
            $dirname && $dirname !== '.' ? $dirname . '/' : ''
        ) . $this->filterFileExtension(strtolower($baseName));
    }

    /**
     * Filter file to make sure safe for public access
     *
     * @param string $fileName
     * @return string
     */
    public function filterFileExtension(
        string $fileName
    ): string {
        // filter :
        // php, html, js, inc, py, c, h, cpp, go, jsp, jsx, mjs
        if (!preg_match(self::SCRIPT_REGEX, $fileName)) {
            return $fileName;
        }
        return preg_replace_callback(
            // maybe user add query string or hash (? / #)
            '~\.([^.?#]+)([?#].*)?$~',
            static function ($e) {
                $ex = $e[2]??'';
                return ".$e[1]._x.txt$ex";
            },
            $fileName
        );
    }

    /**
     * @param UploadedFileInterface $uploadedFile
     * @param AbstractUser $user
     * @param bool $noResolve
     * @return string
     */
    public function getAvatarUploadFullPathByUser(
        UploadedFileInterface $uploadedFile,
        AbstractUser $user,
        bool $noResolve = false
    ) : string {
        $file = $this->determineAvatarUploadDirectory($user);
        if (!$noResolve) {
            $uploadedFile = MimeType::resolveMediaTypeUploadedFiles($uploadedFile);
        }
        if (!in_array($uploadedFile->getClientMediaType(), $this->getAllowedAvatarMimeTypes())) {
            throw new UnsupportedArgumentException(
                sprintf(
                    '%s is not supported for avatar extension',
                    $uploadedFile->getClientMediaType()
                )
            );
        }

        $extension = MimeType::extension($uploadedFile->getClientMediaType());
        $recommendedPrefix = 'uid-';
        $prefix = $this->getManager()?->dispatch(
            'media.avatarPrefix',
            $recommendedPrefix
        )?:$recommendedPrefix;
        if (!is_string($prefix)
            || trim($prefix) === ''
            || preg_match('~[^a-zA-Z0-9_\-]~', $prefix)
        ) {
            $prefix = $recommendedPrefix;
        }
        $fileName = sprintf('%s%s', $prefix, $user->getId());
        return sprintf(
            '%s/%s.%s',
            $file,
            $fileName,
            $extension
        );
    }
}
