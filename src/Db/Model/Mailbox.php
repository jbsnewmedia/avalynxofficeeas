<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Db\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Read-only mapping of the mailbox table (owned by the AvalynxOffice core).
 */
#[ORM\Entity]
#[ORM\Table(name: 'mailbox')]
#[ORM\HasLifecycleCallbacks]
class Mailbox
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid_v7', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(type: 'string', length: 190)]
    private string $name;
    #[ORM\Column(type: 'string', length: 190)]
    private string $email;
    #[ORM\ManyToOne(targetEntity: Folder::class)]
    #[ORM\JoinColumn(name: 'inbox_folder_id', referencedColumnName: 'id', nullable: true)]
    private ?Folder $inboxFolder = null;
    /**
     * @var \Doctrine\Common\Collections\Collection<int, FolderMailbox>&iterable<FolderMailbox>
     */
    #[ORM\OneToMany(mappedBy: 'mailbox', targetEntity: FolderMailbox::class)]
    private iterable $folderLinks;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->folderLinks = new ArrayCollection();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getInboxFolder(): ?Folder
    {
        return $this->inboxFolder;
    }

    public function setInboxFolder(?Folder $folder): self
    {
        $this->inboxFolder = $folder;

        return $this;
    }

    /**
     * @return iterable<FolderMailbox>
     */
    public function getFolderLinks(): iterable
    {
        return $this->folderLinks;
    }
}
