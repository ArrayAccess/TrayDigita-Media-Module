<?php
declare(strict_types=1);

namespace ArrayAccess\TrayDigita\App\Modules\Media;

use ArrayAccess\TrayDigita\App\Modules\Media\Servant\DataServe;
use ArrayAccess\TrayDigita\App\Modules\Media\Traits\MediaFilterTrait;
use ArrayAccess\TrayDigita\App\Modules\Media\Traits\MediaPathTrait;
use ArrayAccess\TrayDigita\App\Modules\Media\Uploader\AdminUpload;
use ArrayAccess\TrayDigita\App\Modules\Media\Uploader\UserUpload;
use ArrayAccess\TrayDigita\Database\Connection;
use ArrayAccess\TrayDigita\L10n\Translations\Adapter\Gettext\PoMoAdapter;
use ArrayAccess\TrayDigita\Module\AbstractModule;
use ArrayAccess\TrayDigita\Traits\Service\TranslatorTrait;
use ArrayAccess\TrayDigita\Uploader\Chunk;
use ArrayAccess\TrayDigita\Uploader\StartProgress;
use ArrayAccess\TrayDigita\Util\Filter\ContainerHelper;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class Media extends AbstractModule
{
    use TranslatorTrait,
        MediaPathTrait,
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
            'module',
            'media-module'
        );
    }

    public function getDescription(): string
    {
        return $this->translateContext(
            'Module to make application support media & file attachments',
            'module',
            'media-module'
        );
    }

    protected function doInit(): void
    {
        if ($this->didInit) {
            return;
        }

        $this->didInit = true;
        foreach ($this->getTranslator()?->getAdapters()??[] as $adapter) {
            if ($adapter instanceof PoMoAdapter) {
                $adapter->registerDirectory(
                    __DIR__ .'/Languages',
                    'media-module'
                );
            }
        }

        $this->doRegisterEntities();
        $this->doFilterPath();
    }

    /**
     * Register entities
     * @return void
     */
    private function doRegisterEntities(): void
    {
        $metadata = ContainerHelper::use(Connection::class, $this->getContainer())
            ?->getDefaultConfiguration()
            ->getMetadataDriverImpl();
        if ($metadata instanceof AttributeDriver) {
            $metadata->addPaths([
                __DIR__ . '/Entities'
            ]);
        }
    }

    public function getRequest(): ?ServerRequestInterface
    {
        return $this->request;
    }

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    /** @noinspection PhpUnused */
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
