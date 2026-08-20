<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'role_permissions')]
class RolePermission {
    #[ORM\Id, ORM\Column(name: 'role_id')]
    private int $roleId;
    #[ORM\Id, ORM\Column(name: 'permission_id')]
    private int $permissionId;
}
