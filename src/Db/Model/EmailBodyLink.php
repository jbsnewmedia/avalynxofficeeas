<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Db\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'email_body_link')]
#[ORM\HasLifecycleCallbacks]
class EmailBodyLink
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid_v7', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\ManyToOne(targetEntity: Email::class, inversedBy: 'emailBodies')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Email $email = null;
    #[ORM\ManyToOne(targetEntity: Body::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Body $body = null;
    #[ORM\Column(name: 'content_type', type: 'string', enumType: BodyContentType::class)]
    private BodyContentType $contentType = BodyContentType::TEXT;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getEmail(): ?Email
    {
        return $this->email;
    }

    public function setEmail(?Email $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getBody(): ?Body
    {
        return $this->body;
    }

    public function setBody(?Body $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getContentType(): BodyContentType
    {
        return $this->contentType;
    }

    public function setContentType(BodyContentType $contentType): self
    {
        $this->contentType = $contentType;

        return $this;
    }
}
