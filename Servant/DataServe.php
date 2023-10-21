<?php
declare(strict_types=1);

namespace ArrayAccess\TrayDigita\App\Modules\Media\Servant;

use ArrayAccess\TrayDigita\App\Modules\Media\Media;
use ArrayAccess\TrayDigita\Responder\FileResponder;
use ArrayAccess\TrayDigita\Util\Filter\DataNormalizer;
use ArrayAccess\TrayDigita\Util\Filter\MimeType;
use DateTimeImmutable;
use DateTimeZone;
use function clearstatcache;
use function filemtime;
use function filesize;
use function is_file;
use function is_readable;
use function str_starts_with;

/**
 * Object class to get file output from data storage directory
 */
final class DataServe
{
    private array $cachedSize = [];

    /**
     * @var array<string, int>
     */
    private array $cachedLastModified = [];

    private array $cachedNormalize = [];

    public function __construct(public readonly Media $uploader)
    {
    }

    public function getNormalizeFile(string $file)
    {
        if (isset($this->cachedNormalize[$file])) {
            return $this->cachedNormalize[$file]?:null;
        }
        $file = DataNormalizer::normalizeDirectorySeparator($file);
        $uploadDirectory = $this->uploader->getDataDirectory();
        if (!str_starts_with($file, $uploadDirectory)) {
            $file = DataNormalizer::normalizeDirectorySeparator(
                $uploadDirectory . '/' .$file
            );
        }
        if (is_file($file) && is_readable($file)) {
            $this->cachedNormalize[$file] = $file;
        } else {
            $this->cachedNormalize[$file] = false;
        }
        return $this->cachedNormalize[$file]?:null;
    }

    public function size(string $file): bool|int
    {
        $file = $this->getNormalizeFile($file);
        if (!$file) {
            return false;
        }
        if (isset($this->cachedSize[$file])) {
            return $this->cachedSize[$file];
        }
        return $this->cachedSize[$file] = filesize($file);
    }

    public function getMimeType(string $file): ?string
    {
        $file = $this->getNormalizeFile($file);
        if (!$file) {
            return null;
        }
        return MimeType::fileMimeType($file);
    }

    /** @noinspection PhpUnused */
    public function getLastModified(string $file) : ?DateTimeImmutable
    {
        $file = $this->getNormalizeFile($file);
        if (!$file) {
            return null;
        }
        if (!isset($this->cachedLastModified[$file])) {
            // clear stat cache
            clearstatcache(true, $file);
            $this->cachedLastModified[$file] = filemtime($file);
        }
        return DateTimeImmutable::createFromFormat(
            'c',
            gmdate('c', $this->cachedLastModified[$file]),
            new DateTimeZone('UTC')
        );
    }

    /**
     * @param string $file
     * @param bool $sendHeaderContentLength
     * @param bool $allowRange
     * @param bool $sendAsAttachment
     * @return bool
     */
    public function display(
        string $file,
        bool $sendHeaderContentLength = false,
        bool $allowRange = false,
        bool $sendAsAttachment = false
    ) : bool {
        $file = $this->getNormalizeFile($file);
        if (!$file || !is_file($file)) {
            return false;
        }
        $size = $this->size($file);
        if ($size === false) {
            return false;
        }
        $responder = (new FileResponder($file));
        $responder->sendContentLength($sendHeaderContentLength);
        $responder->setAllowRange($allowRange);
        $responder->sendAsAttachment($sendAsAttachment);
        // never
        $responder->send();
    }
}
