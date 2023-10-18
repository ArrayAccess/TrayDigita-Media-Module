<?php
declare(strict_types=1);

namespace ArrayAccess\TrayDigita\App\Modules\Media\Traits;

use ArrayAccess\TrayDigita\App\Modules\Users\Entities\Admin;
use ArrayAccess\TrayDigita\Collection\Config;
use ArrayAccess\TrayDigita\Database\Entities\Abstracts\AbstractUser;
use ArrayAccess\TrayDigita\Exceptions\Runtime\RuntimeException;
use ArrayAccess\TrayDigita\Util\Filter\ContainerHelper;
use ArrayAccess\TrayDigita\Util\Filter\DataNormalizer;
use ArrayAccess\TrayDigita\Util\Filter\MimeType;
use Psr\Http\Message\UploadedFileInterface;
use function date;
use function defined;
use function dirname;
use function explode;
use function is_dir;
use function is_string;
use function mkdir;
use function preg_match;
use function realpath;
use function strtolower;
use function trim;
use const TD_APP_DIRECTORY;
use const TD_INDEX_FILE;

trait MediaPathTrait
{
    protected string $uploadDirectory;

    protected string $publicDirectory;

    protected string $dataDirectory;

    protected string $pathScript = 'scripts';

    protected string $pathImages = 'images';

    protected string $pathAudio = 'audio';

    protected string $pathVideo = 'video';

    protected string $pathDocuments = 'documents';

    protected string $avatarPath = 'avatars';

    protected string $pathFiles = 'files';

    protected string $frontendPath  = 'frontend';

    protected string $backendPath = 'backend';

    private bool $registeredPathInit = false;

    private function doFilterPath(): static
    {
        if ($this->registeredPathInit) {
            return $this;
        }

        $this->registeredPathInit = true;
        $path = ContainerHelper::service(Config::class, $this->getContainer())->get('path');
        $path = $path instanceof Config ? $path : new Config();
        $dataDir = $path->get('data');
        if (!$dataDir || !is_string($dataDir)) {
            $dataDir = dirname(TD_APP_DIRECTORY) . '/data';
        }
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        $uploadDir = $path->get('upload');
        $publicDir = $path->get('public');
        if (!$uploadDir || !is_string($uploadDir) || !is_dir($uploadDir)) {
            if (!is_string($publicDir)
                || !$publicDir
                || !realpath($publicDir)
            ) {
                if (!defined('TD_INDEX_FILE')) {
                    throw new RuntimeException(
                        'Could not determine public directory'
                    );
                }
                $publicDir = dirname(TD_INDEX_FILE);
            } else {
                $publicDir = realpath($publicDir);
            }
            $uploadDir = $publicDir . '/uploads';
        }

        $this->dataDirectory = DataNormalizer::normalizeDirectorySeparator($dataDir);
        $this->uploadDirectory = DataNormalizer::normalizeUnixDirectorySeparator($uploadDir);
        $this->publicDirectory = DataNormalizer::normalizeDirectorySeparator(dirname($this->uploadDirectory));
        return $this;
    }

    public function getUploadDirectory(): string
    {
        return $this->doFilterPath()->uploadDirectory;
    }

    public function getDataDirectory(): string
    {
        return $this->doFilterPath()->dataDirectory;
    }

    public function getPublicDirectory(): string
    {
        return $this->doFilterPath()->publicDirectory;
    }

    public function getPathScript(): string
    {
        return $this->doFilterPath()->pathScript;
    }

    public function getPathImages(): string
    {
        return $this->doFilterPath()->pathImages;
    }

    public function getPathAudio(): string
    {
        return $this->doFilterPath()->pathAudio;
    }

    public function getPathVideo(): string
    {
        return $this->doFilterPath()->pathVideo;
    }

    public function getPathDocuments(): string
    {
        return $this->doFilterPath()->pathDocuments;
    }

    public function getPathFiles(): string
    {
        return $this->doFilterPath()->pathFiles;
    }

    public function getAvatarPath(): string
    {
        return $this->doFilterPath()->avatarPath;
    }

    public function getFrontendPath(): string
    {
        return $this->doFilterPath()->frontendPath;
    }

