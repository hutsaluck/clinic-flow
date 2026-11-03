<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\Column(type: 'guid')] public string $id;
    #[ORM\Column(unique: true)] public string $email;
    #[ORM\Column] public string $password_hash;
    #[ORM\Column(length: 120)] public string $first_name;
    #[ORM\Column(length: 120)] public string $last_name;
    #[ORM\Column(length: 20)] public string $status = 'ACTIVE';
    #[ORM\Column] public bool $platform_admin = false;
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return $this->platform_admin ? ['ROLE_USER', 'ROLE_PLATFORM_ADMIN'] : ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password_hash;
    }

    public function eraseCredentials(): void
    {
    }
}
