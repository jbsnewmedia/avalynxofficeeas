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
#[ORM\Table(name: 'email_address_link')]
#[ORM\HasLifecycleCallbacks]
class EmailAddressLink
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid_v7', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\ManyToOne(targetEntity: Email::class, inversedBy: 'emailAddresses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Email $email = null;
    #[ORM\ManyToOne(targetEntity: Address::class, inversedBy: 'emailAddresses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Address $address = null;
    #[ORM\Column(type: 'string', enumType: EmailAddressType::class)]
    private EmailAddressType $type = EmailAddressType::RECIPIENT;

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

    public function getAddress(): ?Address
    {
        return $this->address;
    }

    public function setAddress(?Address $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function getType(): EmailAddressType
    {
        return $this->type;
    }

    public function setType(EmailAddressType $type): self
    {
        $this->type = $type;

        return $this;
    }
}