    public function getBackendPath(): string
    {
        return $this->doFilterPath()->backendPath;
    }

    public function determineUploadPath(?AbstractUser $user = null): string
    {
        return $user instanceof Admin
            ? $this->getBackendPath()
            : $this->getFrontendPath();
    }

    public function determinePathMimeType(
        string|UploadedFileInterface $mimetype,
        bool $noResolve = false
    ): string {
        $clientFileName = null;
        if ($mimetype instanceof UploadedFileInterface) {
            $clientFileName = $mimetype->getClientFilename();
            if (!$noResolve) {
                $newMimeType = MimeType::mimeTypeUploadedFile($mimetype);
                $mimetype = $newMimeType ?? $mimetype->getClientMediaType();
            } else {
                $mimetype = $mimetype->getClientMediaType();
            }
        }

        // stop here if extension contains scripts
        if ($clientFileName
            && (
                preg_match(self::SCRIPT_REGEX, $clientFileName)
                || preg_match(
                    // json shell exec etc.
                    '~/.*(json|shell|php|ecmascript|bash|exec|binary).*$~',
                    $mimetype
                )
            )
        ) {
            return $this->getPathScript();
        }

        $mimetype = $mimetype instanceof UploadedFileInterface
            ? strtolower($mimetype->getClientMediaType())
            : strtolower(trim($mimetype));

        $match = match ($mimetype) {
            'image/svg+xml' => $this->getPathImages(),
            'text/css',
            'text/javascript',
            'text/ecmascript',
            'application/sql',
            'application/x-sql',
            'text/x-php',
            'application/x-php',
            'application/json',
            'application/javascript' => $this->getPathScript(),
            'text/plain',
            'text/html',
            'text/xml',
            'text/csv',
            'text/calendar',
            'application/pgp-signature',
            'application/pdf',
            'application/rtf',
            'text/csv-schema',
            'application/xml' => $this->getPathDocuments(),
            default => null
        };
        $match = $match?? match (explode('/', $mimetype, 2)[0]) {
            'audio' => $this->getPathAudio(),
            'video' => $this->getPathVideo(),
            'image' => $this->getPathImages(),
            default => null
        };

        if ($match) {
            return $match;
        }
        if (preg_match(
            '~^application/
                    .*
                (?:officedocument|openxmlformats|xml|xsl|msword|ppt|odt|pdf|wpd|docx?)
            ~x',
            $mimetype
        )) {
            return $this->getPathDocuments();
        }

        // fallback
        return $this->getPathFiles();
    }

    /**
     * Determine recommended upload directory
     *
     * @param UploadedFileInterface $uploadedFile
     * @param ?AbstractUser $user
     * @param bool $noResolve
     * @return string
     */
    public function determineUploadDirectory(
        UploadedFileInterface $uploadedFile,
        ?AbstractUser $user = null,
        bool $noResolve = false
    ) : string {
        $directory = $this->getUploadDirectory();
        $directory .= '/' . $this->determineUploadPath($user);
        $directory .= '/' . $this->determinePathMimeType($uploadedFile, noResolve: $noResolve);
        $directory .= '/' . date('Y/m/d');
        return $directory;
    }

    /**
     * @param AbstractUser|null $user
     * @return string
     */
    public function determineAvatarUploadDirectory(
        ?AbstractUser $user = null
    ) : string {
        $directory = $this->getUploadDirectory();
        $directory .= '/' . $this->determineUploadPath($user);
        $directory .= '/' . $this->getAvatarPath();
        return $directory;
    }

    /**
     * Determine recommended upload directory
     *
     * @param UploadedFileInterface $uploadedFile
     * @param AbstractUser|null $user
     * @param bool $noResolve
     * @return string
     */
    public function determineDataDirectory(
        UploadedFileInterface $uploadedFile,
        ?AbstractUser $user = null,
        bool $noResolve = false
    ) : string {
        $directory = $this->getDataDirectory();
        $directory .= '/' . $this->determineUploadPath($user);
        $directory .= '/' . $this->determinePathMimeType($uploadedFile, noResolve: $noResolve);
        $directory .= '/' . date('Y/m/d');
        return $directory;
    }
}
