<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Role;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Cree un compte utilisateur rattache a un role metier')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse e-mail de connexion')
            ->addArgument('password', InputArgument::REQUIRED, 'Mot de passe en clair, il est hache avant enregistrement')
            ->addArgument('role', InputArgument::REQUIRED, 'Nom du role metier, par exemple Administrateur ou Scolarite')
            ->addArgument('first-name', InputArgument::OPTIONAL, 'Prenoms', '')
            ->addArgument('last-name', InputArgument::OPTIONAL, 'Nom', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $roleName = (string) $input->getArgument('role');

        if ($this->userRepository->findOneByEmail($email) !== null) {
            $io->error(sprintf('Un compte existe deja pour "%s".', $email));

            return Command::FAILURE;
        }

        $role = $this->entityManager->getRepository(Role::class)->findOneBy(['name' => $roleName]);
        if (!$role instanceof Role) {
            $available = array_map(
                static fn (Role $existing): string => $existing->getName(),
                $this->entityManager->getRepository(Role::class)->findAll(),
            );
            $io->error(sprintf('Role "%s" introuvable. Roles disponibles : %s.', $roleName, implode(', ', $available)));

            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRole($role);
        $user->setFirstName((string) $input->getArgument('first-name'));
        $user->setLastName((string) $input->getArgument('last-name'));
        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $input->getArgument('password')));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Compte "%s" cree avec le role %s.', $email, $role->getSecurityRole()));

        return Command::SUCCESS;
    }
}
