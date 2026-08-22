<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:db:init', description: 'Cree la base SQLite, le schema et les donnees de reference')]
final class InitDatabaseCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $root = \dirname(__DIR__, 2);
        $directory = $root . \DIRECTORY_SEPARATOR . 'data';

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $io->error('Impossible de creer le dossier data.');

            return Command::FAILURE;
        }

        require $root . \DIRECTORY_SEPARATOR . 'config.php';

        $io->success(sprintf('Base initialisee : %s', DB_PATH));
        $io->writeln('Creez ensuite un compte : php bin/console app:user:create email mot-de-passe Administrateur Prenoms Nom');

        return Command::SUCCESS;
    }
}
