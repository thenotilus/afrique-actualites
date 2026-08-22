<?php

namespace App\User\Command;

use App\User\Entity\User;
use App\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée (ou met à jour) un compte administrateur pouvant accéder au back-office `/admin`
 * (`ROLE_ADMIN`, voir config/packages/security.yaml).
 *
 * Idempotente par email, sur le modèle de {@see \App\Geography\Command\FillCountriesCommand} :
 * rejouable sans dupliquer. Si l'email existe déjà, le mot de passe est réinitialisé et le rôle
 * administrateur garanti. Email et mot de passe peuvent être passés en arguments ou saisis
 * interactivement (le mot de passe est alors masqué).
 */
#[AsCommand(name: 'app:user:create-admin', description: 'Crée ou met à jour un administrateur du back-office')]
final class CreateAdminCommand extends Command
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Adresse email de connexion')
            ->addArgument('password', InputArgument::OPTIONAL, 'Mot de passe en clair (sera haché)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email')
            ?? $io->ask('Adresse email', null, function (?string $value): string {
                $value = trim((string) $value);
                if ('' === $value || false === filter_var($value, \FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException('Merci de saisir une adresse email valide.');
                }

                return $value;
            });
        $email = trim((string) $email);

        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('Adresse email invalide : "%s".', $email));

            return Command::INVALID;
        }

        $password = $input->getArgument('password')
            ?? $io->askHidden('Mot de passe (masqué)', function (?string $value): string {
                if (null === $value || \strlen($value) < self::MIN_PASSWORD_LENGTH) {
                    throw new \RuntimeException(sprintf('Le mot de passe doit faire au moins %d caractères.', self::MIN_PASSWORD_LENGTH));
                }

                return $value;
            });
        $password = (string) $password;

        if (\strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(sprintf('Le mot de passe doit faire au moins %d caractères.', self::MIN_PASSWORD_LENGTH));

            return Command::INVALID;
        }

        $user = $this->userRepository->findOneByEmail($email);
        $isNew = null === $user;
        if (null === $user) {
            $user = new User($email);
        }

        $roles = $user->getRoles();
        if (!in_array(User::ROLE_ADMIN, $roles, true)) {
            $roles[] = User::ROLE_ADMIN;
            $user->setRoles($roles);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            '%s administrateur "%s".',
            $isNew ? 'Création du compte' : 'Mise à jour du compte',
            $email,
        ));

        return Command::SUCCESS;
    }
}
