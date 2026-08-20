<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reprise du schema scolaire legacy vers Symfony et normalisation des inscriptions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE students SET cycle = 'Primaire' WHERE class_name LIKE 'CP%' OR class_name LIKE 'CE%' OR class_name LIKE 'CM%'");
        $this->addSql("UPDATE students SET cycle = 'Secondaire' WHERE cycle IS NULL OR cycle = ''");
        $this->addSql("INSERT OR IGNORE INTO registrations (student_id, site_id, school_year_id, class_id, registration_number, status) SELECT s.id, 1, 1, c.id, 'INS-' || printf('%05d', s.id), 'Validee' FROM students s INNER JOIN classes c ON c.name = s.class_name WHERE NOT EXISTS (SELECT 1 FROM registrations r WHERE r.student_id = s.id AND r.school_year_id = 1)");
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_students_cycle ON students (cycle)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_registrations_year ON registrations (school_year_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_students_cycle');
        $this->addSql('DROP INDEX IF EXISTS idx_registrations_year');
    }
}
