<?php

declare(strict_types=1);

namespace AvalynxOfficeEas\Db\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'address')]
#[ORM\HasLifecycleCallbacks]
class Address
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid_v7', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(type: 'string', length: 190)]
    private string $email;
    /**
     * @var Collection<int, EmailAddressLink>
     */
    #[ORM\OneToMany(mappedBy: 'address', targetEntity: EmailAddressLink::class)]
    private Collection $emailAddresses;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $name = null;
    #[ORM\Column(name: 'is_contact', type: 'boolean', options: ['default' => false])]
    private bool $isContact = false;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $firstname = null;
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $lastname = null;
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    private ?string $company = null;
    #[ORM\Column(name: 'business_phone', type: 'string', length: 60, nullable: true)]
    private ?string $businessPhone = null;
    #[ORM\Column(name: 'mobile_phone', type: 'string', length: 60, nullable: true)]
    private ?string $mobilePhone = null;
    #[ORM\Column(name: 'home_phone', type: 'string', length: 60, nullable: true)]
    private ?string $homePhone = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->emailAddresses = new ArrayCollection();
    }

    public function getId(): ?UuidV7
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = strtolower($email);

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function isContact(): bool
    {
        return $this->isContact;
    }

    public function setIsContact(bool $isContact): self
    {
        $this->isContact = $isContact;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): self
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(?string $lastname): self
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getBusinessPhone(): ?string
    {
        return $this->businessPhone;
    }

    public function setBusinessPhone(?string $businessPhone): self
    {
        $this->businessPhone = $businessPhone;

        return $this;
    }

    public function getMobilePhone(): ?string
    {
        return $this->mobilePhone;
    }

    public function setMobilePhone(?string $mobilePhone): self
    {
        $this->mobilePhone = $mobilePhone;

        return $this;
    }

    public function getHomePhone(): ?string
    {
        return $this->homePhone;
    }

    public function setHomePhone(?string $homePhone): self
    {
        $this->homePhone = $homePhone;

        return $this;
    }

    public function displayName(): string
    {
        if (null !== $this->name && '' !== $this->name) {
            return $this->name;
        }

        $names = trim((string) $this->firstname.' '.(string) $this->lastname);

        return '' !== $names ? $names : $this->email;
    }
}
