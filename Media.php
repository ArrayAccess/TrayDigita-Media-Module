<?php
declare(strict_types=1);

namespace ArrayAccess\TrayDigita\App\Modules\Media;

use ArrayAccess\TrayDigita\App\Modules\Media\Servant\DataServe;
use ArrayAccess\TrayDigita\App\Modules\Media\Traits\MediaFilterTrait;
use ArrayAccess\TrayDigita\App\Modules\Media\Traits\MediaPathTrait;
use ArrayAccess\TrayDigita\App\Modules\Media\Uploader\AdminUpload;
use ArrayAccess\TrayDigita\App\Modules\Media\Uploader\UserUpload;
use ArrayAccess\TrayDigita\Module\AbstractModule;
use ArrayAccess\TrayDigita\Traits\Database\ConnectionTrait;
use ArrayAccess\TrayDigita\Traits\Service\TranslatorTrait;
use ArrayAccess\TrayDigita\Uploader\Chunk;
use ArrayAccess\TrayDigita\Uploader\StartProgress;
use ArrayAccess\TrayDigita\Util\Filter\Consolidation;
use ArrayAccess\TrayDigita\Util\Filter\ContainerHelper;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class Media extends AbstractModule
{
    use TranslatorTrait,
        MediaPathTrait,
        ConnectionTrait,
        MediaFilterTrait;

    protected ?ServerRequestInterface $request = null;

    protected Chunk $chunk;

    protected ?DataServe $dataServe = null;

    protected ?AdminUpload $adminUpload = null;

    protected ?UserUpload $userUpload = null;

    private bool $didInit = false;

    protected string $name = 'Media Manager';

    public function getName(): string
    {
        return $this->translateContext(
            'Media Manager',
            'media-module',
            'module'
        );
    }

    public function getDescription(): string
    {
        return $this->translateContext(
            'Module to make application support media & file attachments',
            'media-module',
            'module'
        );
    }

    protected function doInit(): void
    {
        /** @noinspection DuplicatedCode */
        if ($this->didInit) {
            return;
        }

        Consolidation::registerAutoloader(__NAMESPACE__, __DIR__);
        $this->didInit = true;
        $kernel = $this->getKernel();
        $kernel->registerControllerDirectory(__DIR__ .'/Controllers');
        $this->getTranslator()?->registerDirectory('module', __DIR__ . '/Languages');
        $this->getConnection()->registerEntityDirectory(__DIR__.'/Entities');
        $this->doFilterPath();
    }

    public function getRequest(): ?ServerRequestInterface
    {
        return $this->request;
    }

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function getDataServe(): DataServe
    {
        return $this->dataServe ??= new DataServe($this);
    }

    public function getChunk(): Chunk
    {
        return $this->chunk ??= ContainerHelper::use(Chunk::class, $this->getContainer());
    }

    /**
     * @param UploadedFileInterface $uploadedFile
     * @param ServerRequestInterface $request
     * @return StartProgress
     */
    public function upload(
        UploadedFileInterface $uploadedFile,
        ServerRequestInterface $request
    ): StartProgress {
        return StartProgress::create(
            $this->getChunk(),
            $uploadedFile,
            $request
        );
    }

    public function getAdminUpload(): AdminUpload
    {
        return $this->adminUpload ??= new AdminUpload($this);
    }

    public function getUserUpload(): UserUpload
    {
        return $this->userUpload ??= new UserUpload($this);
    }
}
