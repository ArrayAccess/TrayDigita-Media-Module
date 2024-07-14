<?php
declare(strict_types=1);

namespace ArrayAccess\TrayDigita\App\Modules\Media\Entities;

use ArrayAccess\TrayDigita\App\Modules\Users\Entities\Admin;
use ArrayAccess\TrayDigita\App\Modules\Users\Entities\Site;
use ArrayAccess\TrayDigita\Database\Entities\Abstracts\AbstractAttachment;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * @property-read ?Admin $user
 */
#[Entity]
#[Table(
    name: self::TABLE_NAME,
    options: [
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'comment' => 'Attachments created by admin user',
    ]
)]
#[UniqueConstraint(
    name: 'unique_path_storage_type',
    columns: ['path', 'storage_type']
)]
#[Index(
    name: 'index_id_site_id',
    columns: ['id', 'site_id']
)]
#[Index(
    name: 'index_storage_type_mime_type',
    columns: ['storage_type', 'mime_type']
)]
#[Index(
    name: 'relation_attachments_user_id_admins_id',
    columns: ['user_id']
)]
#[Index(
    name: 'relation_attachments_site_id_sites_id',
    columns: ['site_id']
)]
#[Index(
    name: 'index_name_file_name_status_mime_type_storage_type_site_id',
    columns: ['name', 'file_name', 'status', 'mime_type', 'storage_type', 'site_id']
)]
#[HasLifecycleCallbacks]
class Attachment extends AbstractAttachment
{
    public const TABLE_NAME = 'attachments';

    #[Column(
        name: 'site_id',
        type: Types::BIGINT,
        length: 20,
        nullable: true,
        options: [
            'unsigned' => true,
            'default' => null,
            'comment' => 'Site id'
        ]
    )]
    protected ?int $site_id = null;
    #[
        JoinColumn(
            name: 'site_id',
            referencedColumnName: 'id',
            nullable: true,
            onDelete: 'RESTRICT',
            options: [
                'relation_name' => 'relation_attachments_site_id_sites_id',
                'onUpdate' => 'CASCADE',
                'onDelete' => 'RESTRICT'
            ]
        ),
        ManyToOne(
            targetEntity: Site::class,
            cascade: [
                "persist"
            ],
            fetch: 'EAGER'
        )
    ]
    protected ?Site $site = null;

    #[
        JoinColumn(
            name: 'user_id',
            referencedColumnName: 'id',
            nullable: true,
            onDelete: 'RESTRICT',
            options: [
                'relation_name' => 'relation_attachments_user_id_admins_id',
                'onUpdate' => 'CASCADE',
                'onDelete' => 'RESTRICT'
            ],
        ),
        ManyToOne(
            targetEntity: Admin::class,
            cascade: [
                'persist'
            ],
            fetch: 'LAZY',
        )
    ]
    protected ?Admin $user = null;

    public function setUser(?Admin $user): void
    {
        $this->user = $user;
        $this->setUserId($user?->getId());
    }

    public function getUser(): ?Admin
    {
        return $this->user;
    }

    public function getSiteId(): ?int
    {
        return $this->site_id;
    }

    public function setSiteId(?int $site_id): void
    {
        $this->site_id = $site_id;
    }

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(?Site $site): void
    {
        $this->site = $site;
        $this->setSiteId($site?->getId());
    }
}
