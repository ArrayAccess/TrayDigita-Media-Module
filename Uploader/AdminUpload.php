<?php
declare(strict_types=1);

namespace ArrayAccess\TrayDigita\App\Modules\Media\Uploader;

use ArrayAccess\TrayDigita\App\Modules\Users\Entities\Admin;
use ArrayAccess\TrayDigita\App\Modules\Media\Uploader\Abstracts\AbstractUploader;
use ArrayAccess\TrayDigita\Database\Entities\Abstracts\AbstractUser;
use ArrayAccess\TrayDigita\Exceptions\InvalidArgument\InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

class AdminUpload extends AbstractUploader
{
    public function uploadAttachment(
        ServerRequestInterface $request,
        UploadedFileInterface $uploadedFile,
        ?AbstractUser $user,
        string $type
    ): UploadedFileMetadata {
        if (!$user instanceof Admin) {
            throw new InvalidArgumentException(
                'Argument user is not valid'
            );
        }
        return parent::uploadAttachment($request, $uploadedFile, $user, $type);
    }
}
