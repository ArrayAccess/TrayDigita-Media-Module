<?php
declare(strict_types=1);

namespace ArrayAccess\TrayDigita\App\Modules\Media\Servant;

// phpcs:disable PSR1.Files.SideEffects
use ArrayAccess\TrayDigita\Util\Filter\DataNormalizer;
use ArrayAccess\TrayDigita\Util\Filter\MimeType;
use DateTimeImmutable;
use DateTimeZone;
use function fclose;
use function feof;
use function filemtime;
use function filesize;
use function fopen;
use function fread;
use function header;
use function headers_sent;
use function is_file;
use function is_readable;
use function ob_end_flush;
use function ob_get_level;
use function ob_start;
use function str_starts_with;

/**
 * Object class to get file output from data storage directory
 */
final class DataServe
{
    private array $cachedSize = [];
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
        $uploadDir = $this->uploader->getDataDirectory();
        if (!str_starts_with($file, $uploadDir)) {
            $file = DataNormalizer::normalizeDirectorySeparator(
                $uploadDir . '/' .$file
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

    public function getLastModified(string $file) : ?DateTimeImmutable
    {
        $file = $this->getNormalizeFile($file);
        if (!$file) {
            return null;
        }
        return DateTimeImmutable::createFromFormat(
            'c',
            gmdate('c', filemtime($file)),
            new DateTimeZone('UTC')
        );
    }

    public function display(
        string $file,
        bool $sendHeaderContentLength = false
    ) : int|false {
        $size = $this->size($file);
        if ($size === false) {
            return false;
        }

        $file = $this->uploader->getDataDirectory() . '/'. $file;
        $resource = fopen($file, 'rb');
        $level = ob_get_level();
        if ($level > 0) {
            ob_end_flush();
        }
        if ($sendHeaderContentLength && !headers_sent()) {
            header('Content-Length: %d', $size);
        }
        while (!feof($resource)) {
            echo fread($resource, 8192);
        }
        fclose($resource);

        // re-start buffer
        if ($level > ob_get_level()) {
            ob_start();
        }
        return $size;
    }
}
