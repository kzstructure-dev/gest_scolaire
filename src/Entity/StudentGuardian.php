<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'student_guardians')]
class StudentGuardian {
    #[ORM\Id, ORM\Column(name: 'student_id')]
    private int $studentId;
    #[ORM\Id, ORM\Column(name: 'guardian_id')]
    private int $guardianId;
}
