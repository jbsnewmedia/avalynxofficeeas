<?php

declare(strict_types=1);

namespace AvalynxOfficeEas\Db\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'folder')]
#[ORM\HasLifecycleCallbacks]
class Folder
{
    use TimestampableTrait;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid_v7', unique: true)]
    private ?UuidV7 $id = null;
    #[ORM\Column(type: 'string', length: 190)]
    private string $name;
    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $functions = [];

    public function __construct()
    {
        $this->id = Uuid::v7();
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

    /**
     * @return list<string>
     */
    public function getFunctions(): array
    {
        return $this->functions;
    }

    /**
     * @param list<string> $functions
     */
    public function setFunctions(array $functions): self
    {
        $this->functions = $functions;

        return $this;
    }

    public function hasFunction(FolderFunction|string $function): bool
    {
        $value = strtolower(trim($function instanceof FolderFunction ? $function->value : $function));

        return in_array($value, $this->functions, true);
    }
}
